<?php

namespace Drupal\chat_ai\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Chat AI settings for this site.
 */
class ApiKeysSettingsForm extends ConfigFormBase {

  private const API_ENDPOINT = 'https://api.openai.com/v1';

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'chat_ai_api_keys';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['chat_ai.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['open_ai'] = [
      '#type' => 'details',
      '#title' => $this->t('OpenAI credentials'),
      '#open' => TRUE,
    ];

    $form['open_ai']['api_key'] = [
      '#required' => TRUE,
      '#type' => 'textarea',
      '#title' => $this->t('API Key'),
      '#default_value' => $this->config('chat_ai.settings')->get('api_key'),
      '#description' => $this->t('The API key from <a href="@link" target="_blank">OpenAI</a>.', ['@link' => 'https://openai.com/api']),
    ];

    $form['open_ai']['api_org'] = [
      '#required' => FALSE,
      '#type' => 'textfield',
      '#title' => $this->t('Organization ID'),
      '#default_value' => $this->config('chat_ai.settings')->get('api_org'),
      '#description' => $this->t('The organization name or ID of your OpenAI account.'),
    ];

    $form['open_ai']['api_endpoint'] = [
      '#type' => 'url',
      '#required' => TRUE,
      '#title' => $this->t('Open AI endpoint'),
      '#default_value' => $this->config('chat_ai.settings')->get('api_endpoint') ?? self::API_ENDPOINT,
    ];

    $form['supabase'] = [
      '#type' => 'details',
      '#title' => $this->t('Supabase settings'),
      '#open' => TRUE,
    ];

    $form['supabase']['supabase_url'] = [
      '#type' => 'url',
      '#required' => TRUE,
      '#title' => $this->t('Supabase Rest API URL'),
      '#default_value' => $this->config('chat_ai.settings')->get('supabase_url'),
      '#description' => $this->t("The Rest API URL for your project. Something like <em><code>https://&lt;project_ref&gt;.supabase.co</code></em>"),
    ];

    $form['supabase']['supabase_key'] = [
      '#required' => TRUE,
      '#type' => 'textarea',
      '#title' => $this->t('Supabase Key'),
      '#default_value' => $this->config('chat_ai.settings')->get('supabase_key'),
      '#description' => $this->t('The unique Supabase Key which is supplied when you create a new project in your project dashboard.'),
    ];

    $form['supabase']['info'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<em>Check the <strong><a href="#">instructions</a></strong> on how to setup your Supabase for use with Chat AI.</em>'),
    ];

    $form['pinecone'] = [
      '#type' => 'details',
      '#title' => $this->t('Pinecone settings'),
      '#open' => FALSE,
      '#access' => FALSE,
    ];
    $form['pinecone']['info'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<i>Pinecone support is under development</i>'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    try {
      $client = \OpenAI::client($form_state->getValue('api_key'));
      $client->models()->list();
    }
    catch (\Exception $e) {
      $this->messenger()->addError($e->getMessage());
      $form_state->setErrorByName('api_key');
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('chat_ai.settings')
      ->set('api_key', $form_state->getValue('api_key'))
      ->set('api_org', $form_state->getValue('api_org'))
      ->set('api_endpoint', $form_state->getValue('api_endpoint'))
      ->set('supabase_url', $form_state->getValue('supabase_url'))
      ->set('supabase_key', $form_state->getValue('supabase_key'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
