<?php

namespace Drupal\chat_ai\Http;

use Drupal\Core\Config\ConfigFactoryInterface;
use OpenAI\Client;

/**
 * Service for generating OpenAI clients.
 */
class OpenAiClientFactory {

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
   * Creates a new OpenAI client.
   *
   * @return \OpenAI\Client
   *   The OpenAI client.
   */
  public function create(): Client {
    // @todo Update to use endpoint as well
    return $client = \OpenAI::client($this->config->get('api_key') ?: '');
  }

}
