<?php

namespace Drupal\chat_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for chat completion endpoint.
 */
class ChatCompletionController extends ControllerBase {

  /**
   * Handles chat completion requests.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function complete(Request $request) {

    global $base_url;
    $this->config('chat_ai.settings');

    global $base_url;
    $allowed_origins = [
      '127.0.0.1',
      'localhost',
      $base_url,
    ];
    $origin = $request->headers->get('Origin');

    // If no origin header is present, fall back to client IP
    if (!$origin) {
      $origin = $request->getClientIp();
    }
    $parsed_origin = parse_url($origin, PHP_URL_HOST) ?: $origin;
    if (!in_array($parsed_origin, $allowed_origins)) {
      \Drupal::logger('chat_ai')->debug($parsed_origin);
      return new JsonResponse([
        'error' => 'Unauthorized',
        'message' => 'Request origin not allowed'
      ], 403); // 403 Forbidden status
    }

    $data = $request->getContent();
    $input = json_decode($data, TRUE);

    // Validate required parameters
    if (!isset($input['message']) || !isset($input['langcode'])) {
      return new JsonResponse([
        'error' => 'Missing required parameters: message and langcode are required',
      ], 400);
    }

    $message = $input['message'];
    $langcode = $input['langcode'];
    $history = $input['history'];

    if (!is_array($history) || empty($history)) {
      $history = [];
    }

    $context = \Drupal::service('chat_ai.supabase')->getMultiQueryMatchingChunks($message);
    $context = implode('\n', $context);
    $choices = \Drupal::service('chat_ai.service')->chat($message, $context, $langcode, $history);
    $choices = implode('<br />', $choices);
    $choices = "<p class='chat-gpt'>{$choices}</p>";

    $response_data = [
      'status' => 'success',
      'answer' => $choices,
      'langcode' => $langcode,
      'processed_at' => date('c'),
    ];

    // Return JSON response
    return new JsonResponse($response_data);
  }
}
