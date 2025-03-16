<?php

namespace Drupal\chat_ai;

use Drupal\Core\File\FileExists;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use Masterminds\HTML5;
use Stevebauman\Hypertext\Transformer;

/**
 * Service to fetch and process URL content.
 */
class ContentFetcher {

  /**
   * HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger channel factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $logger;

  /**
   * Constructs a ContentFetcher object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory) {
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('chat_ai');
  }

  /**
   *
   */
  public function fetchContentEntityContent(EntityInterface $entity) {

    // @todo Take this from env variaables.
    // DEVV PRODUCTION_URL=https://dev.paraplegie.ch
    $base_url = getenv('PRODUCTION_URL') ?: 'https://dev.paraplegie.ch';

    $url = $base_url . $entity->toUrl()->toString();
    $text = $this->fetchUrl($url);

    if (!$text) {
      return NULL;
    }

    /** @var \Drupal\file\FileRepositoryInterface $fileRepository */
    $fileRepository = \Drupal::service('file.repository');
    $file_system = \Drupal::service('file_system');
    $dir = 'public://fetched-ai';

    $file_name = $entity->language()->getId() . $entity->uuid();
    $file_system->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $fileRepository->writeData($text, "{$dir}/{$file_name}", FileExists::Replace);
    $file_path = $file_system->realpath("{$dir}/{$file_name}");
    return $file_path ?: NULL;
  }

  /**
   * Fetches URL content and converts it to Markdown.
   *
   * @param string $url
   *   The URL to fetch.
   * @param string $selector
   *   CSS selector for content extraction (default: 'body').
   *
   * @return string|null
   *   The processed content in Markdown format, or null on failure.
   */
  public function fetchUrl(string $url, string $selector = 'body'): ?string {
    try {
      // Fetch the URL content.
      $response = $this->httpClient->request('GET', $url, [
        'headers' => [
          'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
        ],
      ]);

      // Perhaps try this as well.
      $response = $this->httpClient->request('GET', $url, [
        'headers' => [
          'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
          'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
          'Accept-Language' => 'en-US,en;q=0.5',
          'Accept-Encoding' => 'gzip, deflate',
          'Media-Type' => 'print',
        ],
      ]);

      if ($response->getStatusCode() == 404) {
        return NULL;
      }

      $html = $response->getBody()->getContents();

      // Parse HTML.
      $html5 = new HTML5();
      $document = $html5->loadHTML($html);

      // Handle selector.
      $content_node = NULL;
      if ($selector === 'body') {
        $body = $document->getElementsByTagName('body')->item(0);
        $content_node = $body ?? $document->documentElement;
      }
      else {
        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query($this->convertSelectorToXpath($selector));
        $content_node = $nodes->length > 0 ? $nodes->item(0) : NULL;
      }

      if (!$content_node) {
        $this->logger->warning('No content found for selector @selector in @url', [
          '@selector' => $selector,
          '@url' => $url,
        ]);
        return NULL;
      }

      // Clean the content
      // $this->cleanNode($content_node);
      // $html = $document->saveHTML($content_node);
      $transformer = new Transformer();
      // $transformer->filter("//*[@id='some-element']");
      $transformer->keepNewLines();
      // $transformer->keepLinks(FALSE);
      $text = $transformer->toText($html);
      return $text;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to fetch URL @url: @message', [
        '@url' => $url,
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Cleans up Markdown by removing excessive newlines and image markup.
   *
   * @param string $markdown
   *   The Markdown string to clean.
   *
   * @return string
   *   The cleaned Markdown string.
   */
  protected function cleanMarkdown(string $markdown): string {
    // Remove image markup (e.g., ![alt text](url))
    $markdown = preg_replace('/!\[.*?\]\(.*?\)\n*/', '', $markdown);

    // Replace multiple newlines with a maximum of two
    // $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);.
    $markdown = preg_replace('/\n{1,}/', "\n", $markdown);

    // Trim leading and trailing newlines.
    $markdown = trim($markdown, "\n");

    return $markdown;
  }

  /**
   * Cleans DOM node by removing unwanted elements and attributes.
   *
   * @param \DOMNode $node
   *   The DOM node to clean.
   */
  protected function cleanNode(\DOMNode $node): void {
    $remove_tags = ['script', 'style', 'img', 'iframe', 'noscript'];
    $nodes_to_remove = [];

    // Collect nodes to remove.
    foreach ($node->childNodes as $child) {
      if ($child instanceof \DOMElement) {
        if (in_array(strtolower($child->tagName), $remove_tags)) {
          $nodes_to_remove[] = $child;
        }
        else {
          // Remove class attributes.
          if ($child->hasAttribute('class')) {
            $child->removeAttribute('class');
          }
          // Recursively clean child nodes.
          $this->cleanNode($child);
        }
      }
    }
  }

  /**
   * Converts CSS selector to XPath.
   *
   * @param string $selector
   *   CSS selector.
   *
   * @return string
   *   XPath expression.
   */
  protected function convertSelectorToXpath(string $selector): string {
    // Handle basic selectors (excluding 'body' which is handled separately)
    if (strpos($selector, '#') === 0) {
      return "//*[@id='" . substr($selector, 1) . "']";
    }
    elseif (strpos($selector, '.') === 0) {
      return "//*[contains(@class, '" . substr($selector, 1) . "')]";
    }
    return "//" . $selector;
  }

}
