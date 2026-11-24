<?php

declare(strict_types=1);

namespace Drupal\user_reference_grants\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\State\StateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings: reference fields that grant view access, and field visibility.
 */
class SettingsForm extends ConfigFormBase {

  protected EntityFieldManagerInterface $entityFieldManager;

  protected EntityTypeBundleInfoInterface $bundleInfo;

  protected StateInterface $state;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    // parent::create() works on Drupal 10.3, 11 and 12 (the ConfigFormBase
    // constructor changed between versions).
    $instance = parent::create($container);
    $instance->entityFieldManager = $container->get('entity_field.manager');
    $instance->bundleInfo = $container->get('entity_type.bundle.info');
    $instance->state = $container->get('state');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'user_reference_grants_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['user_reference_grants.settings'];
  }

  /**
   * Content types with configurable user reference fields.
   *
   * @return array<string, array{label: string, references: array<string, string>, fields: array<string, string>}>
   *   Keyed by bundle: its label, its user reference fields and all its
   *   configurable fields (machine name => label).
   */
  protected function getCandidates(): array {
    $candidates = [];
    foreach ($this->bundleInfo->getBundleInfo('node') as $bundle => $info) {
      $references = [];
      $fields = [];
      foreach ($this->entityFieldManager->getFieldDefinitions('node', $bundle) as $name => $definition) {
        if ($definition->getFieldStorageDefinition()->isBaseField()) {
          continue;
        }
        $label = $definition->getLabel() . ' (' . $name . ')';
        $fields[$name] = $label;
        if ($definition->getType() === 'entity_reference'
          && $definition->getSetting('target_type') === 'user') {
          $references[$name] = $label;
        }
      }
      if ($references) {
        asort($fields);
        $candidates[$bundle] = [
          'label' => (string) $info['label'],
          'references' => $references,
          'fields' => $fields,
        ];
      }
    }
    return $candidates;
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $current = $this->config('user_reference_grants.settings')->get('bundles') ?? [];

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Users referenced in the selected fields can <strong>view</strong> (never edit or delete) the published content of that type in which they are referenced. Other access rules (Content Access, roles, field permissions) still apply.') . '</p>'
      . '<p><strong>' . $this->t('Important:') . '</strong> ' . $this->t('other node access modules on these content types must use priority 0 (Content Access uses 0 by default). After changing which fields grant access, rebuild content access permissions, preferably off-peak and after a database backup.') . '</p>',
    ];

    $candidates = $this->getCandidates();
    if (!$candidates) {
      $form['empty'] = [
        '#markup' => '<p>' . $this->t('No content type has a user reference field.') . '</p>',
      ];
    }

    $form['bundles'] = ['#tree' => TRUE];
    foreach ($candidates as $bundle => $candidate) {
      $form['bundles'][$bundle] = [
        '#type' => 'details',
        '#title' => $this->t('@label (@bundle)', ['@label' => $candidate['label'], '@bundle' => $bundle]),
        '#open' => !empty($current[$bundle]),
      ];

      foreach ($candidate['references'] as $reference => $reference_label) {
        $saved = $current[$bundle][$reference] ?? NULL;
        $prefix = 'bundles[' . $bundle . '][' . $reference . ']';

        $form['bundles'][$bundle][$reference] = [
          '#type' => 'fieldset',
          '#title' => $reference_label,
        ];
        $form['bundles'][$bundle][$reference]['grant'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Grant view access to the users referenced in this field'),
          '#default_value' => is_array($saved),
        ];
        $form['bundles'][$bundle][$reference]['limit_fields'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Limit the fields these users can see'),
          '#description' => $this->t('If unchecked, they see every field they would normally see.'),
          '#default_value' => !empty($saved['limit_fields']),
          '#states' => [
            'visible' => [':input[name="' . $prefix . '[grant]"]' => ['checked' => TRUE]],
          ],
        ];
        $form['bundles'][$bundle][$reference]['visible_fields'] = [
          '#type' => 'checkboxes',
          '#title' => $this->t('Fields these users can see'),
          '#description' => $this->t('Title, author and other base fields are always visible.'),
          '#options' => $candidate['fields'],
          '#default_value' => array_values(array_intersect(
            (array) ($saved['visible_fields'] ?? []),
            array_keys($candidate['fields'])
          )),
          '#states' => [
            'visible' => [
              ':input[name="' . $prefix . '[grant]"]' => ['checked' => TRUE],
              ':input[name="' . $prefix . '[limit_fields]"]' => ['checked' => TRUE],
            ],
          ],
        ];
      }
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $candidates = $this->getCandidates();
    $bundles = [];

    foreach ((array) $form_state->getValue('bundles') as $bundle => $references) {
      if (!isset($candidates[$bundle]) || !is_array($references)) {
        continue;
      }
      foreach ($references as $reference => $values) {
        // Only accept existing user reference fields.
        if (!isset($candidates[$bundle]['references'][$reference]) || empty($values['grant'])) {
          continue;
        }
        $visible = array_values(array_intersect(
          array_keys(array_filter((array) ($values['visible_fields'] ?? []))),
          array_keys($candidates[$bundle]['fields'])
        ));
        sort($visible);
        $bundles[$bundle][$reference] = [
          'limit_fields' => !empty($values['limit_fields']),
          'visible_fields' => $visible,
        ];
      }
      if (isset($bundles[$bundle])) {
        ksort($bundles[$bundle]);
      }
    }
    ksort($bundles);

    $config = $this->config('user_reference_grants.settings');
    $old = $config->get('bundles') ?? [];
    $config->set('bundles', $bundles)->save();

    // Grants only change when the set of granting fields changes. Field
    // visibility is checked at view time and needs no rebuild.
    if ($this->grantingFields($old) !== $this->grantingFields($bundles)) {
      // Same as node_access_needs_rebuild(TRUE).
      $this->state->set('node.node_access_needs_rebuild', TRUE);
      $this->messenger()->addWarning($this->t('The fields that grant access changed. Rebuild content access permissions to apply the change to existing content.'));
    }

    parent::submitForm($form, $form_state);
  }

  /**
   * Flattens settings into a sorted list of "bundle:field" strings.
   */
  protected function grantingFields(array $bundles): array {
    $list = [];
    foreach ($bundles as $bundle => $references) {
      foreach (array_keys((array) $references) as $reference) {
        $list[] = $bundle . ':' . $reference;
      }
    }
    sort($list);
    return $list;
  }

}
