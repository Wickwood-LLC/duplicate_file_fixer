<?php

namespace Drupal\duplicate_file_fixer;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\file\Entity\File;
use Drupal\views\Views;
use Drupal\Component\Utility\Html;

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
   * Constructs a new OrderSubscriber object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, Connection $connection) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->database = $connection;

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

    $last_processed_fid = \Drupal::service('duplicate_file_fixer.duplicate_finder')->find($context['sandbox']['current_id'], $limit);

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
          $duplicates['exact'][$file_hash] = $row;
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


    $args = [$duplicate_file->id()];
    $view = Views::getView('files');
    if (is_object($view)) {
      $view->setArguments($args);
      $view->setDisplay('page_2');
      $view->preExecute();
      $view->execute();
      foreach ($view->result as $result) {
        $entity_type_storage = $this->entityTypeManager->getStorage($result->file_usage_type);
        if ($entity_type_storage) {
          $entity = $entity_type_storage->load($result->file_usage_id);
          if ($entity) {
            $fields_strage_definitions = $this->entityFieldManager->getFieldStorageDefinitions($result->file_usage_type);
            foreach ($fields_strage_definitions as $field_name => $field_storage_definition) {
              if (!in_array($field_storage_definition->getType(), ['file', 'image'])) {
                unset($fields_strage_definitions[$field_name]);
              }
            }
            $changed = FALSE;
            foreach ($fields_strage_definitions as $field_name => $field_storage_definition) {
              if ($entity->hasField($field_name)) {
                $values = $entity->get($field_name)->getValue();
                $field_changed = FALSE;
                foreach ($values as $index => $value) {
                  if ($value['target_id'] == $duplicate_file->id()) {
                    $values[$index]['target_id'] = $original_file->id();
                    $changed = TRUE;
                    $field_changed = TRUE;
                  }
                }
                if ($field_changed) {
                  $entity->get($field_name)->setValue($values);
                }
              }
            }
            if ($changed) {
              $entity->save();
            }
          }
        }
      }
    }

    $duplicate_file->delete();

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