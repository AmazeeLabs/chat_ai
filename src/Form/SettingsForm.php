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
      '#default_value' => $this->config('chat_ai.settings')->get('model') ?: self::DEFAULT_MODEL,
      '#required' => TRUE,
    ];

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
}
