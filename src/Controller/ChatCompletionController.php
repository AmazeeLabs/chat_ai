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

    // @todo Extract this to method.
    $allowed_origins = [
      '127.0.0.1',
      'localhost',
      parse_url($base_url, PHP_URL_HOST),
    ];

    $allowed_origins_settings = $this->config('chat_ai.settings')->get('allowed_origins');

    $bypass_origin_checks = $this->config('chat_ai.settings')->get('bypass_origin_checks');

    if (!empty($allowed_origins_array)) {
      $allowed_origins_array = array_filter(array_map('trim', explode("\n", $allowed_origins_settings)));
      foreach ($allowed_origins_array as $origin) {
        $parsed_origin = parse_url($origin, PHP_URL_HOST) ?: $origin;
        $allowed_origins[] = $parsed_origin;
      }
    }

    $origin = $request->headers->get('Origin');

    // If no origin header is present, fall back to client IP.
    if (!$origin) {
      $origin = $request->getClientIp();
    }
    $parsed_origin = parse_url($origin, PHP_URL_HOST) ?: $origin;

    $origin_allowed = in_array($parsed_origin, $allowed_origins);
    if (!$origin_allowed) {

      // Debug.
      \Drupal::logger('chat_ai')->debug("<pre>" . print_r($allowed_origins, TRUE) . "</pre>");
      \Drupal::logger('chat_ai')->debug("The request origin: {$parsed_origin} is not allowed.");

      if (!$bypass_origin_checks) {
        return new JsonResponse([
          'error' => 'Unauthorized',
          'message' => 'Request origin not allowed',
        ], 403);
      }
    }

    $data = $request->getContent();
    $input = json_decode($data, TRUE);

    // Validate required parameters.
    if (!isset($input['message']) || !isset($input['langcode'])) {
      return new JsonResponse([
        'error' => 'Missing required parameters: message and langcode are required',
      ], 400);
    }

    $question = $input['message'];
    $langcode = $input['langcode'];
    $history = $input['history'];

    if (!is_array($history) || empty($history)) {
      $history = [];
    }

    $chat_service = \Drupal::service('chat_ai.service');
    if (!empty($history)) {
      $question = $chat_service->transform($question, $langcode, $history);
    }

    $context = \Drupal::service('chat_ai.supabase')->getMultiQueryMatchingChunks($question);
    $context = implode('\n', $context);

    // Prepapre choices.
    $choices = '';
    $choices = $chat_service->chat($question, $context, $langcode, $history);
    $choices = implode('<br />', $choices);
    $choices = "<p class='chat-gpt'>{$choices}</p>";

    $response_data = [
      'status' => 'success',
      'answer' => $choices,
      'langcode' => $langcode,
      'processed_at' => date('c'),
    ];

    $response = new JsonResponse($response_data);
    if ($origin_allowed && $origin) {
      $response->headers->set('Access-Control-Allow-Origin', $origin);
      $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
      $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');
    }
    return $response;
  }

}
