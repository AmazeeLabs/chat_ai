<?php

namespace Drupal\chat_ai;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\chat_ai\Http\OpenAiClientFactory;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Chat AI service.
 */
class ChatAI {

  private const DEFAULT_CHAT_MODEL = 'gpt-4o-mini';

  use StringTranslationTrait;

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
   * Undocumented function.
   *
   * @return array
   */
  public function listModels(): array {
    $response = $this->client->models()->list();
    return $response->toArray()['data'];
  }

  /**
   * Chat with the website visitors and answer their questions under the given context.
   *
   * @param string $question
   *   The question asked by the website visitor.
   * @param string $context
   *   The context of the chat.
   * @param array $history
   *   Contains the history of the conversation in pairs of [system/user] messages.
   *
   * @return array
   *   An array of possible answers to the question.
   */
  public function chat(string $question, string $context, ?string $langcode = NULL, array $history = []): array {
    $question = mb_convert_encoding($question, 'UTF-8');

    $default_response = $this->configFactory->get('chat_ai.settings')->get('default_response') ?: $this->t('I have no idea');

    $special_prompt_instructions = $this->configFactory->get('chat_ai.settings')->get('special_prompt_instructions') ?: '';
    if (!empty($special_prompt_instructions)) {
      $special_prompt_instructions = <<<EOD
      Follow these special instructions in your response:
      {$special_prompt_instructions}
      EOD;
    }

    // @todo
    $language = $langcode ? $this->getLanguageName($langcode) : $this->getLanguageName();

    // @todo Load this from a .yml file.
    $context = <<<EOD
    You are a website chat bot.
    Answer questions only under the given context. If the context is empty respond with "{$default_response}".
    If you don't know the answer just respond with "{$default_response}".

    Context:  """
    $context
    """

    Respond only in {$language} language.

    {$special_prompt_instructions}

    Format your responses using simple HTML (no markdown formatting or code blocks).

    Produce maximum 3 follow up questions (if possible) from a user perspective and put it at the end of the response. Each one should be inside a <span class="follow-up"></span> tag.

    EOD;
    $model = $this->configFactory->get('chat_ai.settings')->get('model') ?: self::DEFAULT_CHAT_MODEL;

    $messages[] = [
      'role' => 'system',
      'content' => $context,
    ];

    if (!empty($history)) {
      foreach ($history as $history_item) {
        $messages[] = [
          'role' => 'user',
          'content' => $history_item['user'],
        ];

        $messages[] = [
          'role' => 'assistant',
          'content' => $history_item['assistant'],
        ];
      }
    }

    $messages[] = [
      'role' => 'user',
      'content' => $question,
    ];

    $response = $this->client->chat()->create([
      'model' => $model,
      'messages' => $messages,
    ]);

    $choices = [];
    foreach ($response->choices as $result) {
      $choices[] = $result->message->content;
    }
    return $choices;
  }

  /**
   * Inserts a chat history record into the 'chat_ai_history' table.
   *
   * @param string $query
   *   The user's query.
   * @param string $response
   *   The chatbot's response.
   *
   * @return int
   *   The number of rows affected by the insert operation.
   *
   * @throws \Exception
   */
  public function chatHistoryInsert(string $query, string $response) {
    $result = $this->database->insert('chat_ai_history')
      ->fields([
        'created' => \Drupal::time()->getRequestTime(),
        'uid' => $this->account->id(),
        'user_query' => $query,
        'chat_response' => $response,
      ])
      ->execute();
    return $result;
  }

  /**
   * Clears all chat history records from the 'chat_ai_history' table.
   *
   * @return int
   *   The number of rows affected by the delete operation.
   */
  public function chatHistoryClear() {
    return $this->database->delete('chat_ai_history')->execute();
  }

  /**
   * Retrieves the chatbot's response from the chat history for the given user query.
   *
   * @param string $prompt
   *   The user's query to retrieve the chatbot's response for.
   *
   * @return array
   *   The chatbot's response for the given user query, or NULL if not found.
   */
  public function chatHistoryRetrieve(string $prompt) {
    $query = $this->database->select('chat_ai_history', 'c');
    $query->addField('c', 'chat_response');
    $query->condition('c.user_query', '%' . trim($prompt) . '%', 'LIKE');
    $result = $query->execute()->fetchCol();
    return $result;
  }

  /**
   * Generates multiple versions of a question using an AI language model.
   *
   * This function takes a user's question and sends it to an AI model to generate
   * three different versions of the same question. This helps overcome limitations
   * of distance-based similarity search when retrieving documents from a vector database.
   *
   * @param string $question
   *   The original question provided by the user.
   *
   * @return array An array of strings containing alternative formulations of the original question,
   *   each generated by the AI model in the current user's language
   *
   * @throws \Exception If the API request to the AI model fails
   */
  public function getMultiQuery(string $question): array {
    $question = mb_convert_encoding($question, 'UTF-8');

    $language = \Drupal::languageManager()->getCurrentLanguage()->getName();
    $chat_request = <<<EOD
    You are an AI language model assistant.
    Your task is to generate 3 different versions of the given user question to retrieve relevant documents from a vector database.
    By generating multiple perspectives on the user question, your goal is to help the user overcome some of the limitations  of distance-based similarity search.
    Provide these alternative questions separated by newlines.
    Original question: {$question}
    Respond only in {$language} language.
    EOD;
    $model = $this->configFactory->get('chat_ai.settings')->get('model') ?: self::DEFAULT_CHAT_MODEL;

    $response = $this->client->chat()->create([
      'model' => $model,
      'messages' => [
        [
          'role' => 'user',
          'content' => $chat_request,
        ],
      ],
    ]);

    $choices[] = $question;
    foreach ($response->choices as $result) {
      $choices[] = $result->message->content;
    }
    return $choices;
  }

  /**
   * Gets the language name based on the language code.
   *
   * @param string|null $langcode
   *   The language code (optional).
   *
   * @return string|null The language name, or null if the language is not found.
   */
  private function getLanguageName(?string $langcode = NULL): ?string {

    $manager = \Drupal::languageManager();

    if (!$langcode) {
      return $manager->getCurrentLanguage()->getName();
    }

    $language = $manager->getLanguage($langcode);

    if ($language instanceof LanguageInterface) {
      return $language->getName();
    }

    return $manager->getCurrentLanguage()->getName();
  }

  /**
   * Transforms a user question to make it more complete and self-contained.
   *
   * This method enhances a question to improve its effectiveness in similarity search
   * by sending it to the configured AI model. The transformation keeps the original
   * meaning but makes the question more comprehensive.
   *
   * @param string $question
   *   The original user question to be transformed.
   * @param string|null $langcode
   *   The language code in which the response should be provided.
   *   If NULL, the default language will be used.
   * @param array $history
   *   An array of conversation history items, each containing 'user' and 'assistant' keys
   *   representing previous exchanges in the conversation.
   *
   * @return mixed
   *   The transformed question string, or NULL if the transformation failed.
   */
  public function transform(string $question, ?string $langcode = NULL, array $history = []): mixed {

    $question = mb_convert_encoding($question, 'UTF-8');
    $language = $langcode ? $this->getLanguageName($langcode) : $this->getLanguageName();
    $context = <<<EOD
    We need to enrich a user question to improve its effectiveness in a similarity search.
    Given the following context, enhance the user's question to make it more complete and self-contained.
    Do not change the meaning of the original question.

    Original Question: $question

    Respond only in {$language}. Ouput only the improved question and nothing else.
    EOD;
    $model = $this->configFactory->get('chat_ai.settings')->get('model') ?: self::DEFAULT_CHAT_MODEL;

    if (!empty($history)) {
      foreach ($history as $history_item) {
        $messages[] = [
          'role' => 'user',
          'content' => $history_item['user'],
        ];

        $messages[] = [
          'role' => 'assistant',
          'content' => $history_item['assistant'],
        ];
      }
    }

    $messages[] = [
      'role' => 'system',
      'content' => $context,
    ];

    $response = $this->client->chat()->create([
      'model' => $model,
      'messages' => $messages,
    ]);

    $choices = [];
    foreach ($response->choices as $result) {
      $choices[] = $result->message->content;
    }

    return $choices[0];
  }

}
