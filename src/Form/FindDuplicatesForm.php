<?php

namespace Drupal\file_de_duplicator\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

class FindDuplicatesForm extends FormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a new FindDuplicatesForm object.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   */
  public function __construct(Connection $connection) {
    $this->database = $connection;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'find_duplicate_files';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $num_duplicates = $this->database->select('duplicate_files', 'd')
      ->isNull('d.replaced_timestamp')
      ->countQuery()
      ->execute()
      ->fetchField();

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Find duplicates'),
      '#button_type' => 'primary',
    );
    if ($num_duplicates) {
      $form['actions']['replace_all'] = array(
        '#type' => 'submit',
        '#value' => $this->t('Replace All Duplicates'),
        '#name' => 'replace_all',
      );
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // if (strlen($form_state->getValue('candidate_number')) < 10) {
    //   $form_state->setErrorByName('candidate_number', $this->t('Mobile number is too short.'));
    // }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    if ($form_state->getTriggeringElement()['#name'] == 'replace_all') {
      $batch = [
        'title' => t('Replacing Duplicates...'),
        'operations' => [
          [
            '\Drupal\file_de_duplicator\DuplicateFinder::replaceAsBatchProcess',
            []
          ],
        ],
        // 'finished' => '\Drupal\batch_example\DeleteNode::deleteNodeExampleFinishedCallback',
      ];

      batch_set($batch);
    }
    else {
      \Drupal::service('file_de_duplicator.duplicate_finder')->clearFindings();

      $batch = [
        'title' => t('Finding Duplicates...'),
        'operations' => [
          [
            '\Drupal\file_de_duplicator\DuplicateFinder::findAsBatchProcess',
            []
          ],
        ],
        // 'finished' => '\Drupal\batch_example\DeleteNode::deleteNodeExampleFinishedCallback',
      ];

      batch_set($batch);
    }
  }

}