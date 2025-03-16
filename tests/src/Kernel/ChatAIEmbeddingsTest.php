<?php

namespace Drupal\Tests\chat_ai\Kernel;

use Drupal\node\Entity\Node;

/**
 * Tests the Chat AI history methods.
 *
 * @group chat_ai
 */
class ChatAIEmbeddingsTest extends ChatAIKernelTestBase {

  /**
   * Test Chat AI insert.
   *
   * @return void
   */
  public function testEmbeddingIndex() {

    $node = Node::create([
      'type' => 'page',
      'status' => 1,
      'title' => 'Test page',
      'uid' => $this->drupalCreateUser()->id(),
      'body' => 'Some body value',
    ]);
    $node->save();

    // Set up our custom test config.
    $config = $this->config('chat_ai.settings');
    $config->set('include', '{"block_content__basic_include":0,"block_content__basic_view_mode":"default","comment__comment_include":0,"comment__comment_view_mode":"default","node__article_include":0,"node__article_view_mode":"default","node__page_include":1,"node__page_view_mode":"default","user__user_include":0,"user__user_view_mode":"default"}');
    $config->save();
    $this->assertTrue(
      $this->embeddings->shouldIndex($node),
      $this->t('Chat AI embeddings: test node page should be included')
    );
    $node->set('status', 0)->save();
    $this->assertFALSE(
      $this->embeddings->shouldIndex($node),
      $this->t('Chat AI embeddings: test node page should not be included')
    );

    $article = Node::create([
      'type' => 'article',
      'status' => 1,
      'title' => 'Test article',
      'uid' => $this->drupalCreateUser()->id(),
      'body' => 'Some body article value',
    ]);
    $article->save();
    $this->assertFALSE(
      $this->embeddings->shouldIndex($article),
      $this->t('Chat AI embeddings: test node article should not be included')
    );
  }

  /**
   * Test Chat AI insert to queue.
   *
   * @return void
   */
  public function testEmbeddingInsert() {
    // Set up our custom test config.
    $config = $this->config('chat_ai.settings');
    $config->set('include', '{"block_content__basic_include":0,"block_content__basic_view_mode":"default","comment__comment_include":0,"comment__comment_view_mode":"default","node__article_include":0,"node__article_view_mode":"default","node__page_include":1,"node__page_view_mode":"default","user__user_include":0,"user__user_view_mode":"default"}');
    $config->save();

    $node = Node::create([
      'type' => 'page',
      'status' => 1,
      'title' => 'Test page',
      'uid' => $this->drupalCreateUser()->id(),
      'langcode' => 'en',
      'body' => 'Some body value',
    ]);

    $node->save();

    $this->embeddings->insertDatabaseEmbedding($node);
    $this->embeddings->insertToQueue($node);
    $this->assertTrue(
      $this->embeddings->isQueued($node),
      $this->t('Chat AI embeddings: check if entity is in queue')
    );

    $this->embeddings->setIndexed($node);
    $this->assertTrue(
      $this->embeddings->isIndexed($node),
      $this->t('Chat AI embeddings: check if entity is indexed')
    );

    $this->embeddings->setQueued($node, FALSE);
    $this->assertFalse(
      $this->embeddings->isQueued($node),
      $this->t('Chat AI embeddings: check if entity is not in queue')
    );

    $this->embeddings->setIndexed($node, FALSE);
    $this->assertFalse(
      $this->embeddings->isIndexed($node),
      $this->t('Chat AI embeddings: check if entity is not in queue')
    );
  }

}
