<?php

namespace Drupal\chat_ai;

use LLPhant\Embeddings\DocumentSplitter\DocumentSplitter;
use LLPhant\Embeddings\DataReader\FileDataReader;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\chat_ai\Http\OpenAiClientFactory;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\file\Entity\File;

/**
 * The embeddings service.
 */
class Embeddings {

  private const EMBEDDING_MODEL = 'text-embedding-3-small';
  private const INDEXED = TRUE;
  private const NOT_INDEXED = FALSE;

  private const IN_QUEUE = TRUE;
  private const NOT_IN_QUEUE = FALSE;

  /**
   * The default view mode to render .
   */
  private const DEFAULT = 'default';

  /**
   * The open_ai.client service.
   *
   * @var \OpenAI\Client
   */
  protected $client;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * The logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * Active database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Supabase service.
   *
   * @var \Drupal\chat_ai\Supabase
   */
  protected $supabase;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a ChatAIService object.
   *
   * @param \Drupal\chat_ai\Http\OpenAiClientFactory $open_ai_factory
   *   The open_ai.client_factory service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   The logger channel factory.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\chat_ai\Supabase $supabase,
   *   The supabase service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(
    OpenAiClientFactory $open_ai_factory,
    EntityTypeManagerInterface $entity_type_manager,
    AccountInterface $account,
    LoggerChannelFactoryInterface $logger,
    Connection $database,
    Supabase $supabase,
    ConfigFactoryInterface $config_factory,
  ) {
    $this->client = $open_ai_factory->create();
    $this->entityTypeManager = $entity_type_manager;
    $this->account = $account;
    $this->logger = $logger;
    $this->database = $database;
    $this->supabase = $supabase;
    $this->configFactory = $config_factory;
  }

  /**
   * Returns the rendered content of the given entity in the specified view mode.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to render.
   * @param string $view_mode
   *   (optional) The view mode to render the entity in. Defaults to 'default'.
   *
   * @return string The rendered content of the entity.
   */
  public function getEntityContent(ContentEntityInterface $entity, string $view_mode = 'default') {
    $langcode = $entity->language()->getId();
    $render_controller = $this->entityTypeManager->getViewBuilder($entity->getEntityTypeId());
    $render_output = $render_controller->view($entity, $view_mode, $langcode);
    $output = \Drupal::service('renderer')->renderPlain($render_output);
    $output = strip_tags($output);
    $output = preg_replace("/ {2,}/", " ", $output);
    $output = preg_replace("/(\r?\n){2,}/", "", $output);
    $output = preg_replace("/(\n){1,}/", "", $output);
    return $output;
  }

  /**
   * Creates chunks of text from the rendered content of the given entity in the specified view mode.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to create chunks of text from.
   * @param string $view_mode
   *   (optional) The view mode to render the entity in. Defaults to 'default'.
   * @param int $min_length
   *   (optional) The minimum length of each chunk. Defaults to 600.
   * @param string $needle
   *   (optional) The delimiter to use for splitting the text into chunks. Defaults to '.'.
   *
   * @return array An array of chunks of text.
   */
  public function createChunks(ContentEntityInterface $entity, string $view_mode = 'default', int $min_length = 600, string $needle = '.') {
    $text = $this->getEntityContent($entity, $view_mode);
    // @todo Get chunk size from config (small, medium, large)
    $delimiter = preg_quote($needle);
    $match = preg_match_all("/.*?$delimiter/", $text, $matches);
    if ($match == 0) {
      return [$text];
    }
    $sentences = current($matches);
    $chunks = [];
    $tmp = '';
    foreach ($sentences as $sentence) {
      $tmp .= $sentence;
      if (strlen($tmp) > $min_length) {
        $chunks[] = $tmp;
        $tmp = '';
      }
    }
    if ($tmp != '') {
      $chunks[] = $tmp;
    }
    return $chunks;
  }

  /**
   * Returns an array of entities that need to be indexed.
   *
   * @return array An array of entities that need to be indexed.
   */
  public function getEntitiesToIndex() {
    $query = $this->database->select('chat_ai_embeddings', 'c');
    $query->addField('c', 'entity_id');
    $query->addField('c', 'entity_type');
    $query->addField('c', 'bundle');
    $query->addField('c', 'langcode');
    $query->condition('c.indexed', 0);
    $query->condition('c.in_queue', 0);
    $results = $query->execute()->fetchAll();
    $entities = [];
    foreach ($results as $result) {
      $entities[] = $this->getEntity($result->entity_id, $result->entity_type, $result->bundle);
    }
    return $entities;
  }

  /**
   * Returns the entity with the given ID, entity type, and bundle.
   *
   * @param int $id
   *   The ID of the entity to retrieve.
   * @param string $type
   *   The entity type of the entity to retrieve.
   * @param string $bundle
   *   The bundle of the entity to retrieve.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface|null
   *   The entity with the given ID, entity type, and bundle, or NULL if not found.
   *
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  private function getEntity(int $id, string $type, string $bundle) {
    // Revise this.
    $storage = $this->entityTypeManager->getStorage($type);
    $entity_keys = $this->entityTypeManager->getDefinition($type)->getKeys();
    $entity_id_field = $entity_keys['id'];
    if ($entity_id_field) {
      $items = $storage->loadByProperties([
        $entity_id_field => $id,
        'type' => $bundle,
      ]);
      return reset($items);
    }
    return NULL;
  }

  /**
   * Returns the total number of entities currently in the embeddings indexing queue.
   *
   * @return int
   *   The total number of entities currently in the embeddings indexing queue.
   */
  public function getTotalQueued(): int {
    $query = $this->database->select('chat_ai_embeddings', 'c');
    $query->addField('c', 'id');
    $query->condition('c.in_queue', intval(self::IN_QUEUE));
    return $query->countQuery()->execute()->fetchField();
  }

  /**
   * Returns the total number of entities that have been indexed.
   *
   * @return int
   *   The total number of entities that have been indexed.
   */
  public function getTotalIndexed(): int {
    $query = $this->database->select('chat_ai_embeddings', 'c');
    $query->addField('c', 'id');
    $query->condition('c.indexed', intval(self::INDEXED));
    return $query->countQuery()->execute()->fetchField();
  }

  /**
   * Returns the total number of entities in the embeddings table.
   *
   * @return int
   *   The total number of entities in the embeddings table.
   */
  public function getTotal(): int {
    $query = $this->database->select('chat_ai_embeddings', 'c');
    $query->addField('c', 'id');
    return $query->countQuery()->execute()->fetchField();
  }

  /**
   * Returns whether the given entity is currently in the embeddings indexing queue.
   *
   * @param mixed $entity
   *   The entity to check.
   *
   * @return bool
   *   TRUE if the entity is in the embeddings indexing queue, FALSE otherwise.
   */
  public function isQueued($entity) {
    $query = $this->database->select('chat_ai_embeddings', 'c');
    $query->addField('c', 'in_queue');
    $query->condition('c.entity_id', $entity->id());
    $query->condition('c.entity_type', $entity->getEntityTypeId());
    $query->condition('c.bundle', $entity->bundle());
    $query->condition('c.langcode', $entity->language()->getId());
    return (bool) $query->execute()->fetchField();
  }

  /**
   * Returns whether the given entity is currently indexed.
   *
   * @param mixed $entity
   *   The entity to check.
   *
   * @return bool
   *   TRUE if the entity is indexed, FALSE otherwise.
   */
  public function isIndexed($entity) {
    $query = $this->database->select('chat_ai_embeddings', 'c');
    $query->addField('c', 'indexed');
    $query->condition('c.entity_id', $entity->id());
    $query->condition('c.entity_type', $entity->getEntityTypeId());
    $query->condition('c.bundle', $entity->bundle());
    $query->condition('c.langcode', $entity->language()->getId());
    return (bool) $query->execute()->fetchField();
  }

  /**
   * Inserts the given entity into the embeddings indexing queue.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to insert into the queue.
   */
  public function insertToQueue(ContentEntityInterface $entity) {
    // @todo Check if item is already in queue
    if (!$this->isQueued($entity)) {
      $queue = \Drupal::service('queue')->get('embeddings_queue');

      $languages = $entity->getTranslationLanguages();
      foreach ($languages as $langcode => $language) {
        $translation = $entity->getTranslation($langcode);
        // @todo Extract the creation of the item in method
        $item = new \stdClass();
        $item->entity = $translation;
        $item->item_id = $entity->id();
        $item->view_mode = $this->getViewModeToIndex($entity);
        // @todo Maybe we don't need this.
        $item->langcode = $langcode;

        $queue->createItem($item);
        $this->setQueued($translation);
        $this->setIndexed($translation, FALSE);
      }
      // @todo If empty($languages)
    }
  }

  /**
   * Sets the 'in_queue' flag for the given entity in the embeddings table.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to set the flag for.
   *
   * @return int The number of rows updated in the embeddings table.
   */
  public function setQueued(ContentEntityInterface $entity, bool $value = self::IN_QUEUE) {
    // @todo
    $result = $this->database->update('chat_ai_embeddings')
      ->fields([
        'in_queue'  => intval($value),
      ])
      ->condition('entity_id', $entity->id())
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('bundle', $entity->bundle())
      ->condition('langcode', $entity->language()->getId())
      ->execute();
    return $result;
  }

  /**
   * Updates the token usage statistics for the given entity in the embeddings table.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to update the token usage statistics for.
   * @param int $prompt
   *   The number of prompt tokens used.
   * @param int $total
   *   The total number of tokens used.
   *
   * @return int The number of rows updated in the embeddings table.
   */
  public function updateTokensUse(ContentEntityInterface $entity, int $prompt, int $total) {
    // @todo
    $result = $this->database->update('chat_ai_embeddings')
      ->expression('prompt_tokens', 'prompt_tokens + :amount', [':amount' => $prompt])
      ->expression('total_tokens', 'total_tokens + :amount', [':amount' => $total])
      ->condition('entity_id', $entity->id())
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('bundle', $entity->bundle())
      ->condition('langcode', $entity->language()->getId())
      ->execute();
    return $result;
  }

  /**
   * Updates the metadata for the given entity in the embeddings table.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to update the metadata for.
   * @param array $metadata
   *   An array of metadata to update.
   *
   * @return int The number of rows updated in the embeddings table.
   */
  public function updateMetadata(ContentEntityInterface $entity, array $metadata = []) {
    // @todo
    $result = $this->database->update('chat_ai_embeddings')
      ->fields([
        'metadata'  => json_encode($metadata),
      ])
      ->condition('entity_id', $entity->id())
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('bundle', $entity->bundle())
      ->condition('langcode', $entity->language()->getId())
      ->execute();
    return $result;
  }

  /**
   * Inserts a new record into the embeddings table with the given entity ID,
   *  entity type, bundle, and language code. ATTENTION: Not exactly an MySQL upsert.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *
   * @return int
   *   The number of rows inserted into the embeddings table.
   *
   * @throws \Exception
   */
  public function insertDatabaseEmbedding(ContentEntityInterface $entity) {
    // @todo Transform this to upsert or merge query.
    if (!$this->selectDatabaseEmbedding($entity)) {
      $result = $this->database->insert('chat_ai_embeddings')
        ->fields([
          'created'  => \Drupal::time()->getRequestTime(),
          'entity_id'  => $entity->id(),
          'entity_type'  => $entity->getEntityTypeId(),
          'bundle'  => $entity->bundle(),
          'langcode'  => $entity->language()->getId(),
        ])
        ->execute();
      return $result;
    }
    return TRUE;
  }

  /**
   * Returns whether the given entity.
   *
   * @param mixed $entity
   *   The entity to check.
   *
   * @return bool TRUE if the entity is in the embeddings, FALSE otherwise.
   */
  public function selectDatabaseEmbedding($entity) {
    $query = $this->database->select('chat_ai_embeddings', 'c');
    $query->addField('c', 'id');
    $query->condition('c.entity_id', $entity->id());
    $query->condition('c.entity_type', $entity->getEntityTypeId());
    $query->condition('c.bundle', $entity->bundle());
    $query->condition('c.langcode', $entity->language()->getId());
    return (int) (int) $query->execute()->fetchField();
  }

  /**
   * Sets the 'indexed' flag for the given entity in the embeddings table.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to set the flag for.
   *
   * @return int The number of rows updated in the embeddings table.
   */
  public function setIndexed(ContentEntityInterface $entity, bool $value = self::INDEXED) {
    // @todo
    $result = $this->database->update('chat_ai_embeddings')
      ->fields([
        'indexed'  => intval($value),
      ])
      ->condition('entity_id', $entity->id())
      ->condition('entity_type', $entity->getEntityTypeId())
      ->condition('bundle', $entity->bundle())
      ->condition('langcode', $entity->language()->getId())
      ->execute();
    return $result;
  }

  /**
   * Deletes all data from the embeddings table.
   *
   * @return int The number of rows deleted from the embeddings table.
   */
  public function clearIndexedData() {
    // @todo Extract this to method
    $this->emptyQueue();
    $result = $this->database->delete('chat_ai_embeddings')
      ->execute();
    return $result;
  }

  /**
   * Deletes all indexed data associated with entities of the same bundle as the given entity.
   *
   * @param string $type
   *   The entity type to use to determine which documents to delete.
   * @param string $bundle
   *   The bundle to use to determine which documents to delete.
   *
   * @return int
   *   The number of rows deleted from the database.
   */
  public function clearIndexedDataByBundle(string $type, string $bundle) {
    $this->supabase->clearIndexedDataByBundle($type, $bundle);
    return $this->database->delete('chat_ai_embeddings')
      ->condition('entity_type', $type)
      ->condition('bundle', $bundle)
      ->execute();
  }

  /**
   * Remove an embedding for the given entity.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to remove from indexing.
   */
  public function clearIndexedDataByEntity(ContentEntityInterface $entity) {
    $this->supabase->clearEntityIndexedData($entity);
    $this->deleteQueueItem($entity);
    return $this->database->delete('chat_ai_embeddings')
      ->condition('entity_id', $entity->id())
      ->execute();
  }

  /**
   * Get the Open AI embedding response.
   *
   * @param string $chunk
   *
   * @return \OpenAI\Responses\Embeddings\CreateResponse
   */
  public function getOpenAiEmbedding(string $chunk) {
    $response = $this->client->embeddings()->create([
      'model'  => self::EMBEDDING_MODEL,
      'input'  => $chunk,
    ]);
    // @todo
    return $response;
  }

  /**
   * Creates an embedding for the given entity and view mode.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to create an embedding for.
   * @param string $langcode|null
   *   The langcode to use.
   */
  public function createEmbedding(ContentEntityInterface $entity, $langcode = NULL) {

    // First delete the related vectors from Supabase.
    $this->supabase->clearEntityIndexedData($entity);
    $chunks = $this->documentSplitter($entity);

    foreach ($chunks as $chunk) {
      // @todo Aargh..this has also to be batched
      $this->createSingleChunkEmbedding($entity, $chunk);
    }

    $this->setIndexed($entity);
    $this->setQueued($entity, FALSE);
    $this->logger->get('chat_ai')->info("{$entity->label()} indexed successfully in both local db and supabase.");
  }

  /**
   * Creates single chunk embedding.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to create an embedding for.
   * @param mixed $chunk
   *   The chunk, document spliiter outcome.
   */
  public function createSingleChunkEmbedding(ContentEntityInterface $entity, $chunk) {

    $content = mb_convert_encoding($chunk->content, 'UTF-8');
    $response = $this->getOpenAiEmbedding($content);
    $this->updateTokensUse($entity, $response->usage->promptTokens, $response->usage->totalTokens);
    foreach ($response->embeddings as $embedding) {
      $vectors[] = json_encode($embedding->embedding);
    }
    foreach ($vectors as $vector) {
      $this->supabase->upsert($entity, $content, $vector);
    }
  }

  /**
   * Returns the view mode to use when indexing entities of the given type and bundle.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to get the view mode for.
   *
   * @return string
   *   The view mode to use when indexing entities of the given type and bundle.
   */
  public function getViewModeToIndex(ContentEntityInterface $entity) {
    $type = $entity->getEntityTypeId();
    $bundle = $entity->bundle();
    if ($this->configFactory->get('chat_ai.settings')->get('include')) {
      $config = json_decode($this->configFactory->get('chat_ai.settings')->get('include') ?? '', TRUE);
      return $config["{$type}__{$bundle}_view_mode"] ?? self::DEFAULT;
    }
    return self::DEFAULT;
  }

  /**
   * Determines whether the given entity should be indexed.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to check.
   *
   * @return bool
   *   TRUE if the entity should be indexed, FALSE otherwise.
   */
  public function shouldIndex(ContentEntityInterface $entity) {
    if ($this->configFactory->get('chat_ai.settings')->get('include')) {
      $config = json_decode($this->configFactory->get('chat_ai.settings')->get('include') ?? '', TRUE);

      if (method_exists($entity, 'isPublished')) {
        $published = $entity->isPublished();
      } else {
        // @todo Revise this, custom entities may have a custom logic
        $published = method_exists($entity, 'hasField')  &&  $entity->hasField('status') && $entity->status->value;
      }

      $bundle = $config["{$entity->getEntityTypeId()}__{$entity->bundle()}_include"] ?? FALSE;
      return $bundle && $published ?? FALSE;
    }
    return FALSE;
  }

  /**
   * Splits documents from a file into smaller chunks.
   *
   * This function reads a file, extracts documents from it, and splits them
   * into smaller chunks with a maximum size of 800 characters, using '.' as
   * a delimiter. It also filters out chunks larger than 1000 characters as a
   * workaround to remove large sections like menus and hidden items.
   *
   * @param File $file The file to process
   *
   * @return array Array of document chunks, each smaller than 1000 characters
   */
  public function documentSplitterFile(File $file) {
    $path = $this->getFileAbsoluteServerPath($file);
    $reader = new FileDataReader($path);
    $documents = $reader->getDocuments();
    $splitted  = DocumentSplitter::splitDocuments($documents, 800, '.', 0);
    // Workaround to drop out big chunks, which usual are menus, hidden items etc.
    $chunks = array_filter($splitted, function ($item) {
      return strlen($item->content) < 1000;
    });
    return $chunks;
  }

  /**
   * Splits content from a Drupal entity into smaller document chunks.
   *
   * This function fetches content from a Drupal file, creates a temporary file,
   * extracts documents from it, and splits them into smaller chunks with a maximum
   * size of 800 characters, using '.' as a delimiter. It also filters out chunks
   * larger than 1000 characters as a workaround to remove large sections like
   * menus and hidden items.
   *
   * @todo Add dependency injection and refactor this initial prototype
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity The Drupal entity to process
   *
   * @return array Array of document chunks, each smaller than 1000 characters,
   *               or empty array if content fetching fails
   */
  public function documentSplitter(ContentEntityInterface $entity) {
    // @todo Add DI, refactor (note: this is an initial working prototype)
    $fetcher = \Drupal::service('chat_ai.content_fetcher');
    $file_path = $fetcher->fetchContentEntityContent($entity);
    // Check if file was created successfully.
    if (!file_exists($file_path)) {
      return [];
    }
    $reader = new FileDataReader($file_path);
    $documents = $reader->getDocuments();
    $splitted  = DocumentSplitter::splitDocuments($documents, 800, '.', 0);

    // Workaround to drop out big chunks, which usual are menus, hidden items etc.
    $chunks = array_filter($splitted, function ($item) {
      return strlen($item->content) < 1000;
    });
    return $chunks;
  }

  /**
   * Gets the absolute server path for a given file entity.
   *
   * @param \Drupal\file\Entity\File $file
   *   The file entity.
   *
   * @return string|null
   *   The absolute server path to the file, or NULL if the path cannot be determined.
   */
  private function getFileAbsoluteServerPath(File $file) {
    $uri = $file->getFileUri();
    $absolute_path = \Drupal::service('file_system')->realpath($uri);
    if ($absolute_path  &&  file_exists($absolute_path)) {
      return $absolute_path;
    }
    return NULL;
  }

  /**
   * Deletes a queue item associated with a specific entity.
   *
   * This function searches through the specified queue for items
   * that contain the given entity, and deletes the first matching item found.
   * The matching is done by comparing entity IDs and language codes.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity to search for in the queue.
   * @param string $queue_name
   *   The name of the queue to search in. Defaults to 'embeddings_queue'.
   *
   * @return void
   */
  public function deleteQueueItem(ContentEntityInterface $entity, string $queue_name = 'embeddings_queue') {
    $queue = \Drupal::queue($queue_name);
    $database = \Drupal::database();
    $result = $database->select('queue', 'q')
      ->fields('q', ['item_id', 'data'])
      ->condition('q.name', $queue_name)
      ->execute()
      ->fetchAll();

    foreach ($result as $item) {
      $data = unserialize($item->data);
      $queue_entity = $data->entity;
      $queue_entity_langcode = $data->entity->language()->getId();
      if (
        $queue_entity->id() == $entity->id()
        && $entity->language()->getId() == $queue_entity_langcode
      ) {
        $queue_item = $queue->claimItem(0, $item->item_id);
        $queue->deleteItem($queue_item);
      }
    }
  }

  /**
   * Empties the specified queue by deleting it.
   *
   * This function completely removes the specified queue and all its items.
   * By default, it targets the 'embeddings_queue' if no queue name is provided.
   *
   * @param string $queue_name
   *   The name of the queue to empty. Defaults to 'embeddings_queue'.
   *
   * @return bool
   *   TRUE if the queue was successfully deleted, FALSE otherwise.
   */
  public function emptyQueue(string $queue_name = 'embeddings_queue') {
    $queue = \Drupal::queue($queue_name);
    return $queue->deleteQueue();
  }
}
