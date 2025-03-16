<?php

namespace Drupal\chat_ai\Http;

use GuzzleHttp\Client;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for generating OpenAI clients.
 */
class SupabaseClientFactory {

  /**
   * The config settings object.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Constructs a new ClientFactory instance.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory service.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->config = $config_factory->get('chat_ai.settings');
  }

  /**
   * Creates a new Supabase client.
   *
   * @return \GuzzleHttp\Client
   *   The supabase http client.
   */
  public function create() {
    $api_key = $this->config->get('supabase_key');
    $base_uri = $this->config->get('supabase_url') . '/rest/v1/';
    $client = new Client([
      'base_uri' => $base_uri,
      'headers' => [
        'Content-Type'  => 'application/json',
        'apikey' => $api_key,
        'Authorization' => "Bearer $api_key",
      ],
    ]);
    return $client;
  }

}
