<?php

namespace Drupal\chat_ai\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Returns responses for Chat AI routes.
 */
class ChatAiController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function build() {

    $build['content'] = [
      '#type' => 'item',
      '#markup' => $this->t('It works!'),
    ];

    return $build;
  }

}
