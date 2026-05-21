<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\Core\Config\Entity\ConfigEntityTypeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\language\ConfigurableLanguageManagerInterface;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\JobItemInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Controller that auto-creates TMGMT jobs and redirects to the review form.
 *
 * Abstracts away TMGMT's job/job_item concepts so users just click "Translate"
 * and land on the translation review form.
 */
final class TranslationJobController extends ControllerBase {

  public function translate(string $entity_type, string $entity_id, string $target_language): RedirectResponse {
    $entity = $this->entityTypeManager()->getStorage($entity_type)->load($entity_id);
    if (!$entity) {
      throw new NotFoundHttpException();
    }

    $entity_type_definition = $this->entityTypeManager()->getDefinition($entity_type);
    $is_config_entity = $entity_type_definition instanceof ConfigEntityTypeInterface;

    // Determine TMGMT plugin, item_type, and item_id.
    if ($is_config_entity) {
      $plugin = 'config';
      $item_type = $entity_type;
      $item_id = $entity_type_definition->getConfigPrefix() . '.' . $entity->id();
    }
    else {
      $plugin = 'content';
      $item_type = $entity_type;
      $item_id = (string) $entity->id();
    }

    // Determine source language.
    $source_language = 'en';
    if ($entity instanceof TranslatableInterface && method_exists($entity, 'language')) {
      $source_language = $entity->language()->getId();
    }
    elseif ($is_config_entity) {
      $source_language = $this->languageManager()->getDefaultLanguage()->getId();
    }

    // Case A: Check for existing active job item.
    $existing_item = $this->findActiveJobItem($plugin, $item_type, $item_id, $target_language);
    if ($existing_item) {
      return $this->redirectToJobItem($existing_item);
    }

    // Check if translation already exists.
    $translation_exists = $this->translationExists($entity, $entity_type, $target_language, $is_config_entity);

    // Find or create job.
    $job = $this->findOrCreateJob($source_language, $target_language);

    // Create job item.
    $job_item = $job->addItem($plugin, $item_type, $item_id);

    // Case B: Translation exists — pre-populate with existing data.
    if ($translation_exists) {
      $this->prePopulateJobItem($job_item);
    }

    return $this->redirectToJobItem($job_item);
  }

  private function findActiveJobItem(string $plugin, string $item_type, string $item_id, string $target_language): ?JobItemInterface {
    $storage = $this->entityTypeManager()->getStorage('tmgmt_job_item');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('plugin', $plugin)
      ->condition('item_type', $item_type)
      ->condition('item_id', $item_id)
      ->condition('state', [JobItemInterface::STATE_ACTIVE, JobItemInterface::STATE_REVIEW], 'IN')
      ->execute();

    if (empty($ids)) {
      return NULL;
    }

    // Filter to only items whose job targets the right language.
    $items = $storage->loadMultiple($ids);
    foreach ($items as $item) {
      \assert($item instanceof JobItemInterface);
      if ($item->getJob()->getTargetLangcode() === $target_language) {
        return $item;
      }
    }
    return NULL;
  }

  private function translationExists(object $entity, string $entity_type, string $target_language, bool $is_config_entity): bool {
    if (!$is_config_entity && $entity instanceof TranslatableInterface) {
      return $entity->hasTranslation($target_language);
    }
    if ($is_config_entity) {
      $language_manager = \Drupal::service(LanguageManagerInterface::class);
      if ($language_manager instanceof ConfigurableLanguageManagerInterface) {
        $override = $language_manager->getLanguageConfigOverride($target_language, $entity->getConfigDependencyName());
        return !$override->isNew();
      }
    }
    return FALSE;
  }

  private function findOrCreateJob(string $source_language, string $target_language): Job {
    $storage = $this->entityTypeManager()->getStorage('tmgmt_job');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('source_language', $source_language)
      ->condition('target_language', $target_language)
      ->condition('state', [Job::STATE_ACTIVE, Job::STATE_CONTINUOUS], 'IN')
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->execute();

    if (!empty($ids)) {
      $job = $storage->load(reset($ids));
      \assert($job instanceof Job);
      return $job;
    }

    // Create a new job.
    $job = tmgmt_job_create($source_language, $target_language, (int) $this->currentUser()->id());
    $job->set('label', "Canvas translations ($source_language → $target_language)");
    $job->save();
    // Set to active state so job items can be reviewed.
    $job->setState(Job::STATE_ACTIVE);
    return $job;
  }

  private function prePopulateJobItem(JobItemInterface $job_item): void {
    // Get source data from the plugin.
    $source_data = $job_item->getData();
    // Copy source values as translations to show existing translation.
    $translated = $this->extractTranslatedValues($source_data);
    if (!empty($translated)) {
      $job_item->addTranslatedData($translated);
    }
  }

  private function extractTranslatedValues(array $data): array {
    $translated = [];
    foreach ($data as $key => $value) {
      if (is_array($value)) {
        if (isset($value['#text'])) {
          $translated[$key] = $value;
        }
        else {
          $result = $this->extractTranslatedValues($value);
          if (!empty($result)) {
            $translated[$key] = $result;
          }
        }
      }
    }
    return $translated;
  }

  private function redirectToJobItem(JobItemInterface $job_item): RedirectResponse {
    $url = $job_item->toUrl()->setAbsolute()->toString();
    return new RedirectResponse($url);
  }

}
