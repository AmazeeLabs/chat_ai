<?php

namespace Drupal\chat_ai\Form;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\file\Entity\File;

/**
 * Provides a confirmation form before clearing out the examples.
 */
class RemoveFileEmbeddingConfirmForm extends ConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'chat_ai_remove_file_embedding_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to remove this file from the chatbot knowledge?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('chat_ai.file_embeddings');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, File $file = NULL) {
    $form = parent::buildForm($form, $form_state);
    $form['description']['info'] = [
      '#markup' => $this->t('<p>This action instructs the chatbot to remove the file <p><em><strong>@name</strong></em></p> from its knowledge base, ensuring that the information contained within is no longer accessible or referenced in future interactions.</p>', [
        '@name' => $file->getFilename()
      ]),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $route_match = \Drupal::routeMatch();
    $file = $route_match->getParameter('file');

    // @todo Add DI
    if ($file instanceof ContentEntityInterface) {
      \Drupal::service('chat_ai.supabase')->clearEntityIndexedData($file);
      $file->delete();
    }

    $this->messenger()->addStatus($this->t('File removed successfully from the chatbot knowledge base.'));
    $form_state->setRedirectUrl(new Url('chat_ai.file_embeddings'));
  }
}
