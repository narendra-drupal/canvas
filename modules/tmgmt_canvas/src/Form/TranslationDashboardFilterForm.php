<?php

declare(strict_types=1);

namespace Drupal\tmgmt_canvas\Form;

use Drupal\content_translation\ContentTranslationManagerInterface;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Filter form for the Canvas translation dashboard.
 */
final class TranslationDashboardFilterForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly ContentTranslationManagerInterface $contentTranslationManager,
    private readonly Request $request,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('language_manager'),
      $container->get('content_translation.manager'),
      $container->get('request_stack')->getCurrentRequest(),
    );
  }

  public function getFormId(): string {
    return 'canvas_translation_dashboard_filter';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $entity_type = $this->request->query->get('entity_type', 'node');
    $bundle = $this->request->query->get('bundle', '');
    $langcode = $this->request->query->get('langcode', '');
    $title = $this->request->query->get('title', '');
    $status = $this->request->query->get('status', '');

    $form['filters'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['views-exposed-form'],
        'style' => 'display:flex;flex-wrap:wrap;align-items:flex-end;gap:1rem;',
      ],
    ];

    $auto_submit = ['onchange' => 'this.form.submit()'];

    $entity_type_options = $this->getTranslatableEntityTypes();
    $form['filters']['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity type'),
      '#options' => $entity_type_options,
      '#default_value' => \array_key_exists($entity_type, $entity_type_options) ? $entity_type : array_key_first($entity_type_options),
      '#required' => TRUE,
      '#attributes' => $auto_submit,
    ];

    $bundle_options = $this->getTranslatableBundles($entity_type);
    if (!empty($bundle_options)) {
      $form['filters']['bundle'] = [
        '#type' => 'select',
        '#title' => $this->t('Bundle'),
        '#options' => $bundle_options,
        '#default_value' => $bundle,
        '#empty_option' => $this->t('- All -'),
        '#attributes' => $auto_submit,
      ];
    }

    $language_options = $this->getLanguageOptions();
    $default_langcode = $langcode ?: array_key_first($language_options);
    $form['filters']['langcode'] = [
      '#type' => 'select',
      '#title' => $this->t('Language'),
      '#options' => $language_options,
      '#default_value' => $default_langcode,
      '#required' => TRUE,
      '#attributes' => $auto_submit,
    ];

    $form['filters']['title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Title'),
      '#default_value' => $title,
      '#size' => 30,
    ];

    $form['filters']['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Status'),
      '#options' => [
        '' => $this->t('- Any -'),
        'none' => $this->t('Not translated'),
        'translated' => $this->t('Translated'),
        'outdated' => $this->t('Outdated'),
      ],
      '#default_value' => $status,
      '#attributes' => $auto_submit,
    ];

    $form['filters']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Search'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $query = [
      'entity_type' => $form_state->getValue('entity_type'),
      'langcode' => $form_state->getValue('langcode'),
    ];

    if ($form_state->getValue('bundle')) {
      $query['bundle'] = $form_state->getValue('bundle');
    }
    if ($form_state->getValue('title')) {
      $query['title'] = $form_state->getValue('title');
    }
    if ($form_state->getValue('status')) {
      $query['status'] = $form_state->getValue('status');
    }

    $form_state->setRedirect('canvas.translation_dashboard', [], ['query' => $query]);
  }

  /**
   * Returns translatable entity type options, sorted alphabetically.
   */
  public function getTranslatableEntityTypes(): array {
    $options = [];

    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $definition) {
      if ($definition instanceof ConfigEntityTypeInterface) {
        continue;
      }
      $bundle_entity_type = $definition->getBundleEntityType();
      if ($bundle_entity_type) {
        $bundle_ids = \array_keys($this->entityTypeManager->getStorage($bundle_entity_type)->loadMultiple());
        foreach ($bundle_ids as $bundle_id) {
          if ($this->contentTranslationManager->isEnabled($entity_type_id, $bundle_id)) {
            $options[$entity_type_id] = $definition->getLabel();
            break;
          }
        }
      }
      else {
        if ($this->contentTranslationManager->isEnabled($entity_type_id, $entity_type_id)) {
          $options[$entity_type_id] = $definition->getLabel();
        }
      }
    }

    // @todo Use TMGMT as source of truth for available entity types instead of
    //   this hardcoded list. TMGMT's config source plugin (tmgmt_config) uses
    //   plugin.manager.config_translation.mapper to enumerate config entity
    //   types, and ContentEntitySource uses content_translation.manager for
    //   content entity types. Aligning with TMGMT would ensure the dashboard
    //   only shows entity types that TMGMT can actually translate.
    // Canvas config entity types are included when config_translation support
    // is active (i.e., canvas_dev_translation module is installed), indicated
    // by the presence of the config-translation-overview link template.
    $canvas_config_entity_types = ['content_template', 'page_region'];
    foreach ($canvas_config_entity_types as $entity_type_id) {
      $definition = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
      if ($definition && $definition->hasLinkTemplate('config-translation-overview')) {
        $options[$entity_type_id] = $definition->getLabel();
      }
    }

    asort($options);
    return $options;
  }

  /**
   * Returns translatable bundle options for entity types with multiple bundles.
   *
   * Returns empty array when no filtering is needed (config entities, single
   * bundle, or entity type without a bundle entity type).
   */
  public function getTranslatableBundles(string $entity_type_id): array {
    $definition = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    if (!$definition || $definition instanceof ConfigEntityTypeInterface) {
      return [];
    }

    $bundle_entity_type = $definition->getBundleEntityType();
    if (!$bundle_entity_type) {
      return [];
    }

    $bundles = $this->entityTypeManager->getStorage($bundle_entity_type)->loadMultiple();
    $options = [];
    foreach ($bundles as $bundle_id => $bundle_entity) {
      if ($this->contentTranslationManager->isEnabled($entity_type_id, $bundle_id)) {
        $options[$bundle_id] = $bundle_entity->label();
      }
    }

    // Only show dropdown when multiple translatable bundles exist.
    return count($options) > 1 ? $options : [];
  }

  /**
   * Returns non-default configurable languages sorted alphabetically by name.
   */
  public function getLanguageOptions(): array {
    $default_langcode = $this->languageManager->getDefaultLanguage()->getId();
    $languages = $this->languageManager->getLanguages(LanguageInterface::STATE_CONFIGURABLE);

    $options = [];
    foreach ($languages as $langcode => $language) {
      if ($langcode !== $default_langcode) {
        $options[$langcode] = $language->getName();
      }
    }

    asort($options);
    return $options;
  }

}
