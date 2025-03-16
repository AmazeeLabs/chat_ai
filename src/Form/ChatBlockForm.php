<?php

namespace Drupal\chat_ai\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\AppendCommand;
use Drupal\Core\Ajax\FocusFirstCommand;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\chat_ai\Embeddings;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a Chat AI form.
 */
class ChatBlockForm extends FormBase {

  private static $messages = [];

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'chat_ai_block';
  }

  /**
   * The embedding service.
   *
   * @var \Drupal\chat_ai\Embeddings
   */
  protected $embeddings;

  /**
   * Constructs new FieldBlockDeriver.
   *
   * @param \Drupal\chat_ai\Embeddings $embeddings
   *   The embeddings service.
   */
  public function __construct(
    Embeddings $embeddings,
  ) {
    $this->embeddings = $embeddings;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('chat_ai.embeddings')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form['chat_container'] = [
      '#type' => 'container',
      '#markup' => '',
      '#attributes' => [
        'class' => 'chat--area-container edit-chat-container',
      ],
    ];

    $form['chat_footer'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => 'chat--footter js-form-wrapper form-wrapper',
      ],
    ];

    $form['chat_footer']['inline'] = [
      '#type' => 'container',
      '#attributes' => [
        'style' => 'display: flex; align-items: center; gap: 4px; margin: 0px',
      ],
    ];

    $form['chat_footer']['inline']['message'] = [
      '#type' => 'textfield',
      '#placeholder' => $this->t('Message'),
      '#required' => TRUE,
      '#attributes' => [
        'style' => ['flex-grow: 1; margin-left: 10px; width: 98%;'],
      ],
    ];

    $form['chat_footer']['inline']['send'] = [
      '#type' => 'button',
      '#value' => $this->t('Send'),
      '#attributes' => [
        'class' => ['chat-ai--send-button button js-form-submit form-submit'],
      ],
      '#ajax' => [
        'callback' => '::chatCallback',
        // Or TRUE to prevent re-focusing on the triggering element.
        'disable-refocus' => FALSE,
        'event' => 'click',
        'wrapper' => '.edit-chat-container',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('...'),
        ],
      ],
    ];


    $form['#attached']['library'][] = 'chat_ai/chat_ai';
    $form['#attached']['library'][] = 'core/drupal.ajax';
    return $form;
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
    $this->messenger()->addStatus($this->t('The message has been sent.'));
    $form_state->setRedirect('<front>');
  }

  /**
   * Ajax callback function for the chat form.
   *
   * @param array $form
   *   The chat form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   The Ajax response object.
   */
  public function chatCallback(array &$form, FormStateInterface $form_state) {

    $message = "<p>{$form_state->getValue('message')}</p>";
    // @todo Add DI
    $chat = \Drupal::service('chat_ai.service');
    $history = $chat->chatHistoryRetrieve(strip_tags(trim($message)));
    $use_history = FALSE;

    if (is_array($history) && !empty($history) && $use_history) {
      $choices = reset($history);
    } else {
      $context = \Drupal::service('chat_ai.supabase')->getMultiQueryMatchingChunks($message);
      $context = implode('\n', $context);
      // $choices = \Drupal::service('chat_ai.service')->completion($message, $context);
      $choices = \Drupal::service('chat_ai.service')->chat($message, $context);
      // @todo Split in separate answers
      $choices = implode('<br />', $choices);
      $choices = "<p class='chat-gpt'>{$choices}</p>";
      $chat->chatHistoryInsert(strip_tags(trim($message)), $choices);
    }

    $response = new AjaxResponse();
    $response->addCommand(new AppendCommand('#edit-chat-container', $message));
    $response->addCommand(new AppendCommand('#edit-chat-container', $choices));
    $response->addCommand(new InvokeCommand('#edit-message', 'val', ['']));
    $response->addCommand(new FocusFirstCommand('#edit-message'));
    return $response;
  }
}
