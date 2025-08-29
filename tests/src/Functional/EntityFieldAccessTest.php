<?php

namespace Drupal\Tests\islandora_workbench_integration;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\UnitTestCase;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests for islandora_workbench_integration_entity_field_access function.
 *
 * @group islandora_workbench_integration
 */
class EntityFieldAccessTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'node',
    'field',
    'text',
    'rest',
    'system',
    'user',
    'islandora_workbench_integration',
  ];

  /**
   * Test user with workbench permission.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $workbenchUser;

  /**
   * Test user without workbench permission.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $regularUser;

  /**
   * Mock field definition for file entity.
   *
   * @var \Drupal\Core\Field\FieldDefinitionInterface
   */
  protected $fileFieldDefinition;

  /**
   * Mock field definition for media entity.
   *
   * @var \Drupal\Core\Field\FieldDefinitionInterface
   */
  protected $mediaFieldDefinition;

  /**
   * Mock field definition for node entity.
   *
   * @var \Drupal\Core\Field\FieldDefinitionInterface
   */
  protected $nodeFieldDefinition;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Clear plugin cache to ensure our custom Views plugin is discovered.
    \Drupal::service('plugin.manager.views.access')->clearCachedDefinitions();

    // Rebuild the container to ensure all services are properly loaded.
    $this->rebuildContainer();

    // Create a role with workbench permission.
    $workbench_role = Role::create([
      'id' => 'workbench_user',
      'label' => 'Workbench User',
    ]);
    $workbench_role->grantPermission('use islandora workbench');
    $workbench_role->save();

    // Create users.
    $this->workbenchUser = User::create([
      'name' => 'workbench_test_user',
      'mail' => 'workbench@example.com',
      'status' => 1,
    ]);
    $this->workbenchUser->addRole('workbench_user');
    $this->workbenchUser->save();

    $this->regularUser = User::create([
      'name' => 'regular_test_user',
      'mail' => 'regular@example.com',
      'status' => 1,
    ]);
    $this->regularUser->save();

    // Create mock field definitions.
    $this->fileFieldDefinition = $this->createMock(FieldDefinitionInterface::class);
    $this->fileFieldDefinition->method('getTargetEntityTypeId')->willReturn('file');
    $this->fileFieldDefinition->method('getName')->willReturn('test_file_field');

    $this->mediaFieldDefinition = $this->createMock(FieldDefinitionInterface::class);
    $this->mediaFieldDefinition->method('getTargetEntityTypeId')->willReturn('media');
    $this->mediaFieldDefinition->method('getName')->willReturn('test_media_field');

    $this->nodeFieldDefinition = $this->createMock(FieldDefinitionInterface::class);
    $this->nodeFieldDefinition->method('getTargetEntityTypeId')->willReturn('node');
    $this->nodeFieldDefinition->method('getName')->willReturn('test_node_field');
  }

  /**
   * Test access is neutral for non-edit operations.
   */
  public function testNonEditOperationReturnsNeutral(): void {
    $this->setCurrentRoute('rest.file.upload.POST', 'POST');

    $result = islandora_workbench_integration_entity_field_access(
      'view',
      $this->fileFieldDefinition,
      $this->workbenchUser
    );

    $this->assertTrue($result->isNeutral());
  }

  /**
   * Test access is neutral for non-file/media entities.
   */
  public function testNonFileMediaEntityReturnsNeutral(): void {
    $this->setCurrentRoute('rest.file.upload.POST', 'POST');

    $result = islandora_workbench_integration_entity_field_access(
      'edit',
      $this->nodeFieldDefinition,
      $this->workbenchUser
    );

    $this->assertTrue($result->isNeutral());
  }

  /**
   * Test access is neutral for non-file-upload routes.
   */
  public function testNonFileUploadRouteReturnsNeutral(): void {
    $this->setCurrentRoute('some.other.route', 'POST');

    $result = islandora_workbench_integration_entity_field_access(
      'edit',
      $this->fileFieldDefinition,
      $this->workbenchUser
    );

    $this->assertTrue($result->isNeutral());
  }

  /**
   * Test access is neutral for non-POST requests.
   */
  public function testNonPostRequestReturnsNeutral(): void {
    $this->setCurrentRoute('rest.file.upload.POST', 'GET');

    $result = islandora_workbench_integration_entity_field_access(
      'edit',
      $this->fileFieldDefinition,
      $this->workbenchUser
    );

    $this->assertTrue($result->isNeutral());
  }

  /**
   * Test access is allowed for workbench user on file upload route.
   */
  public function testWorkbenchUserFileFieldAccessAllowed(): void {
    $this->setCurrentRoute('rest.file.upload.POST', 'POST');

    $result = islandora_workbench_integration_entity_field_access(
      'edit',
      $this->fileFieldDefinition,
      $this->workbenchUser
    );

    $this->assertTrue($result->isAllowed());
    $this->assertEquals(['user.permissions'], $result->getCacheContexts());
  }

  /**
   * Test access is allowed for workbench user on media field.
   */
  public function testWorkbenchUserMediaFieldAccessAllowed(): void {
    $this->setCurrentRoute('rest.file.upload.POST', 'POST');

    $result = islandora_workbench_integration_entity_field_access(
      'edit',
      $this->mediaFieldDefinition,
      $this->workbenchUser
    );

    $this->assertTrue($result->isAllowed());
    $this->assertEquals(['user.permissions'], $result->getCacheContexts());
  }

  /**
   * Test access is neutral for regular user without workbench permission.
   */
  public function testRegularUserAccessNeutral(): void {
    $this->setCurrentRoute('rest.file.upload.POST', 'POST');

    $result = islandora_workbench_integration_entity_field_access(
      'edit',
      $this->fileFieldDefinition,
      $this->regularUser
    );

    $this->assertTrue($result->isNeutral());
  }

  /**
   * Test that exceptions are handled gracefully.
   */
  public function testExceptionHandlingReturnsNeutral(): void {
    // Create a field definition that will throw an exception.
    $faultyFieldDefinition = $this->createMock(FieldDefinitionInterface::class);
    $faultyFieldDefinition->method('getTargetEntityTypeId')->willThrowException(new \Exception('Test exception'));

    $result = islandora_workbench_integration_entity_field_access(
      'edit',
      $faultyFieldDefinition,
      $this->workbenchUser
    );

    $this->assertTrue($result->isNeutral());
  }

  /**
   * Test logging is called for successful workbench user access.
   */
  public function testLoggingForSuccessfulAccess(): void {
    $this->setCurrentRoute('rest.file.upload.POST', 'POST');

    // Enable database logging to capture log messages.
    \Drupal::service('module_installer')->install(['dblog']);

    $result = islandora_workbench_integration_entity_field_access(
      'edit',
      $this->fileFieldDefinition,
      $this->workbenchUser
    );

    $this->assertTrue($result->isAllowed());

    // Check that debug and info log entries were created.
    $log_entries = \Drupal::database()->select('watchdog', 'w')
      ->fields('w', ['message', 'variables', 'severity'])
      ->condition('type', 'logger.channel.islandora_workbench_integration')
      ->execute()
      ->fetchAll();

    $this->assertNotEmpty($log_entries);

    // Check for debug log entry.
    $debug_found = FALSE;
    $info_found = FALSE;

    foreach ($log_entries as $entry) {
      $variables = unserialize($entry->variables, ['allowed_classes' => FALSE]);
      if (strpos($entry->message, 'Field access check') !== FALSE) {
        $debug_found = TRUE;
        $this->assertEquals('rest.file.upload.POST', $variables['@route']);
        $this->assertEquals('test_file_field', $variables['@field']);
        $this->assertEquals($this->workbenchUser->id(), $variables['@user']);
      }
      if (strpos($entry->message, 'Allowing file field access') !== FALSE) {
        $info_found = TRUE;
        $this->assertEquals($this->workbenchUser->id(), $variables['@user']);
      }
    }

    $this->assertTrue($debug_found, 'Debug log entry was created');
    $this->assertTrue($info_found, 'Info log entry was created');
  }

  /**
   * Helper method to set current route and request method.
   *
   * @param string $route_name
   *   The route name to set.
   * @param string $method
   *   The HTTP method to set.
   */
  protected function setCurrentRoute(string $route_name, string $method): void {
    // Mock the route match service.
    $route_match = $this->createMock(RouteMatchInterface::class);
    $route_match->method('getRouteName')->willReturn($route_name);

    // Mock the request.
    $request = $this->createMock(Request::class);
    $request->method('getMethod')->willReturn($method);

    // Mock the request stack.
    $request_stack = $this->createMock(RequestStack::class);
    $request_stack->method('getCurrentRequest')->willReturn($request);

    // Replace the services in the container.
    $container = \Drupal::getContainer();
    $container->set('current_route_match', $route_match);
    $container->set('request_stack', $request_stack);

    \Drupal::setContainer($container);
  }

}

/**
 * Unit tests for the field access function with more isolated testing.
 *
 * @group islandora_workbench_integration
 */
class EntityFieldAccessUnitTest extends UnitTestCase {

  /**
   * Test the function directly with mocked dependencies.
   */
  public function testFieldAccessFunctionDirectly(): void {
    // This test would require more complex mocking of Drupal services
    // and is better suited for the functional test above.
    // Keeping this as a placeholder for potential unit tests.
    $this->markTestSkipped('Direct unit testing requires complex service mocking');
  }

}
