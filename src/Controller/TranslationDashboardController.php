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
        $has_translation = $this->entityHasTranslation($entity, $entity_type, $langcode, $is_config_entity);

        $translate_url = Url::fromRoute('canvas.translate_entity', [
          'entity_type' => $entity_type,
          'entity_id' => $entity_id,
          'target_language' => $langcode,
        ]);

        $rows[] = [
          $language->getName(),
          $has_translation ? $this->t('Translated') : $this->t('Not translated'),
          ['data' => Link::fromTextAndUrl($has_translation ? $this->t('Edit') : $this->t('Translate'), $translate_url)->toRenderable()],
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

  private function entityHasTranslation(object $entity, string $entity_type, string $langcode, bool $is_config_entity): bool {
    if (!$is_config_entity && $entity instanceof TranslatableInterface) {
      return $entity->hasTranslation($langcode);
    }
    if ($is_config_entity) {
      $language_manager = \Drupal::service(LanguageManagerInterface::class);
      if ($language_manager instanceof ConfigurableLanguageManagerInterface) {
        $override = $language_manager->getLanguageConfigOverride($langcode, $entity->getConfigDependencyName());
        return !$override->isNew();
      }
    }
    return FALSE;
  }

}
