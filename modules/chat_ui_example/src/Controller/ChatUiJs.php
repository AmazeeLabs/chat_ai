<?php

declare(strict_types=1);

namespace Drupal\chat_ui_example\Controller;

use Drupal\Core\Controller\ControllerBase;
use MatthiasMullie\Minify\JS;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returns responses for Chat UI example routes.
 */
final class ChatUiJs extends ControllerBase {

  /**
   * Builds the response.
   */
  public function serve() {

    $module_path = \Drupal::service('module_handler')->getModule('chat_ui_example')->getPath();
    $file_path = $module_path . '/js/chat-ui.js';

    // Check if the file exists.
    if (!file_exists($file_path)) {
      return new Response('File not found.', 404);
    }

    // Read the file contents.
    $js_content = file_get_contents($file_path);

    // Get the Drupal base URL.
    $base_url = \Drupal::request()->getSchemeAndHttpHost() ?: '';

    // Replace a placeholder or static URL in the JavaScript.
    // Assuming the JS has a placeholder like `{{BASE_URL}}` or a static URL to replace.
    // $modified_js = str_replace('{{BASE_URL}}', $base_url, $js_content);
    $modified_js = str_replace('/chat/completion', "{$base_url}/chat/completion", $js_content);

    // Minify the JavaScript.
    try {
      $minifier = new JS($modified_js);
      $minified_js = $minifier->minify();
    } catch (\Exception $e) {
      // Log minification error and serve unminified content as fallback.
      \Drupal::logger('chat_ui_example')->error('JS minification failed: @message', ['@message' => $e->getMessage()]);
      $minified_js = $modified_js;
    }

    // Return the modified JavaScript as a response.
    $response = new Response($minified_js);
    $response->headers->set('Content-Type', 'application/javascript');

    // Optional: Cache the response to reduce fetching.
    $response->setCache([
      'max_age' => 3600, // Cache for 1 hour.
      'public' => TRUE,
    ]);

    return $response;
  }
}
