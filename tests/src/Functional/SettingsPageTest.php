<?php

namespace Drupal\Tests\chat_ai\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\Core\Url;

/**
 * Test the module settings page.
 *
 * @group chat_ai
 */
class SettingsPageTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The modules to load to run the test.
   *
   * @var array
   */
  protected static $modules = [
    'node',
    'filter',
    'user',
    'chat_ai',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
  }

  /**
   * Tests the settings form permissions (keys).
   */
  public function testSettingsPermissionsForm() {

    $authenticated_user = $this->drupalCreateUser();
    $session = $this->assertSession();

    $this->drupalLogin($authenticated_user);
    $settings = Url::fromRoute('chat_ai.keys');
    $this->drupalGet($settings);
    $session->statusCodeEquals(403);
    $this->drupalLogout();

    $admin_user = $this->drupalCreateUser([
      'administer chat_ai configuration',
    ]);
    $this->drupalLogin($admin_user);
    $this->drupalGet($settings);
    $session->statusCodeEquals(200);

  }

  /**
   * Tests the settings form (keys).
   */
  public function testSettingsForm() {

    $settings = Url::fromRoute('chat_ai.keys');
    $session = $this->assertSession();
    $admin_user = $this->drupalCreateUser([
      'administer chat_ai configuration',
    ]);
    $this->drupalLogin($admin_user);
    $this->drupalGet($settings);
    $session->statusCodeEquals(200);

    $edit = [
      'api_key' => 'dummy key',
      'api_org' => 'dummy org',
      'supabase_key' => 'dummy key',
      'supabase_url' => 'dummy url',
    ];
    $this->submitForm($edit, 'Save configuration');

    $api_key = $session->fieldExists('api_key')->getValue();
    $api_org = $session->fieldExists('api_org')->getValue();
    $this->assertTrue($api_key == 'dummy key');
    $this->assertTrue($api_org == 'dummy org');

    $this->assertSession()->pageTextContains('Incorrect API key provided');
  }

  /**
   * Tests the settings form (keys).
   */
  public function testIndexingForm() {

    $settings = Url::fromRoute('chat_ai.embeddings');
    $session = $this->assertSession();
    $admin_user = $this->drupalCreateUser([
      'administer chat_ai configuration',
    ]);
    $this->drupalLogin($admin_user);
    $this->drupalGet($settings);
    $session->statusCodeEquals(200);

    $this->assertSession()->pageTextContains('There are 0 items for indexing');
  }

}
