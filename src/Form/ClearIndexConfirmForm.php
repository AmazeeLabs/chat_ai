<?php

namespace Drupal\chat_ai\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides a confirmation form before clearing out the examples.
 */
class ClearIndexConfirmForm extends ConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'chat_ai_clear_index_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to clean all indexed data?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('chat_ai.embeddings');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $form['description']['info'] = [
      '#markup' => $this->t('<p>Clearing all indexed data, will delete data on the local embeddings database table and the remote vector database as well.</p>'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // @todo Add DI
    $supabase = \Drupal::service('chat_ai.supabase');
    $embeddings = \Drupal::service('chat_ai.embeddings');

    // @todo Add error handling
    $supabase->clearIndexedData();
    $embeddings->clearIndexedData();

    $queue = \Drupal::service('queue')->get('embeddings_queue');
    $queue->deleteQueue();

    $this->messenger()->addStatus($this->t('Indexed cleared successfully'));
    $form_state->setRedirectUrl(new Url('chat_ai.embeddings'));
  }

}
