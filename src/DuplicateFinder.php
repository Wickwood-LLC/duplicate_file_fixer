<?php

namespace Drupal\file_de_duplicator;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\file\Entity\File;
use Drupal\views\Views;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Messenger\MessengerInterface;

class DuplicateFinder {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Flag indicating if the Crop Image module is enabled.
   */
  protected $cropImageModuleEnabled;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a new OrderSubscriber object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, Connection $connection, MessengerInterface $messenger) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->database = $connection;
    $this->messenger = $messenger;

    $moduleHandler = \Drupal::service('module_handler');
    $this->cropImageModuleEnabled = $moduleHandler->moduleExists('crop_image');
  }

  public static function findAsBatchProcess(&$context) {

    if (empty($context['sandbox'])) {
      $database = \Drupal::database();

      $context['sandbox']['progress'] = 0;
      $context['sandbox']['current_id'] = 0;
      $context['sandbox']['max'] = $database->select('file_managed', 'f')->countQuery()->execute()->fetchField();
    }

    $limit = 50;

    $last_processed_fid = \Drupal::service('file_de_duplicator.duplicate_finder')->find($context['sandbox']['current_id'], $limit);

    $context['sandbox']['progress'] += $limit;

    if ($last_processed_fid == $context['sandbox']['current_id']) {
      $context['finished'] = 1;
    }
    else {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }

    $context['message'] = t('Processed %num files.', ['%num' => $context['sandbox']['progress']]);
    $context['sandbox']['current_id'] = $last_processed_fid;
  }

  /**
   * Batch process callback for replacing duplicates.
   */
  public static function replaceAsBatchProcess(&$context) {
    $database = \Drupal::database();
    if (empty($context['sandbox'])) {

      $context['sandbox']['progress'] = 0;
      $context['sandbox']['current_id'] = 0;
      $context['sandbox']['max'] = $database->select('duplicate_files', 'd')
        ->fields('d', ['fid'])
        ->isNull('d.replaced_timestamp')
        ->countQuery()->execute()->fetchField();
    }

    $duplicate_record = $database->select('duplicate_files', 'd')
      ->fields('d', ['fid', 'original_fid'])
      ->isNull('d.replaced_timestamp')
      ->range(0, 1)
      ->execute()->fetchObject();

    \Drupal::service('file_de_duplicator.duplicate_finder')->replace($duplicate_record->fid, $duplicate_record->original_fid);

    $context['sandbox']['progress'] += 1;
    $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    $context['message'] = t('Processed %num duplicates.', ['%num' => $context['sandbox']['progress']]);
  }

  public function find($start_fid, $count = 10) {
    $currnet_fid = $start_fid;

    $i = 0;
    while ($i < $count) {
      $query = $this->database->select('file_managed', 'f');
      $query->condition('f.fid', $currnet_fid, '>');
      $query->isNotNull('f.filename');
      $query->fields('f', ['fid', 'filename', 'filemime', 'filesize', 'origname']);
      $query->where('f.fid NOT IN(SELECT fid from {duplicate_files})');
      $query->where('f.fid NOT IN(SELECT original_fid from {duplicate_files})');
      $query->range(0, 1);
      $row = $query->execute()->fetchObject();
      if (!$row) {
        break;
      }
      $i++;
      $currnet_fid = $row->fid;
      $duplicates = $this->findDuplicates(File::load($row->fid));

      $insert_query = $this->database->insert('duplicate_files')->fields(['fid', 'original_fid', 'exact']);
      $insert_values = [];
      foreach ($duplicates['exact'] as $item) {
        $insert_values[] = [
          'fid' => $item->fid,
          'original_fid' => $row->fid,
          'exact' => TRUE,
        ];
      }
      foreach ($duplicates['possible'] as $item) {
        $insert_values[] = [
          'fid' => $item->fid,
          'original_fid' => $row->fid,
          'exact' => FALSE,
        ];
      }
      foreach ($insert_values as $value) {
        $insert_query->values($value);
      }
      $insert_query->execute();
    }
    return $currnet_fid;
  }

  public function findDuplicates($file) {
    $hash_algorithm = 'md5';
    $file_hash = NULL;
    if (is_readable($file->getFileUri())) {
      $file_hash = hash_file($hash_algorithm, $file->getFileUri());
    }
    $duplicates = ['possible' => [], 'exact' => []];

    $query = $this->database->select('file_managed', 'f');
    $query->condition('f.fid', $file->id(), '>');
    $query->condition('f.filesize', $file->getSize());
    $query->condition('f.filemime', $file->getMimeType());
    $query->fields('f', ['fid', 'filename', 'filemime', 'filesize', 'origname']);
    $result = $query->execute();
    foreach ($result as $row) {
      $exact_duplicate = FALSE;
      $duplicate_file = File::load($row->fid);
      if ($this->cropImageModuleEnabled && \Drupal\crop_image\Entity\CropDuplicate::isDuplicate($row->fid)) {
        // CropDuplicates should be exempted.
        continue;
      }
      if ($file_hash && is_readable($duplicate_file->getFileUri())) {
        $duplicate_file_hash = hash_file($hash_algorithm, $duplicate_file->getFileUri());
        if ($file_hash = $duplicate_file_hash) {
          $duplicates['exact'][] = $row;
          $exact_duplicate = TRUE;
        }
      }
      if (!$exact_duplicate) {
        $duplicates['possible'][] = $row;
      }
    }
    return $duplicates;
  }

  public function replace($duplicate_file, $original_file) {
    $file_fields = $this->entityFieldManager->getFieldMapByFieldType('file') + $this->entityFieldManager->getFieldMapByFieldType('image');

    if (!is_object($duplicate_file)) {
      $duplicate_file = File::load($duplicate_file);
    }
    if (!is_object($original_file)) {
      $original_file = File::load($original_file);
    }

    $can_deleted = TRUE;
    $usage = \Drupal::service('file.usage')->listUsage($duplicate_file);
    foreach ($usage as $module => $file_usage) {
      foreach ($file_usage as $entity_type => $usage_data) {
        foreach ($usage_data as $entity_id => $current_usage_count) {
          $usage_count = 0;
          $entity_type_storage = $this->entityTypeManager->getStorage($entity_type);
          if ($entity_type_storage) {
            $entity = $entity_type_storage->load($entity_id);
            if ($entity) {
              $fields_strage_definitions = $this->entityFieldManager->getFieldStorageDefinitions($entity_type);

              $table_mapping = $this->entityTypeManager->getStorage($entity_type)->getTableMapping();

              foreach ($fields_strage_definitions as $field_name => $field_storage_definition) {
                if (!in_array($field_storage_definition->getType(), ['file', 'image'])) {
                  unset($fields_strage_definitions[$field_name]);
                }
              }
              $changed = FALSE;
              $latest_revision_id = NULL;
              $field_table_info = [];
              $entity_data_table = $table_mapping->getDataTable();
              $entity_revision_table = $table_mapping->getRevisionDataTable();
              foreach ($fields_strage_definitions as $field_name => $field_storage_definition) {
                if ($entity->hasField($field_name)) {
                  $values = $entity->get($field_name)->getValue();
                  $field_changed = FALSE;
                  foreach ($values as $index => $value) {
                    if ($value['target_id'] == $duplicate_file->id()) {
                      $values[$index]['target_id'] = $original_file->id();
                      $changed = TRUE;
                      $field_changed = TRUE;
                      $usage_count++;
                    }
                  }
                  if ($field_changed) {
                    $entity->get($field_name)->setValue($values);
                  }
                  $table_name = $table_mapping->getFieldTableName($field_name);
                  $main_property_name = $field_storage_definition->getMainPropertyName();

                  if ($table_name == $entity_data_table) {
                    $revision_table = $entity_revision_table;
                    $main_property_column = $field_name . '__' . $main_property_name;
                  }
                  else {
                    $revision_table = $table_mapping->getDedicatedRevisionTableName($field_storage_definition);
                    $main_property_column = $field_name . '_' . $main_property_name;
                  }
                  $field_table_info[$field_name] = ['table' => $table_name, 'column' => $main_property_column, 'revision_table' => $revision_table];
                }
              }
              if ($changed) {
                $entity->save();
              }
              if ($entity_type_storage instanceof RevisionableStorageInterface) {
                $latest_revision_id = $entity_type_storage->getLatestRevisionId($entity->id());
                $revision_key = $entity->getEntityType()->getKey('revision');
                $id_key = $entity->getEntityType()->getKey('revision');
                foreach ($field_table_info as $field_name => $table_info) {
                  if ($table_info['revision_table'] == $entity_revision_table) {
                    // $revision_column = $revision_key;
                    $num_updated = $this->database->update($table_info['revision_table'])
                      ->condition($id_key, $entity->id())
                      ->condition($revision_key, $latest_revision_id, '!=')
                      ->condition($table_info['column'], $duplicate_file->id())
                      ->fields(array($table_info['column'] => $original_file->id()))
                      ->execute();
                  }
                  else {
                    // $revision_column = 'revision_id';
                    $num_updated = $this->database->update($table_info['revision_table'])
                      ->condition('entity_id', $entity->id())
                      ->condition('revision_id', $latest_revision_id, '!=')
                      ->condition($table_info['column'], $duplicate_file->id())
                      ->fields(array($table_info['column'] => $original_file->id()))
                      ->execute();
                  }

                  $usage_count += $num_updated;
                  // \Drupal::service('file.usage')->add($file, 'editor', $entity->getEntityTypeId(), $entity->id());
                }
              }
            }
            // TODO: Need to get the table name dynamically.
            $query = $this->database->update('file_usage')
              ->condition('module', $module)
              ->condition('fid', $duplicate_file->id())
              ->condition('type', $entity_type)
              ->condition('id', $entity_id);
            // Incorrect usage info makes it impossible to deduct calculated usage count.
            // $query->expression('count', 'count - :count', [':count' => $usage_count]);
            $query->fields(['count' => 0]);
            $query->execute();
          }
          else {
            $can_deleted = FALSE;
            $this->messenger->addStatus($this->t(
              'File usage of file @fid by module @module for type @type with id @id could not be resolved.',
              ['@fid' => $duplicate_file->id(), '@module' => $module, '@type' => $entity_type, '@id' => $entity_id]
            ));
          }
        }
      }
    }

    if ($can_deleted) {
      $duplicate_file->delete();
    }

    $this->database->update('duplicate_files')
      ->condition('fid', $duplicate_file->id())
      ->condition('original_fid', $original_file->id())
      ->isNull('replaced_timestamp')
      ->fields(array('replaced_timestamp' => time()))
      ->execute();
  }

  public function clearFindings() {
    \Drupal::database()->truncate('duplicate_files')->execute();
  }
}