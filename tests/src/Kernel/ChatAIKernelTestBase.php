<?php

namespace Drupal\Tests\chat_ai\Kernel;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\chat_ai\Supabase;
use Drupal\user\Entity\User;

/**
 * Tests the Chat AI history methods.
 *
 * @group chat_ai
 */
abstract class ChatAIkernelTestBase extends KernelTestBase {

  use StringTranslationTrait;

  use NodeCreationTrait {
    getNodeByTitle as drupalGetNodeByTitle;
    createNode as drupalCreateNode;
  }

  use UserCreationTrait {
    createUser as drupalCreateUser;
    createRole as drupalCreateRole;
    createAdminRole as drupalCreateAdminRole;
  }
  use ContentTypeCreationTrait {
    createContentType as drupalCreateContentType;
  }


  /**
   * The service under test.
   *
   * @var \GuzzleHttp\Client
   *   The supabase http client.
   */
  protected $supabase;

  /**
   * The service under test.
   *
   * @var \GuzzleHttp\Client
   *   The supabase http client.
   */
  protected $supabaseOriginal;

  /**
   * The service under test.
   *
   * @var \Drupal\chat_ai\ChatAI
   */
  protected $chatAI;

  /**
   * The service under test.
   *
   * @var \Drupal\chat_ai\Embeddings
   */
  protected $embeddings;

  /**
   * A user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $user;

  /**
   * The modules to load to run the test.
   *
   * @var array
   */
  protected static $modules = [
    'chat_ai',
    'datetime',
    'node',
    'user',
    'text',
    'language',
    'system',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Needed for some user operations.
    $this->installSchema('system', ['sequences']);
    $this->installSchema('chat_ai', ['chat_ai_embeddings', 'chat_ai_history']);
    $this->installSchema('node', 'node_access');
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');

    $this->enableModules(['node', 'chat_ai']);
    $this->installConfig(['chat_ai']);

    // Ok, not sure if this is a good practice but it works fine.
    // Otherwise the mocked up object will have their methods null.
    $this->supabaseOriginal = \Drupal::service('chat_ai.supabase');

    $supabase = $this->createMock(Supabase::class);
    $supabase->method('checkSetup')->willReturn(TRUE);
    $supabase->method('clearEntityIndexedData')->willReturn('');
    $container = \Drupal::getContainer();
    $container->set('chat_ai.supabase', $supabase);

    $this->supabase = $supabase;
    $this->embeddings = \Drupal::service('chat_ai.embeddings');
    $this->chatAI = \Drupal::service('chat_ai.service');

    $this->user = User::create([
      'name' => 'username',
      'status' => 1,
    ]);
    $this->user->save();
    $this->container->get('current_user')->setAccount($this->user);

  }

}
