<?php

declare(strict_types=1);

namespace Drupal\tmgmt_canvas\Controller;

use Drupal\tmgmt_canvas\Form\TranslationDashboardFilterForm;
use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Translation dashboard — single-page view of all translatable entity types.
 */
final class TranslationDashboardController extends ControllerBase {

  public function overview(Request $request): array {
    $form = $this->formBuilder()->getForm(TranslationDashboardFilterForm::class);

    // Resolve effective filter values (defaults match form defaults).
    $entity_type = $request->query->get('entity_type', 'node');
    $bundle = $request->query->get('bundle', '');
    $langcode = $request->query->get('langcode', '');
    $title_filter = $request->query->get('title', '');
    $status_filter = $request->query->get('status', '');

    // If no langcode in URL, use the same default the form uses.
    if (!$langcode) {
      /** @var \Drupal\tmgmt_canvas\Form\TranslationDashboardFilterForm $form_obj */
      $form_obj = \Drupal::classResolver(TranslationDashboardFilterForm::class);
      $language_options = $form_obj->getLanguageOptions();
      $langcode = array_key_first($language_options) ?? '';
    }

    $build = [
      'filters' => $form,
    ];

    if (!$langcode) {
      $build['message'] = [
        '#markup' => '<p>' . $this->t('No target languages are configured.') . '</p>',
      ];
      return $build;
    }

    $entity_type_definition = $this->entityTypeManager()->getDefinition($entity_type, FALSE);
    if (!$entity_type_definition) {
      $build['message'] = [
        '#markup' => '<p>' . $this->t('Unknown entity type.') . '</p>',
      ];
      return $build;
    }

    $is_config_entity = $entity_type_definition instanceof ConfigEntityTypeInterface;
    $storage = $this->entityTypeManager()->getStorage($entity_type);

    if ($is_config_entity) {
      $entities = $storage->loadMultiple();
      // Apply title filter manually for config entities.
      if ($title_filter) {
        $entities = array_filter($entities, function ($entity) use ($title_filter) {
          return str_contains(strtolower((string) ($entity->label() ?? $entity->id())), strtolower($title_filter));
        });
      }
    }
    else {
      $query = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('status', 1)
        ->sort('created', 'DESC')
        ->range(0, 100);

      if ($bundle) {
        $bundle_key = $entity_type_definition->getKey('bundle');
        if ($bundle_key) {
          $query->condition($bundle_key, $bundle);
        }
      }

      if ($title_filter) {
        $label_key = $entity_type_definition->getKey('label');
        if ($label_key) {
          $query->condition($label_key, '%' . $title_filter . '%', 'LIKE');
        }
      }

      $ids = $query->execute();
      $entities = $storage->loadMultiple($ids);
    }

    $rows = [];
    foreach ($entities as $entity) {
      $entity_id = $entity->id();
      $status = $this->getTranslationStatus($entity, $entity_type, $langcode, $is_config_entity);

      if ($status_filter && $status !== $status_filter) {
        continue;
      }

      $translate_url = Url::fromRoute('canvas.translate_entity', [
        'entity_type' => $entity_type,
        'entity_id' => $entity_id,
        'target_language' => $langcode,
      ]);

      $status_label = match ($status) {
        'outdated' => $this->t('Outdated'),
        'translated' => $this->t('Translated'),
        default => $this->t('Not translated'),
      };
      $action_label = $status === 'none' ? $this->t('Translate') : $this->t('Edit');

      $view_link = '';
      if (!$is_config_entity && $entity->hasLinkTemplate('canonical')) {
        $view_links = [
          'default' => Link::fromTextAndUrl($this->t('View default language'), $entity->getUntranslated()->toUrl('canonical'))->toRenderable(),
        ];
        if ($entity instanceof TranslatableInterface && $entity->hasTranslation($langcode)) {
          $language = $this->languageManager()->getLanguage($langcode);
          if ($language) {
            $translation_url = $entity->toUrl('canonical')->setOption('language', $language);
            $translation_link = Link::fromTextAndUrl($this->t('View translation'), $translation_url)->toRenderable();
            $translation_link['#prefix'] = ' | ';
            $view_links['translation'] = $translation_link;
          }
        }
        $view_link = ['data' => $view_links];
      }

      $rows[] = [
        $entity->label() ?: $entity_id,
        $status_label,
        ['data' => Link::fromTextAndUrl($action_label, $translate_url)->toRenderable()],
        $view_link,
      ];
    }

    if (empty($rows)) {
      $build['empty'] = [
        '#markup' => '<p>' . $this->t('No entities found matching the selected filters.') . '</p>',
      ];
    }
    else {
      $build['table'] = [
        '#type' => 'table',
        '#header' => [$this->t('Title'), $this->t('Status'), $this->t('Action'), $this->t('View')],
        '#rows' => $rows,
      ];
    }

    return $build;
  }

  /**
   * Returns 'translated', 'outdated', or 'none'.
   *
   * @todo content_translation_outdated is overly broad — fires on ANY new
   *   revision, even non-translatable changes. May show false "Outdated".
   */
  private function getTranslationStatus(object $entity, string $entity_type, string $langcode, bool $is_config_entity): string {
    if (!$is_config_entity && $entity instanceof TranslatableInterface) {
      if (!$entity->hasTranslation($langcode)) {
        return 'none';
      }
      $translation = $entity->getTranslation($langcode);
      if ($translation->get('content_translation_outdated')->value) {
        return 'outdated';
      }
      return 'translated';
    }
    if ($is_config_entity) {
      $language_manager = \Drupal::service(LanguageManagerInterface::class);
      if ($language_manager instanceof ConfigurableLanguageManagerInterface) {
        $override = $language_manager->getLanguageConfigOverride($langcode, $entity->getConfigDependencyName());
        return $override->isNew() ? 'none' : 'translated';
      }
    }
    return 'none';
  }

}
