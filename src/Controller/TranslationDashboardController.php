<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\language\ConfigurableLanguageManagerInterface;

/**
 * Translation dashboard showing Canvas entities and their translation status.
 */
final class TranslationDashboardController extends ControllerBase {

  public function overview(string $entity_type = 'canvas_page'): array {
    $languages = $this->languageManager()->getLanguages(LanguageInterface::STATE_CONFIGURABLE);
    $default_language = $this->languageManager()->getDefaultLanguage();

    $target_languages = [];
    foreach ($languages as $language) {
      if ($language->getId() !== $default_language->getId()) {
        $target_languages[] = $language;
      }
    }

    $entity_type_definition = $this->entityTypeManager()->getDefinition($entity_type);
    $is_config_entity = $entity_type_definition instanceof ConfigEntityTypeInterface;
    $storage = $this->entityTypeManager()->getStorage($entity_type);

    if ($is_config_entity) {
      $entities = $storage->loadMultiple();
    }
    else {
      $ids = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('status', 1)
        ->sort('created', 'DESC')
        ->range(0, 100)
        ->execute();
      $entities = $storage->loadMultiple($ids);
    }

    $build = [];
    foreach ($entities as $entity) {
      $entity_id = $entity->id();
      $rows = [];
      foreach ($target_languages as $language) {
        $langcode = $language->getId();
        $status = $this->getTranslationStatus($entity, $entity_type, $langcode, $is_config_entity);

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

        $rows[] = [
          $language->getName(),
          $status_label,
          ['data' => Link::fromTextAndUrl($action_label, $translate_url)->toRenderable()],
        ];
      }

      $build[$entity_id] = [
        'heading' => [
          '#type' => 'html_tag',
          '#tag' => 'h3',
          '#value' => $entity->label() ?: $entity_id,
        ],
        'table' => [
          '#type' => 'table',
          '#header' => [$this->t('Language'), $this->t('Status'), $this->t('Action')],
          '#rows' => $rows,
        ],
      ];
    }

    if (empty($build)) {
      $build['empty'] = [
        '#markup' => '<p>' . $this->t('No @type entities found.', ['@type' => $entity_type_definition->getLabel()]) . '</p>',
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
