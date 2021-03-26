<?php

namespace Drupal\file_de_duplicator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Defines a generic controller to list entities.
 */
class DuplicatesController extends ControllerBase {
  use StringTranslationTrait;

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
   * Allows to run to find duplicates and lists duplicates found.
   */
  public function listing() {

    $build['form'] = \Drupal::formBuilder()->getForm(\Drupal\file_de_duplicator\Form\FindDuplicatesForm::class);

    $header = [
      [
        'data' => $this->t('Duplicate File'),
        'field' => 'd.fid',
        'sort' => 'asc',
        'class' => [RESPONSIVE_PRIORITY_MEDIUM],
      ],
      [
        'data' => $this->t('Original File'),
        'field' => 'd.original_fid',
        'class' => [RESPONSIVE_PRIORITY_LOW],
      ],
      $this->t('Type'),
      [
        'data' => $this->t('Replaced'),
        'field' => 'd.replaced_timestamp',
        'class' => [RESPONSIVE_PRIORITY_MEDIUM],
      ],
      $this->t('Usage'),
      $this->t('Operations'),
    ];

    $query = $this->database->select('duplicate_files', 'd')
      ->extend('\Drupal\Core\Database\Query\PagerSelectExtender')
      ->extend('\Drupal\Core\Database\Query\TableSortExtender');
    $query->fields('d', [
      'fid',
      'original_fid',
      'exact',
      'replaced_timestamp',
    ]);

 
    $result = $query
      ->limit(50)
      ->orderByHeader($header)
      ->execute();

    $rows = [];

    foreach ($result as $row) {
      if (empty($row->exact)) {
        $type = $this->t('Possible');
      }
      else {
        $type = $this->t('Exact');
      }

      if (!empty($row->replaced_timestamp)) {
        $replaced = $this->t('Yes (%time)', ['%time' => \Drupal::service('date.formatter')->format($row->replaced_timestamp)]);
      }
      else {
        $replaced = $this->t('No');
      }

      $duplicate_file = File::load($row->fid);
      $original_file = File::load($row->original_fid);

      if ($duplicate_file) {
        $duplicate_file_data =  [
          '#type' => 'link',
          '#title' => $this->t('@name (@fid)', ['@name' => $duplicate_file->getFilename(), '@fid' => $duplicate_file->id()]),
          '#url' => Url::fromUri($duplicate_file->createFileUrl(FALSE)),
        ];
        $replace_data =  [
          '#type' => 'link',
          '#title' => $this->t('Usage of @name (fid: @fid)', ['@name' => $duplicate_file->getFilename(), '@fid' => $duplicate_file->id()]),
          '#url' => Url::fromUri('internal:/admin/content/files/usage/' . $duplicate_file->id()),
        ];
      }
      else {
        $duplicate_file_data =  $this->t('@fid (Not existing)', ['@fid' => $row->fid]);
        $replace_data = '';
      }

      if ($original_file) {
        $original_file_data = [
          '#type' => 'link',
          '#title' => $this->t('@name (@fid)', ['@name' => $original_file->getFilename(), '@fid' => $original_file->id()]),
          '#url' => Url::fromUri($original_file->createFileUrl(FALSE)),
        ];
      }
      else {
        $original_file_data = $this->t('@fid (Not existing)', ['@fid' => $row->original_fid]);
      }
      $operations = [];
      if ($duplicate_file && $original_file) {
        $operations[] = [
          'title' => $this->t('Replace'),
          'url' => Url::fromRoute('file_de_duplicator.replace_file', ['duplicate_file' => $duplicate_file->id(), 'original_file' => $original_file->id()], ['query' => \Drupal::destination()->getAsArray()]),
        ];
      }
      $rows[] = [
        // Cells.
        [
          'data' => $duplicate_file_data,
        ],
        [
          'data' => $original_file_data,
        ],
        $type,
        $replaced,
        [
          'data' => $replace_data,
        ],
        [
          'data' => [
            '#type' => 'operations',
            '#links' => $operations,
          ],
        ]
      ];
    }

    $num_duplicates_requiring_replacement = $this->database->select('duplicate_files', 'd')
      ->isNull('d.replaced_timestamp')
      ->countQuery()
      ->execute()
      ->fetchField();

    $num_duplicates = $this->database->select('duplicate_files', 'd')
      ->countQuery()
      ->execute()
      ->fetchField();

    $build['duplicates'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#caption' => $this->t('There are @num duplicates found and @replace pending for replacement.', ['@num' => $num_duplicates, '@replace' => $num_duplicates_requiring_replacement]),
      '#attributes' => [],
      '#empty' => $this->t('No information available.'),
    ];
    $build['duplicates_pager'] = ['#type' => 'pager'];

    return $build;
  }

  public function replaceFile($duplicate_file, $original_file) {
    $destination_service = \Drupal::service('redirect.destination');

    \Drupal::service('file_de_duplicator.duplicate_finder')->replace($duplicate_file, $original_file);
    
    $response = new RedirectResponse('/admin/content/files-fix-duplicates');
    return $response;
  }

}
