<?php

namespace Drupal\duplicate_file_fixer\Form;

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
    // $form['candidate_name'] = array(
    //   '#type' => 'textfield',
    //   '#title' => t('Candidate Name:'),
    //   '#required' => TRUE,
    // );
    // $form['candidate_mail'] = array(
    //   '#type' => 'email',
    //   '#title' => t('Email ID:'),
    //   '#required' => TRUE,
    // );
    // $form['candidate_number'] = array (
    //   '#type' => 'tel',
    //   '#title' => t('Mobile no'),
    // );
    // $form['candidate_dob'] = array (
    //   '#type' => 'date',
    //   '#title' => t('DOB'),
    //   '#required' => TRUE,
    // );
    // $form['candidate_gender'] = array (
    //   '#type' => 'select',
    //   '#title' => ('Gender'),
    //   '#options' => array(
    //     'Female' => t('Female'),
    //     'male' => t('Male'),
    //   ),
    // );
    // $form['candidate_confirmation'] = array (
    //   '#type' => 'radios',
    //   '#title' => ('Are you above 18 years old?'),
    //   '#options' => array(
    //     'Yes' =>t('Yes'),
    //     'No' =>t('No')
    //   ),
    // );
    // $form['candidate_copy'] = array(
    //   '#type' => 'checkbox',
    //   '#title' => t('Send me a copy of the application.'),
    // );

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Rerun to find duplicates'),
      '#button_type' => 'primary',
    );
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
    // // drupal_set_message($this->t('@can_name ,Your application is being submitted!', array('@can_name' => $form_state->getValue('candidate_name'))));
    // foreach ($form_state->getValues() as $key => $value) {
    //   drupal_set_message($key . ': ' . $value);
    // }

    // $nids = \Drupal::entityQuery('node')
    //   ->condition('type', 'article')
    //   ->sort('created', 'ASC')
    //   ->execute();
    
    // $num_files = $database->select('file_managed', 'f')->countQuery()->execute()->fetchField();

    \Drupal::service('duplicate_file_fixer.duplicate_finder')->clearFindings();

    $batch = [
      'title' => t('Finding Duplicates...'),
      'operations' => [
        [
          '\Drupal\duplicate_file_fixer\DuplicateFinder::findAsBatchProcess',
          []
        ],
      ],
      // 'finished' => '\Drupal\batch_example\DeleteNode::deleteNodeExampleFinishedCallback',
    ];

    batch_set($batch);
  }

}