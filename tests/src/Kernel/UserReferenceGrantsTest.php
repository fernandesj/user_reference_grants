<?php

declare(strict_types=1);

namespace Drupal\Tests\user_reference_grants\Kernel;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\Tests\field\Traits\EntityReferenceFieldCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use Drupal\user_reference_grants\Hook\UserReferenceGrantsHooks;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests node grants and field visibility for referenced users.
 *
 * @group user_reference_grants
 */
#[Group('user_reference_grants')]
#[RunTestsInSeparateProcesses]
class UserReferenceGrantsTest extends KernelTestBase {

  use EntityReferenceFieldCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'user_reference_grants',
  ];

  protected UserInterface $teacher;

  protected UserInterface $reviewer;

  protected UserInterface $other;

  protected UserInterface $staff;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['node', 'user_reference_grants']);

    NodeType::create(['type' => 'case', 'name' => 'Case'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->createEntityReferenceField('node', 'case', 'field_teacher', 'Teacher', 'user');
    $this->createEntityReferenceField('node', 'case', 'field_reviewer', 'Reviewer', 'user');
    $this->createEntityReferenceField('node', 'page', 'field_teacher', 'Teacher', 'user');
    foreach (['field_public' => 'Public', 'field_notes' => 'Notes'] as $name => $label) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => 'string',
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => 'case',
        'label' => $label,
      ])->save();
    }

    $this->config('user_reference_grants.settings')->set('bundles', [
      'case' => [
        'field_reviewer' => ['limit_fields' => FALSE, 'visible_fields' => []],
        'field_teacher' => ['limit_fields' => TRUE, 'visible_fields' => ['field_public', 'field_teacher']],
      ],
    ])->save();

    // User 1 bypasses all access checks: create it first and do not use it.
    $this->createUser();
    $this->teacher = $this->createUser(['access content']);
    $this->reviewer = $this->createUser(['access content']);
    $this->other = $this->createUser(['access content']);
    $this->staff = $this->createUser(['access content', 'bypass node access']);
  }

  /**
   * Creates and saves a node.
   */
  protected function createCase(array $values = []): NodeInterface {
    $node = Node::create($values + [
      'type' => 'case',
      'title' => $this->randomString(),
      'status' => 1,
      'field_teacher' => $this->teacher->id(),
      'field_reviewer' => $this->reviewer->id(),
      'field_public' => 'public',
      'field_notes' => 'internal',
    ]);
    $node->save();
    return $node;
  }

  /**
   * Checks view access with a fresh access cache.
   */
  protected function canView(NodeInterface $node, AccountInterface $account): bool {
    $this->container->get('entity_type.manager')->getAccessControlHandler('node')->resetCache();
    return $node->access('view', $account);
  }

  /**
   * Tests the node_access records written for a node.
   */
  public function testRecords(): void {
    $node = $this->createCase();
    $records = $this->container->get('database')
      ->select('node_access', 'na')
      ->fields('na', ['realm', 'gid', 'grant_view', 'grant_update', 'grant_delete'])
      ->condition('nid', $node->id())
      ->orderBy('gid')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $this->assertCount(2, $records);
    foreach ($records as $record) {
      $this->assertSame(UserReferenceGrantsHooks::REALM, $record['realm']);
      $this->assertEquals(1, $record['grant_view']);
      $this->assertEquals(0, $record['grant_update']);
      $this->assertEquals(0, $record['grant_delete']);
    }
    $this->assertEquals([$this->teacher->id(), $this->reviewer->id()], array_column($records, 'gid'));
  }

  /**
   * Tests view access on the node itself.
   */
  public function testNodeAccess(): void {
    $node = $this->createCase();

    $this->assertTrue($this->canView($node, $this->teacher));
    $this->assertTrue($this->canView($node, $this->reviewer));
    $this->assertFalse($this->canView($node, $this->other));
    $this->assertFalse($this->canView($node, new AnonymousUserSession()));

    // Never update or delete.
    $this->assertFalse($node->access('update', $this->teacher));
    $this->assertFalse($node->access('delete', $this->reviewer));

    // Changing the reviewer moves the access.
    $node->set('field_reviewer', $this->other->id())->save();
    $this->assertFalse($this->canView($node, $this->reviewer));
    $this->assertTrue($this->canView($node, $this->other));

    // Unpublished content is not shared.
    $node->setUnpublished()->save();
    $this->assertFalse($this->canView($node, $this->teacher));
  }

  /**
   * Tests that a content type that is not configured gets no grants.
   */
  public function testUnconfiguredBundle(): void {
    $page = Node::create([
      'type' => 'page',
      'title' => 'Page',
      'status' => 1,
      'field_teacher' => $this->teacher->id(),
    ]);
    $page->save();
    $this->assertFalse($this->canView($page, $this->teacher));
  }

  /**
   * Tests that entity queries (and therefore Views) respect the grants.
   */
  public function testQueryAccess(): void {
    $mine = $this->createCase();
    $this->createCase([
      'field_teacher' => $this->other->id(),
      'field_reviewer' => $this->other->id(),
    ]);

    $this->setCurrentUser($this->teacher);
    $ids = $this->container->get('entity_type.manager')->getStorage('node')
      ->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'case')
      ->execute();
    $this->assertEquals([$mine->id()], array_values($ids));
  }

  /**
   * Tests field visibility limits.
   */
  public function testFieldAccess(): void {
    $node = $this->createCase();

    // Teacher: limited to field_public and field_teacher.
    $this->assertTrue($node->get('field_public')->access('view', $this->teacher));
    $this->assertTrue($node->get('field_teacher')->access('view', $this->teacher));
    $this->assertFalse($node->get('field_notes')->access('view', $this->teacher));
    $this->assertFalse($node->get('field_reviewer')->access('view', $this->teacher));
    // Base fields are never limited.
    $this->assertTrue($node->get('title')->access('view', $this->teacher));

    // Reviewer: not limited.
    $this->assertTrue($node->get('field_notes')->access('view', $this->reviewer));

    // Staff: not limited.
    $this->assertTrue($node->get('field_notes')->access('view', $this->staff));

    // A user referenced in both fields gets the unlimited relationship.
    $node->set('field_reviewer', $this->teacher->id())->save();
    $this->assertTrue($node->get('field_notes')->access('view', $this->teacher));

    // The bypass permission lifts the limits.
    $node->set('field_reviewer', $this->reviewer->id())->save();
    $limited_staff = $this->createUser(['access content', UserReferenceGrantsHooks::BYPASS_FIELD_LIMITS]);
    $node->set('field_teacher', $limited_staff->id())->save();
    $this->assertTrue($node->get('field_notes')->access('view', $limited_staff));
  }

}
