<?php

namespace Drupal\chat_ai\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\Component\Serialization\Json;
use Drupal\chat_ai\Embeddings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Chat AI embeddings settings for this site.
 */
class EmbeddingsForm extends ConfigFormBase {

  private const CONTENT_ENTITY = 'content';
  private const CONFIGURATION_ENTITY = 'configuration';
  private const MEDIA_PROVIDER = 'media';
  private const USER_PROVIDER = 'user';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'chat_ai_embeddings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['chat_ai.settings'];
  }

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity type bundle info.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * The entity type repository.
   *
   * @var \Drupal\Core\Entity\EntityTypeRepositoryInterface
   */
  protected $entityTypeRepository;

  /**
   * The entity display repository.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  protected $entityDisplayRepository;

  /**
   * The embedding service.
   *
   * @var \Drupal\chat_ai\Embeddings
   */
  protected $embeddings;

  /**
   * Constructs new FieldBlockDeriver.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info.
   * @param \Drupal\Core\Entity\EntityTypeRepositoryInterface $entity_type_repository
   *   The entity type repository.
   * @param \Drupal\Core\Entity\EntityDisplayRepositoryInterface $entity_display_repository
   *   The entity display repository.
   * @param \Drupal\chat_ai\Embeddings $embeddings
   *   The embeddings service.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    EntityTypeRepositoryInterface $entity_type_repository,
    EntityDisplayRepositoryInterface $entity_display_repository,
    Embeddings $embeddings,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityTypeRepository = $entity_type_repository;
    $this->entityDisplayRepository = $entity_display_repository;
    $this->embeddings = $embeddings;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.repository'),
      $container->get('entity_display.repository'),
      $container->get('chat_ai.embeddings')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $config = json_decode($this->config('chat_ai.settings')->get('include') ?? '', TRUE);
    $form["indexing_status"] = [
      '#title' => $this->t('Indexing status'),
      '#type' => 'details',
      '#open' => TRUE,
    ];

    // @todo Check if there are items for indexing
    $form["indexing_status"]['progress'] = [
      '#type' => 'markup',
      '#markup' => Markup::create($this->getProgressMarkup()),
    ];

    $total = $this->embeddings->getTotal();
    $indexed = $this->embeddings->getTotalIndexed();
    $queued = $this->embeddings->getTotalQueued();
    $remaining = $total - $indexed - $queued;

    $form["indexing_status"]['actions']['#type'] = 'actions';
    $form["indexing_status"]['actions']['queue_add'] = [
      '#type' => 'submit',
      '#value' => $this->t('Queue @remaining items for indexing', [
        '@remaining' => $remaining,
      ]),
      '#name' => 'reindex',
      '#button_type' => 'danger',
      '#disabled' => !$remaining,
    ];

    $form["indexing_status"]['actions']['clear'] = [
      '#type' => 'link',
      '#title' => $this->t('Clear all indexed data'),
      '#url' => Url::fromRoute('chat_ai.clear_index_confirm'),
      '#attributes' => [
        'class' => ['use-ajax', 'button', 'button--danger'],
        'data-dialog-type' => 'modal',
        'data-dialog-options' => Json::encode([
          'width' => 800,
        ]),
      ],
    ];

    $form["indexing_status"]['actions']['rebuild_tracker'] = [
      '#type' => 'submit',
      '#value' => $this->t('Rebuild tracking information'),
      '#name' => 'rebuild_tracker',
      '#button_type' => 'danger',
    ];

    $form["indexing_status"]['queued'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<em>@queued items to be indexed in the next cron run.</em>', [
        '@queued' => $queued,
      ]),
    ];

    $entity_definitions = $this->entityTypeManager->getDefinitions();
    $entity_types_list = [];
    foreach ($entity_definitions as $entity_name => $entity_definition) {

      if ($entity_definition->getProvider() == self::USER_PROVIDER) {
        continue;
      }

      if ($entity_definition->getProvider() == self::MEDIA_PROVIDER) {
        continue;
      }

      if ($entity_definition->getGroup() == self::CONFIGURATION_ENTITY) {
        continue;
      }

      if ($entity_definition->getGroup() == self::CONTENT_ENTITY) {
        $entity_types_list[$entity_name] = (string) $entity_definition->getLabel();
      }
    }
    $types = array_keys($entity_types_list);

    $form["bundles_include"] = [
      '#title' => $this->t('Select what to index'),
      '#type' => 'details',
      '#open' => FALSE,
    ];
    $form["bundles_include"]['tip'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<em>For each content type bundle select the view mode that you want to index.</em>'),
    ];

    foreach ($types as $type) {
      $bundles = $this->entityTypeBundleInfo->getBundleInfo($type);
      foreach ($bundles as $bundle => $value) {
        $modes = $this->entityDisplayRepository->getViewModeOptionsByBundle($type, $bundle);
        if (!empty($modes)) {
          $form["bundles_include"]["{$bundle}_container"] = [
            '#title' => strtoupper($value['label']),
            '#type' => 'details',
            '#open' => TRUE,
          ];
          // @todo There must be a better way here
          $form["bundles_include"]["{$bundle}_container"]["{$type}__{$bundle}_include"] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Index this bundle'),
            '#default_value' => $config["{$type}__{$bundle}_include"] ?? NULL,
          ];
          $form["bundles_include"]["{$bundle}_container"]["{$type}__{$bundle}_view_mode"] = [
            '#type' => 'select',
            '#options' => $modes,
            '#default_value' => $config["{$type}__{$bundle}_view_mode"] ?? 0,
          ];
        }
      }
    }

    $form["indexing_options"] = [
      '#title' => $this->t('Indexing options'),
      '#type' => 'details',
      '#open' => FALSE,
      '#access' => FALSE,
    ];

    $form['#attached']['library'][] = 'chat_ai/chat_ai';
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $triggering_element = $form_state->getTriggeringElement();
    // @todo create batch process
    if ($triggering_element['#id'] === 'edit-queue-add') {
      // @todo Extract this to method
      $entities = $this->embeddings->getEntitiesToIndex();
      $operations = [];
      foreach ($entities as $entity) {
        $operations[] = ['Drupal\chat_ai\Form\EmbeddingsForm::addItemToQueue', [$entity]];
        // @todo insert to queue
        $batch = [
          'title' => $this->t('Processing items ...'),
          'operations' => $operations,
          'finished' => 'Drupal\chat_ai\Form\EmbeddingsForm::addItemToQueueFinished',
        ];
      }
      batch_set($batch);
    }

    if ($triggering_element['#id'] === 'edit-rebuild-tracker') {
      // @todo Extract this to method
      $types = $this->getTypesBundlesToIndex();
      $operations = [];
      foreach ($types as $type => $bundle) {
        $storage = $this->entityTypeManager->getStorage($type);
        // @todo Not all entities may have statuses
        $items = $storage->loadByProperties([
          'type' => $bundle,
          'langcode' => 'de',
        ]);

        // Temp.
        $items = array_slice($items, 0, 10);

        foreach ($items as $entity) {
          // @todo Revise this, custom entities may have a custom logic
          if (
            method_exists($entity, 'hasField')
            && $entity->hasField('status')
            && (bool) $entity->status->value
          ) {
            /** @var use \Drupal\Core\Entity\ContentEntityInterface $entity */
            $operations[] = ['Drupal\chat_ai\Form\EmbeddingsForm::rebuildTrackingItem', [$entity]];
          }
        }
      }

      $batch = [
        'title' => $this->t('Processing items ...'),
        'operations' => $operations,
        'finished' => 'Drupal\chat_ai\Form\EmbeddingsForm::rebuildTrackingFinished',
      ];

      batch_set($batch);
      // Clear bundles that are not in the selection.
      $bundles = $this->getTypesBundlesToClear();
      foreach ($bundles as $type => $bundle) {
        $this->embeddings->clearIndexedDataByBundle($type, $bundle);
      }
      $this->messenger()->addMessage($this->t("All items that are not indexed will be processed for indexing in the next cron job."));
    }

    if ($triggering_element['#id'] === 'edit-submit') {
      $values = $form_state->getValues();
      $config = [];
      foreach ($values as $key => $value) {
        if (str_ends_with($key, '_include') || str_ends_with($key, '_view_mode')) {
          $config[$key] = $value;
        }
      }
      $config = json_encode($config);
      $this->config('chat_ai.settings')
        ->set('include', $config)
        ->save();
      $this->messenger()->addWarning($this->t("Please do not forget to rebuild tracking information , if you made updates on items to be indexed."));
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Returns the progress markup for the embeddings indexing process.
   *
   * @return \Drupal\Core\Render\Markup The progress markup.
   */
  private function getProgressMarkup() {
    $total = $this->embeddings->getTotal();
    $indexed = $this->embeddings->getTotalIndexed();
    $queued = $this->embeddings->getTotalQueued();
    $remaining = $total - $indexed - $queued;

    $types_to_index = $this->getTypesBundlesToIndex();
    foreach ($types_to_index as $type => $bundle) {
      $storage = $this->entityTypeManager->getStorage($type);
      $items = $storage->loadByProperties([
        'type' => $bundle,
      ]);
    }
    // ...
    if (empty($types_to_index)) {
      $this->messenger()->addWarning($this->t('Please select at least one content type to index.'));
    }

    $output[] = $this->t('<label for="chat-ai--progress">@indexed/@total</>', [
      '@indexed' => $indexed,
      '@total' => $total,
    ]);
    $output[] = $this->t('<div id="chat-ai--progress" class="chat-ai--progress"><progress value="@indexed" max="@total" ></progress></div>', [
      '@indexed' => $indexed,
      '@total' => $total,
    ]);
    $output[] = $this->t('<p>There are @remaining items for indexing.</p>', [
      '@remaining' => $remaining,
    ]);
    return Markup::create(implode('', $output));
  }

  /**
   * Returns an array of content types and their bundles to be indexed based on the 'include' configuration.
   *
   * @return array An array of content types and their bundles to be indexed.
   */
  private function getTypesBundlesToIndex() {
    $config = json_decode($this->config('chat_ai.settings')->get('include') ?? '', TRUE);
    $bundles_to_index = [];
    foreach ($config as $type => $value) {
      if (str_ends_with($type, '_include') && $value !== 0) {
        $item = str_replace('_include', '', $type);
        $parts = explode('__', $item);
        $bundles_to_index[$parts[0]] = $parts[1];
      }
    }
    return $bundles_to_index;
  }

  /**
   *
   */
  private function getTypesBundlesToClear() {
    $config = json_decode($this->config('chat_ai.settings')->get('include') ?? '', TRUE);
    $bundles_to_clear = [];
    foreach ($config as $type => $value) {
      if (str_ends_with($type, '_include') && $value === 0) {
        $item = str_replace('_include', '', $type);
        $parts = explode('__', $item);
        $bundles_to_clear[$parts[0]] = $parts[1];
      }
    }
    return $bundles_to_clear;
  }

  /**
   * Adds the given entity to the embeddings indexing queue.
   *
   * @param mixed $entity
   *   The entity to add to the queue.
   * @param array $context
   *   The batch context array.
   */
  public static function addItemToQueue($entity, &$context) {
    $message = 'Adding item to indexing queue ...' . $entity->label();
    $embeddings = \Drupal::service('chat_ai.embeddings');
    $embeddings->insertToQueue($entity);
    $context['message'] = $message;
    $context['results'][] = $entity->id();
  }

  /**
   * Batch finished callback function for adding items to the embeddings indexing queue.
   *
   * @param bool $success
   *   Whether the batch process was successful.
   * @param array $results
   *   An array of results from the batch operations.
   * @param array $operations
   *   An array of batch operations.
   */
  public static function addItemToQueueFinished($success, $results, $operations) {
    if ($success) {
      $message = \Drupal::translation()->formatPlural(
        count($results),
        'One entity processed.',
        '@count entities added to the indexing queue.'
      );
    } else {
      $message = t('Finished with an error.');
    }
    \Drupal::messenger()->addStatus($message);
  }

  /**
   * Rebuilds the tracking information for the given entity.
   *
   * @param mixed $entity
   *   The entity to rebuild the tracking information for.
   * @param array $context
   *   The batch context array.
   */
  public static function rebuildTrackingItem($entity, &$context) {
    $message = 'Re-building tracking information for ...' . $entity->label();
    $results[] = \Drupal::service('chat_ai.embeddings')->insertDatabaseEmbedding($entity);
    $context['message'] = $message;
    $context['results'][] = $entity->id();
  }

  /**
   * Batch finished callback function for rebuilding tracking information for entities.
   *
   * @param bool $success
   *   Whether the batch process was successful.
   * @param array $results
   *   An array of results from the batch operations.
   * @param array $operations
   *   An array of batch operations.
   */
  public static function rebuildTrackingFinished($success, $results, $operations) {
    if ($success) {
      $message = \Drupal::translation()->formatPlural(
        count($results),
        'One entity processed.',
        '@count entities processed.'
      );
    } else {
      $message = t('Finished with an error.');
    }
    \Drupal::messenger()->addStatus($message);
  }
}
