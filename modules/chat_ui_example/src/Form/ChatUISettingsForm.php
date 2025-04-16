<?php

namespace Drupal\chat_ui_example\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;

/**
 * Configure Chat UI settings for this site.
 */
class ChatUISettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'chat_ui_example_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['chat_ui_example.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['chat_ui'] = [
      '#type' => 'details',
      '#title' => $this->t('Visibility (backend)'),
      '#open' => TRUE,
    ];

    $form['chat_ui']['backend_visibility'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show chat UI interface on the backend'),
      '#default_value' => $this->config('chat_ui_example.settings')->get('backend_visibility'),
    ];

    $form['chat_ui']['path_pages_exclude'] = [
      '#type' => 'textarea',
      '#title' => '<span class="element-invisible">' . $this->t('Exclude Chat UI from the following pages:') . '</span>',
      '#default_value' => $this->config('chat_ui_example.settings')->get('path_pages_exclude'),
      '#description' => $this->t("Specify pages by using their paths. Enter one path per line. The '*' character is a wildcard. Example paths are %blog for the blog page and %blog-wildcard for every personal blog. %front is the front page.", [
        '%blog' => '/blog',
        '%blog-wildcard' => '/blog/*',
        '%front' => '<front>',
      ]),
    ];

    $form['chat_ui_origin'] = [
      '#type' => 'details',
      '#title' => $this->t('Origin'),
      '#open' => TRUE,
    ];

    $form['appearance']['theme_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Theme Mode'),
      '#options' => [
        'light' => $this->t('Light Mode'),
        'dark' => $this->t('Dark Mode'),
        'auto' => $this->t('Auto (Follow System)'),
      ],
      '#default_value' => $this->config('chat_ui_example.settings')->get('theme_mode') ?: 'light',
      '#description' => $this->t('Select the theme mode for the chat interface.'),
    ];

    // Add appearance settings fieldset
    $form['embed'] = [
      '#type' => 'details',
      '#title' => $this->t('Code to embed'),
      '#open' => TRUE,
    ];

    $base_url = \Drupal::request()->getSchemeAndHttpHost();
    $code = <<<EOD
      // Put this on the header of your website.
      <script src="{$base_url}/chat-ui.js" type="text/javascript" />
      EOD;

    $form['embed']['code'] = [
      '#type' => 'textarea',
      '#default_value' => Markup::create($code),
      '#disabled' => TRUE,
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
    $this->config('chat_ui_example.settings')
      ->set('path_pages_exclude', $form_state->getValue('path_pages_exclude'))
      ->set('backend_visibility', $form_state->getValue('backend_visibility'))
      ->set('theme_mode', $form_state->getValue('theme_mode'))
      ->save();
    parent::submitForm($form, $form_state);
  }
}
