<?php

namespace Drupal\chat_ai\Form;

use Drupal\node\Entity\Node;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\chat_ai\Http\OpenAiClientFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Chat AI embeddings settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  private const DEFAULT_MODEL = 'gpt-4o-mini';
  private const OPEN_AI = 'openai';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'chat_ai_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['chat_ai.settings'];
  }

  /**
   * The open_ai.client service.
   *
   * @var \OpenAI\Client
   */
  protected $client;

  /**
   * Constructs new FieldBlockDeriver.
   *
   * @param \Drupal\chat_ai\Http\OpenAiClientFactory $open_ai_factory
   *   The open_ai.client_factory service.
   */
  public function __construct(
    OpenAiClientFactory $open_ai_factory,
  ) {
    $this->client = $open_ai_factory->create();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('open_ai.client_factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form["container"] = [
      '#title' => $this->t('Chat settings'),
      '#type' => 'details',
      '#open' => TRUE,
    ];

    $form['container']['model'] = [
      '#type' => 'select',
      '#title' => $this->t('Select chat model:'),
      '#options' => $this->getGptModels(),
      '#default_value' => $this->getDefaultModel(),
      '#required' => TRUE,
      '#disabled' => $this->getAiContributedDefaultModel(),
    ];

    if ($this->getAiContributedDefaultModel()) {
      $form['container']['default_model_info'] = [
        '#type' => 'markup',
        '#markup' => $this->t('The chat model from <strong><a href="@url">AI settings</a></strong> is used.', [
          '@url' => '/admin/config/ai/settings',
        ]),
        '#suffix' => '<p></p>',
      ];
    }

    $form['container']['info'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Information about the models can be found on <a href="@url" target="_blank">OpenAI website</a>', [
        '@url' => 'https://platform.openai.com/docs/models',
      ]),
    ];

    $form['container']['default_response'] = [
      '#required' => FALSE,
      '#type' => 'text_format',
      '#title' => $this->t('Default response'),
      '#default_value' => $this->config('chat_ai.settings')->get('default_response') ?: '',
      '#description' => $this->t('This is the default response when the chatbot is unable to find relevant information related to the query.'),
      '#format' => 'basic_html',
      '#allowed_formats' => ['basic_html'],
    ];

    $form["advanced"] = [
      '#title' => $this->t('Advanced'),
      '#type' => 'details',
      '#open' => FALSE,
    ];
    $form['advanced']['special_prompt_instructions'] = [
      '#required' => FALSE,
      '#type' => 'textarea',
      '#title' => $this->t('Special prompt instructions'),
      '#default_value' => $this->config('chat_ai.settings')->get('special_prompt_instructions') ?: '',
      '#description' => $this->t('Special prompt instructions define custom guidelines or behaviors for the chatbot, influencing how it responds to user queries. These instructions help tailor the chatbot’s tone, style, and approach to better align with specific use cases or preferences.'),
    ];

    // Add appearance settings fieldset
    $form['chat_ui_origin'] = [
      '#type' => 'details',
      '#title' => $this->t('Allowed origins'),
      '#open' => TRUE,
    ];

    $form['chat_ui_origin']['allowed_origins'] = [
      '#type' => 'textarea',
      '#title' => '<span class="element-invisible">' . $this->t('Allowed origin domains:') . '</span>',
      '#default_value' => $this->config('chat_ai.settings')->get('allowed_origins'),
      '#description' => $this->t("Configure the allowed origin domains that this service will accept cross-origin requests from."),
    ];

    $form['chat_ui_origin']['bypass_origin_checks'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Bypass origin checks'),
      '#default_value' => $this->config('chat_ai.settings')->get('bypass_origin_checks'),
    ];

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

    $default_response = $form_state->getValue('default_response');

    $this->config('chat_ai.settings')
      ->set('model', $form_state->getValue('model'))
      ->set('default_response', $default_response['value'])
      ->set('allowed_origins', $form_state->getValue('allowed_origins'))
      ->set('bypass_origin_checks', $form_state->getValue('bypass_origin_checks'))
      ->set('special_prompt_instructions', $form_state->getValue('special_prompt_instructions'))
      ->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Retrieves a list of GPT models available from the OpenAI API.
   *
   * @return array
   *   An array of GPT model IDs, keyed by the ID.
   */
  private function getGptModels() {

    try {
      $models = $this->client->models()->list();
    } catch (\Exception $e) {
      // @todo
      $this->messenger()->addError($this->t('Please configure your Open AI API key.'));
      return [];
    }

    $options = [];
    foreach ($models['data'] as $model) {
      $id = $model['id'];
      if (str_starts_with($id, 'gpt-')) {
        $options[$id] = $id;
      }
    }

    // Drop out some models.
    $options = array_filter($options, function ($item) {
      return !str_contains($item, 'audio')
        && !str_contains($item, 'preview')
        && !str_contains($item, '3.5');
    });

    return $options;
  }

  /**
   * Gets the default model for chat AI.
   *
   * If a default chat provider is configured with OpenAI as the provider,
   * uses that model ID otheriwse use Chat AI settings.
   * Otherwise, falls back to the class's DEFAULT_MODEL constant.
   *
   * @return string
   *   The default model ID to use for chat AI.
   */
  private function getDefaultModel() {
    $default_model = $this->config('chat_ai.settings')->get('model') ?: self::DEFAULT_MODEL;
    return $this->getAiContributedDefaultModel() ?? $default_model;
  }


  /**
   * Gets the default model for chat AI.
   *
   * Checks the AI module's default providers configuration.
   * Returns the model ID if a default chat provider is configured with OpenAI.
   * Otherwise, returns NULL.
   *
   * @return string|null
   *   The default AI model ID for chat AI or NULL.
   */
  private function getAiContributedDefaultModel(): ?string {
      $default_providers = $this->config('ai.settings')->get('default_providers') ?? [];
      $chat_provider = $default_providers['chat'] ?? [];

      return ($chat_provider && $chat_provider['provider_id'] === self::OPEN_AI)
          ? $chat_provider['model_id']
          : NULL;
  }

}
