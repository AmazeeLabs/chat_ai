<?php

namespace Drupal\Tests\chat_ai\Kernel;

/**
 * Tests the Chat AI history methods.
 *
 * @group chat_ai
 */
class ChatAIHistoryTest extends ChatAIKernelTestBase {

  /**
   * Test Chat AI retrieve method.
   *
   * @return void
   */
  public function testHistoryRetrieve() {
    $result = $this->chatAI->chatHistoryRetrieve('Prompt that does not exist');
    $this->assertTrue(empty($result) == TRUE, $this->t('History result is empty'));
  }

  /**
   * Test Chat AI insert.
   *
   * @return void
   */
  public function testHistoryInsert() {
    $result = $this->chatAI->chatHistoryInsert('Example query?', 'Example response');
    $this->assertTrue($result == 1, $this->t('Chat AI history: insert history record'));

    $result = $this->chatAI->chatHistoryRetrieve('Example query?');
    $this->assertTrue(!empty($result) == TRUE, $this->t('Chat AI history: retrieve result with exact search'));

    $result = $this->chatAI->chatHistoryRetrieve('Example query');
    $this->assertTrue(!empty($result) == TRUE, $this->t('Chat AI history: retrieve result with like operator'));

    $result = $this->chatAI->chatHistoryClear();
    $this->assertTrue($result == 1, $this->t('Chat AI history: chat clear test'));

  }

}
