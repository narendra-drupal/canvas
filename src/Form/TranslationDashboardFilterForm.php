<?php

declare(strict_types=1);

namespace Drupal\canvas\Form;

use Drupal\content_translation\ContentTranslationManagerInterface;
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
      '#attributes' => ['class' => ['views-exposed-form']],
    ];

    $entity_type_options = $this->getTranslatableEntityTypes();
    $form['filters']['entity_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity type'),
      '#options' => $entity_type_options,
      '#default_value' => \array_key_exists($entity_type, $entity_type_options) ? $entity_type : array_key_first($entity_type_options),
      '#required' => TRUE,
    ];

    $bundle_options = $this->getTranslatableBundles($entity_type);
    if (!empty($bundle_options)) {
      $form['filters']['bundle'] = [
        '#type' => 'select',
        '#title' => $this->t('Bundle'),
        '#options' => $bundle_options,
        '#default_value' => $bundle,
        '#empty_option' => $this->t('- All -'),
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
    ];

    $form['filters']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply'),
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
   * Returns translatable entity type options, ordered as defined.
   */
  public function getTranslatableEntityTypes(): array {
    $candidates = [
      'node' => $this->t('Node'),
      'taxonomy_term' => $this->t('Taxonomy Term'),
      'media' => $this->t('Media'),
      'canvas_page' => $this->t('Canvas Page'),
      'content_template' => $this->t('Canvas Content Template'),
      'page_region' => $this->t('Canvas Global Regions'),
    ];

    $config_entity_types = ['content_template', 'page_region'];
    $options = [];

    foreach ($candidates as $entity_type_id => $label) {
      $definition = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
      if (!$definition) {
        continue;
      }

      if (\in_array($entity_type_id, $config_entity_types, TRUE)) {
        $options[$entity_type_id] = $label;
        continue;
      }

      $bundle_entity_type = $definition->getBundleEntityType();
      if ($bundle_entity_type) {
        $bundle_ids = \array_keys($this->entityTypeManager->getStorage($bundle_entity_type)->loadMultiple());
        foreach ($bundle_ids as $bundle_id) {
          if ($this->contentTranslationManager->isEnabled($entity_type_id, $bundle_id)) {
            $options[$entity_type_id] = $label;
            break;
          }
        }
      }
      else {
        // No bundle entity type — uses entity type ID as bundle.
        if ($this->contentTranslationManager->isEnabled($entity_type_id, $entity_type_id)) {
          $options[$entity_type_id] = $label;
        }
      }
    }

    return $options;
  }

  /**
   * Returns translatable bundle options for entity types with multiple bundles.
   *
   * Returns empty array when no filtering is needed (config entities, single
   * bundle, or entity type without a bundle entity type).
   */
  public function getTranslatableBundles(string $entity_type_id): array {
    $config_entity_types = ['content_template', 'page_region'];
    if (\in_array($entity_type_id, $config_entity_types, TRUE)) {
      return [];
    }

    $definition = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    if (!$definition) {
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
