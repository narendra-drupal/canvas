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

    // Build header: Entity label + one column per non-default language.
    $header = [$this->t('Name')];
    $target_languages = [];
    foreach ($languages as $language) {
      if ($language->getId() !== $default_language->getId()) {
        $header[] = $language->getName();
        $target_languages[] = $language;
      }
    }

    $entity_type_definition = $this->entityTypeManager()->getDefinition($entity_type);
    $is_config_entity = $entity_type_definition instanceof ConfigEntityTypeInterface;
    $storage = $this->entityTypeManager()->getStorage($entity_type);

    // Load entities.
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

    $rows = [];
    foreach ($entities as $entity) {
      $row = [$entity->label() ?: $entity->id()];

      foreach ($target_languages as $language) {
        $langcode = $language->getId();
        $has_translation = $this->entityHasTranslation($entity, $entity_type, $langcode, $is_config_entity);

        $translate_url = Url::fromRoute('canvas.translate_entity', [
          'entity_type' => $entity_type,
          'entity_id' => $entity->id(),
          'target_language' => $langcode,
        ]);

        if ($has_translation) {
          $row[] = ['data' => Link::fromTextAndUrl('✓ Edit', $translate_url)->toRenderable()];
        }
        else {
          $row[] = ['data' => Link::fromTextAndUrl('Translate', $translate_url)->toRenderable()];
        }
      }

      $rows[] = $row;
    }

    return [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No @type entities found.', ['@type' => $entity_type_definition->getLabel()]),
    ];
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
