<?php

namespace Drupal\chat_ai\Plugin\Block;

use Drupal\Core\Block\BlockBase;

/**
 * Provides a Chat AI block.
 *
 * @Block(
 *   id = "chat_ai",
 *   admin_label = @Translation("Chat AI"),
 *   category = @Translation("Chat AI")
 * )
 */
class ChatAIBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $form = \Drupal::formBuilder()->getForm('Drupal\chat_ai\Form\ChatBlockForm');
    return $form;
  }

}
