<?php

namespace Drupal\Tests\chat_ai\Kernel;

/**
 * Tests the Chat AI history methods.
 *
 * @group chat_ai
 */
class ChatAISupabaseTest extends ChatAIKernelTestBase {

  /**
   * Test Chat AI insert to queue.
   *
   * @return void
   */
  public function testConfig() {

    // @todo Fix this
    $config = $this->container->get('config.factory');
    $config->getEditable('chat_ai.settings')
      ->set('supabase_key', 'key')
      ->set('supabase_url', 'url')
      ->save();

    $this->assertTrue(
      $this->supabaseOriginal->configExist(),
      $this->t('Chat AI embeddings: supabase config exists.')
     );

    $config = $this->container->get('config.factory');
    $config->getEditable('chat_ai.settings')
      ->set('supabase_key', '')
      ->set('supabase_url', '')
      ->save();
    $this->assertFalse(
      $this->supabaseOriginal->configExist(),
      $this->t('Chat AI embeddings: supabase config exists.')
    );
  }

}
