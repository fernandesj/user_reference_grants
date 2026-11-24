<?php

declare(strict_types=1);

namespace Drupal\user_reference_grants\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;

/**
 * Hook implementations for User Reference Grants.
 *
 * How it works:
 * - hook_node_access_records(): when a published node of a configured
 *   content type is saved, one view grant is written per user referenced in
 *   the configured fields (realm self::REALM, gid = user ID).
 * - hook_node_grants(): every authenticated user holds the grant with their
 *   own user ID, for the "view" operation only.
 * - hook_entity_field_access(): optionally limits which fields a referenced
 *   user can see, depending on the field that references them.
 *
 * Update and delete are never granted. Grants are combined (OR) with those of
 * other node access modules, such as Content Access, as long as all of them
 * use the same priority: core drops grants below the highest priority.
 *
 * On Drupal 11.1+ these methods are called directly (#[Hook]). On Drupal 10
 * they are called by the #[LegacyHook] functions in the .module file.
 */
class UserReferenceGrantsHooks {

  use StringTranslationTrait;

  /**
   * Realm of this module's grants in the node_access table.
   */
  public const REALM = 'user_reference_grants';

  /**
   * Grant priority. Must match other node access modules (usually 0).
   */
  public const PRIORITY = 0;

  /**
   * Permission that lifts field visibility limits.
   */
  public const BYPASS_FIELD_LIMITS = 'bypass user reference field limits';

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): ?string {
    if ($route_name !== 'help.page.user_reference_grants') {
      return NULL;
    }
    return '<h2>' . $this->t('About') . '</h2>'
      . '<p>' . $this->t('User Reference Grants gives <strong>view</strong> access to a piece of content to the users referenced in its user reference fields (for example "Teacher" or "Reviewer"). It never grants update or delete access.') . '</p>'
      . '<p>' . $this->t('It uses the node grants system, so access also applies to Views listings and search. Grants are added to those of other node access modules, such as Content Access, when all of them use the same priority (0).') . '</p>'
      . '<p>' . $this->t('For each reference field you can also limit the fields those users can see. Roles with the "Bypass user reference field limits" permission always see all fields.') . '</p>'
      . '<p>' . $this->t('After changing which fields grant access, rebuild content access permissions.') . '</p>';
  }

  /**
   * Implements hook_node_grants().
   */
  #[Hook('node_grants')]
  public function nodeGrants(AccountInterface $account, string $operation): array {
    if ($operation !== 'view' || $account->isAnonymous()) {
      return [];
    }
    return [self::REALM => [(int) $account->id()]];
  }

  /**
   * Implements hook_node_access_records().
   */
  #[Hook('node_access_records')]
  public function nodeAccessRecords(NodeInterface $node): array {
    // Unpublished content is never shared with referenced users.
    if (!$node->isPublished()) {
      return [];
    }

    $grants = [];
    foreach ($this->referencedUserIds($node, array_keys($this->getBundleSettings($node->bundle()))) as $uid) {
      $grants[] = [
        'realm' => self::REALM,
        'gid' => $uid,
        'grant_view' => 1,
        'grant_update' => 0,
        'grant_delete' => 0,
        'priority' => self::PRIORITY,
      ];
    }
    return $grants;
  }

  /**
   * Implements hook_entity_field_access().
   */
  #[Hook('entity_field_access')]
  public function entityFieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    $config = $this->configFactory->get('user_reference_grants.settings');
    $result = AccessResult::neutral()->addCacheableDependency($config);

    if ($operation !== 'view' || $items === NULL || $field_definition->getTargetEntityTypeId() !== 'node') {
      return $result;
    }
    $node = $items->getEntity();
    if (!$node instanceof NodeInterface) {
      return $result;
    }
    // Base fields (title, author, dates...) are never limited.
    if ($field_definition->getFieldStorageDefinition()->isBaseField()) {
      return $result;
    }
    $settings = $this->getBundleSettings($node->bundle());
    if (!$settings) {
      return $result;
    }

    $result->cachePerPermissions();
    if ($account->hasPermission('bypass node access') || $account->hasPermission(self::BYPASS_FIELD_LIMITS)) {
      return $result;
    }

    $result->cachePerUser()->addCacheableDependency($node);
    $uid = (int) $account->id();
    if ($uid === 0) {
      return $result;
    }

    $referenced = FALSE;
    $visible = [];
    foreach ($settings as $reference_field => $reference_settings) {
      if (!in_array($uid, $this->referencedUserIds($node, [$reference_field]), TRUE)) {
        continue;
      }
      $referenced = TRUE;
      // One unlimited relationship is enough to see every field.
      if (empty($reference_settings['limit_fields'])) {
        return $result;
      }
      $visible = array_merge($visible, (array) ($reference_settings['visible_fields'] ?? []));
    }

    // Users not referenced keep whatever access they already had.
    if (!$referenced || in_array($field_definition->getName(), $visible, TRUE)) {
      return $result;
    }
    return AccessResult::forbidden('Field hidden from users referenced in this content.')->inheritCacheability($result);
  }

  /**
   * Returns the settings of a content type, keyed by reference field name.
   *
   * @return array<string, array{limit_fields?: bool, visible_fields?: string[]}>
   *   Settings of each reference field that grants view access.
   */
  public function getBundleSettings(string $bundle): array {
    $bundles = $this->configFactory->get('user_reference_grants.settings')->get('bundles') ?? [];
    $settings = $bundles[$bundle] ?? [];
    return is_array($settings) ? array_filter($settings, 'is_array') : [];
  }

  /**
   * Returns the IDs of the users referenced in the given fields of a node.
   *
   * Fields that do not exist or are not user references are ignored.
   *
   * @return int[]
   *   Unique user IDs.
   */
  protected function referencedUserIds(NodeInterface $node, array $field_names): array {
    $uids = [];
    foreach ($field_names as $field_name) {
      if (!is_string($field_name) || !$node->hasField($field_name)) {
        continue;
      }
      $definition = $node->getFieldDefinition($field_name);
      if ($definition->getType() !== 'entity_reference'
        || $definition->getSetting('target_type') !== 'user') {
        continue;
      }
      foreach ($node->get($field_name) as $item) {
        $uid = (int) $item->target_id;
        if ($uid > 0) {
          $uids[$uid] = $uid;
        }
      }
    }
    return array_values($uids);
  }

}
