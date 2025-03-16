<?php

namespace Drupal\chat_ai;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\chat_ai\Http\OpenAiClientFactory;
use Drupal\chat_ai\Http\SupabaseClientFactory;
use Drupal\Core\Entity\EntityInterface;

/**
 * Service description.
 */
class Supabase {

  private const MATCH_COUNT = 5;
  private const MATCH_THRESHOLD = 0.5;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The open_ai.client service.
   *
   * @var \OpenAI\Client
   */
  protected $client;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $account;

  /**
   * The supabase client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $supabase;

  /**
   * Constructs a Supabase object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger
   *   The logger channel factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\chat_ai\Http\OpenAiClientFactory $open_ai_factory
   *   The open_ai.client service.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param \Drupal\chat_ai\Http\SupabaseClientFactory $supabase_factory
   *   The current user.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger,
    ConfigFactoryInterface $config_factory,
    OpenAiClientFactory $open_ai_factory,
    RouteMatchInterface $route_match,
    AccountInterface $account,
    SupabaseClientFactory $supabase_factory,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger;
    $this->configFactory = $config_factory;
    $this->client = $open_ai_factory->create();
    $this->routeMatch = $route_match;
    $this->account = $account;
    $this->supabase = $supabase_factory->create();
  }

  /**
   * Check Supabase if everything is in place.
   *
   * @return bool|int
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function checkSetup() {
    try {
      $response = $this->supabase->request('GET', 'documents', [
        'query' => [
          'id' => 'eq.' . 1,
        ],
      ]);
      $status = $response->getStatusCode();
      return $status == 200 ? TRUE : $status;
    } catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Checks if the Supabase configuration exists.
   *
   * @return bool
   *   Returns TRUE if the Supabase configuration exists, FALSE otherwise.
   */
  public function configExist() {
    return !empty($this->configFactory->get('chat_ai.settings')->get('supabase_key')) &&
      !empty($this->configFactory->get('chat_ai.settings')->get('supabase_url'));
  }

  /**
   * Inserts or updates a record in the Supabase database with the given entity, chunk, and embedding.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to insert or update the record for.
   * @param string $chunk
   *   The chunk of text to insert or update the record for.
   * @param string $embedding
   *   The embedding to insert or update the record for.
   *
   * @return string The response body from the Supabase API.
   */
  public function upsert(EntityInterface $entity, string $chunk, string $embedding) {
    // @DCG place your code here.
    $response = $this->supabase->post('documents', [
      'headers' => [
        'Prefer' => 'resolution=merge-duplicates',
      ],
      'json' => [
        'content' => $chunk,
        'embedding' => $embedding,
        'entity_id' => $entity->id(),
        'entity_type' => $entity->getEntityTypeId(),
        'bundle' => $entity->bundle(),
        'langcode' => $entity->language()->getId(),
      ],
    ]);
    return $response->getBody()->getContents();
  }

  /**
   * Clears the indexed data for a given entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity for which the indexed data needs to be cleared.
   *
   * @return string
   *   The response body of the deletion request.
   */
  public function clearEntityIndexedData(EntityInterface $entity) {
    $response = $this->supabase->delete('documents', [
      'query' => [
        'entity_id' => 'eq.' . $entity->id(),
        'entity_type' => 'eq.' . $entity->getEntityTypeId(),
        'bundle' => 'eq.' . $entity->bundle(),
        // 'langcode' => $entity->language()->getId()
      ],
    ]);
    return $response->getBody()->getContents();
  }

  /**
   * Deletes all documents associated with entities of the specified type and bundle.
   *
   * @param string $type
   *   The entity type to use to determine which documents to delete.
   * @param string $bundle
   *   The bundle to use to determine which documents to delete.
   *
   * @return string
   *   The response body from the Supabase API.
   */
  public function clearIndexedDataByBundle(string $type, string $bundle) {
    $response = $this->supabase->delete('documents', [
      'query' => [
        'entity_type' => 'eq.' . $type,
        'bundle' => 'eq.' . $bundle,
      ],
    ]);
    return $response->getBody()->getContents();
  }

  /**
   * Clears all indexed data from the 'documents' table in Supabase.
   *  File data are excluced.
   *
   * @return string
   *   The response body contents.
   */
  public function clearIndexedData() {
    // @todo Exclude files
    $response = $this->supabase->delete('documents', [
      'query' => [
        'id' => 'gt.0',
        'entity_type' => 'neq.file'
      ],
    ]);
    return $response->getBody()->getContents();
  }

  /**
   * Returns an array of chunks that match the given query, based on their embeddings.
   *
   * @param string $query
   *   The query to match against.
   * @param float $match_threshold
   *   The minimum cosine similarity threshold for a match.
   * @param int $match_count
   *   The maximum number of matches to return.
   *
   * @return array An array of chunks that match the given query.
   */
  public function getMatchingChunks(
    string $query,
    float $match_threshold = self::MATCH_THRESHOLD,
    int $match_count = self::MATCH_COUNT,
  ) {

    $query = mb_convert_encoding($query, 'UTF-8');

    $response = $this->client->embeddings()->create([
      // @todo replace this with config value
      'model' => 'text-embedding-3-small',
      'input' => $query,
    ]);

    // We expect only one vector.
    foreach ($response->embeddings as $embedding) {
      $vector = json_encode($embedding->embedding);
    }

    if (empty($vector)) {
      return [];
    }

    // @todo Revise this, we don't need to return the vector
    $response = $this->supabase->post('rpc/match_documents', [
      'headers' => [
        'Prefer' => 'resolution=merge-duplicates',
      ],
      'json' => [
        'match_count' => $match_count,
        'match_threshold' => $match_threshold,
        'query_embedding' => $vector,
      ],
    ]);

    $chunks = json_decode($response->getBody()->getContents());
    $context = [];
    foreach ($chunks as $chunk) {
      $context[] = $chunk->content;
    }
    return $context;
  }

  /**
   * Retrieves chunks that match multiple queries derived from a single input query.
   *
   * This function takes a query string, splits it into multiple sub-queries using
   * the chat_ai service, and finds matching chunks for each sub-query.
   *
   * @param string $query The input query to be processed.
   * @param float $match_threshold The minimum threshold for considering a match.
   *                              Defaults to self::MATCH_THRESHOLD.
   * @param int $match_count The maximum number of matches to return.
   *                        Defaults to self::MATCH_COUNT.
   *
   * @return array An array of unique matching chunks across all sub-queries.
   *
   * @todo Add dependency injection for the chat_ai service.
   */
  public function getMultiQueryMatchingChunks(
    string $query,
    float $match_threshold = self::MATCH_THRESHOLD,
    int $match_count = self::MATCH_COUNT,
  ) {
    // @todo Add DI
    $chat = \Drupal::service('chat_ai.service');
    $question = mb_convert_encoding($query, 'UTF-8');
    $response = $chat->getMultiQuery($question);
    $text = $response[0] ?: NULL;
    if ($text) {
      $array = explode("\n", $text);
      $array = array_map('trim', $array);
      $array = array_filter($array);
      foreach ($array as $item) {
        $chunks[] = $this->getMatchingChunks($item);
      }
    }
    $flattened = array_merge(...array_values($chunks));
    return array_unique($flattened);
  }
}
