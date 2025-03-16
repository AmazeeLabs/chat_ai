<?php

namespace Drupal\chat_ai\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Defines 'embeddings_queue' queue worker.
 *
 * @QueueWorker(
 *   id = "embeddings_queue",
 *   title = @Translation("Embeddings Queue Worker"),
 *   cron = {"time" = 600}
 * )
 */
class EmbeddingsQueue extends QueueWorkerBase {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $data->entity;
    $embeddings = \Drupal::service('chat_ai.embeddings');
    // $langcode = $data->langcode ?: NULL;
    if (PHP_SAPI === 'cli') {
      print "Indexing entity: {$entity->label()} (language: {$entity->language()->getId()})" . PHP_EOL;
    }

    $embeddings->createEmbedding($entity);

    if (PHP_SAPI === 'cli') {
      print "Indexing finished ✅" . PHP_EOL;
    }

    \Drupal::logger('chat_ai')->info($this->t('@name (@langcode) indexed successfully', [
      '@name' => $entity->label(),
      '@label' => $entity->language()->getId(),
    ]));
  }
}
