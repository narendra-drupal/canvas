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
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Controller that auto-creates TMGMT jobs and redirects to the review form.
 *
 * Abstracts away TMGMT's job/job_item concepts so users just click "Translate"
 * and land on the translation review form.
 */
final class TranslationJobController extends ControllerBase {

  public function translate(string $entity_type, string $entity_id, string $target_language): RedirectResponse|array {
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

    // Case A: Check for existing active/review job item.
    $existing_item = $this->findActiveJobItem($plugin, $item_type, $item_id, $target_language);
    if ($existing_item) {
      // If the item is stale (no user progress saved), discard and create fresh.
      if ($this->isStaleWithNoProgress($existing_item)) {
        $existing_item->delete();
      }
      else {
        return $this->redirectToJobItem($existing_item, $entity_type, $entity_id);
      }
    }

    // Check if translation already exists.
    $translation_exists = $this->translationExists($entity, $entity_type, $target_language, $is_config_entity);

    // Case B: Translation exists, no active job item.
    if ($translation_exists) {
      $is_outdated = $this->translationIsOutdated($entity, $target_language, $is_config_entity);

      // If outdated, skip read-only view — go straight to editable form.
      // hook_entity_prepare_form will pre-populate with existing translation.
      // If user abandons, Fix 3 discards the stale item next time.
      if (!$is_outdated) {
        $accepted_item = $this->findAcceptedJobItem($plugin, $item_type, $item_id, $target_language);
        if ($accepted_item) {
          $update_url = Url::fromRoute('canvas.update_translation', [
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'target_language' => $target_language,
          ])->toString();
          \Drupal::messenger()->addMessage($this->t('This translation has already been accepted. <a href="@url">Click here to update it</a>.', [
            '@url' => $update_url,
          ]));
          return $this->redirectToJobItem($accepted_item, $entity_type, $entity_id);
        }
        // No accepted job item found (translation created outside TMGMT).
        return $this->translationExistsPage($entity, $entity_type, $entity_id, $target_language);
      }
    }

    // Case C: No translation exists — create fresh job item.
    $job = $this->findOrCreateJob($source_language, $target_language);
    $job_item = $job->addItem($plugin, $item_type, $item_id);
    $job_item->setState(JobItemInterface::STATE_REVIEW);
    $job_item->save();

    return $this->redirectToJobItem($job_item, $entity_type, $entity_id);
  }

  public function updateTranslation(string $entity_type, string $entity_id, string $target_language): RedirectResponse {
    $entity = $this->entityTypeManager()->getStorage($entity_type)->load($entity_id);
    if (!$entity) {
      throw new NotFoundHttpException();
    }

    $entity_type_definition = $this->entityTypeManager()->getDefinition($entity_type);
    $is_config_entity = $entity_type_definition instanceof ConfigEntityTypeInterface;

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

    $source_language = 'en';
    if ($entity instanceof TranslatableInterface && method_exists($entity, 'language')) {
      $source_language = $entity->language()->getId();
    }
    elseif ($is_config_entity) {
      $source_language = $this->languageManager()->getDefaultLanguage()->getId();
    }

    $job = $this->findOrCreateJob($source_language, $target_language);
    $job_item = $job->addItem($plugin, $item_type, $item_id);
    $job_item->setState(JobItemInterface::STATE_REVIEW);
    $job_item->save();

    return $this->redirectToJobItem($job_item, $entity_type, $entity_id);
  }

  private function translationExistsPage(object $entity, string $entity_type, string $entity_id, string $target_language): array {
    $language = $this->languageManager()->getLanguage($target_language);
    $language_name = $language ? $language->getName() : $target_language;

    return [
      '#type' => 'container',
      'message' => [
        '#markup' => '<p>' . $this->t('A @language translation already exists for <strong>@title</strong>.', [
          '@language' => $language_name,
          '@title' => method_exists($entity, 'label') ? $entity->label() : $entity_id,
        ]) . '</p>',
      ],
      'actions' => [
        '#type' => 'actions',
        'update' => [
          '#type' => 'link',
          '#title' => $this->t('Update Translation'),
          '#url' => Url::fromRoute('canvas.update_translation', [
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'target_language' => $target_language,
          ]),
          '#attributes' => [
            'class' => ['button', 'button--primary'],
          ],
        ],
      ],
    ];
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

  private function findAcceptedJobItem(string $plugin, string $item_type, string $item_id, string $target_language): ?JobItemInterface {
    $storage = $this->entityTypeManager()->getStorage('tmgmt_job_item');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('plugin', $plugin)
      ->condition('item_type', $item_type)
      ->condition('item_id', $item_id)
      ->condition('state', JobItemInterface::STATE_ACCEPTED)
      ->sort('changed', 'DESC')
      ->range(0, 5)
      ->execute();

    if (empty($ids)) {
      return NULL;
    }

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

  private function translationIsOutdated(object $entity, string $target_language, bool $is_config_entity): bool {
    if ($is_config_entity) {
      return FALSE;
    }
    if (!$entity instanceof TranslatableInterface || !$entity->hasTranslation($target_language)) {
      return FALSE;
    }
    $translation = $entity->getTranslation($target_language);
    return (bool) $translation->get('content_translation_outdated')->value;
  }

  private function isStaleWithNoProgress(JobItemInterface $item): bool {
    /** @var \Drupal\tmgmt\Data $data_service */
    $data_service = \Drupal::service('tmgmt.data');
    $data = $item->getData();
    $translatable_items = $data_service->filterTranslatable($data);
    foreach ($translatable_items as $flat_item) {
      if (!empty($flat_item['#translation']['#text'])) {
        return FALSE;
      }
    }
    return TRUE;
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

    // Create a new job with the first available translator.
    $job = tmgmt_job_create($source_language, $target_language, (int) $this->currentUser()->id());
    $job->set('label', "Canvas translations ($source_language → $target_language)");

    // Assign the first available translator (e.g., "local" = Drupal user).
    $translator_ids = $this->entityTypeManager()->getStorage('tmgmt_translator')
      ->getQuery()
      ->accessCheck(FALSE)
      ->execute();
    if (empty($translator_ids)) {
      throw new \RuntimeException('No TMGMT translator available. Create one at /admin/tmgmt/translators.');
    }
    $job->set('translator', reset($translator_ids));

    $job->save();
    $job->setState(Job::STATE_ACTIVE);
    return $job;
  }


  private function redirectToJobItem(JobItemInterface $job_item, string $entity_type = 'canvas_page', string $entity_id = ''): RedirectResponse {
    $request = \Drupal::request();
    $origin = $request->query->get('origin');

    if ($origin === 'canvas' && $entity_id) {
      $destination = "/canvas/editor/{$entity_type}/{$entity_id}";
    }
    else {
      $route_map = [
        'canvas_page' => 'canvas.translation_dashboard',
        'content_template' => 'canvas.translation_dashboard.content_template',
        'page_region' => 'canvas.translation_dashboard.page_region',
      ];
      $route = $route_map[$entity_type] ?? 'canvas.translation_dashboard';
      $destination = Url::fromRoute($route)->toString();
    }

    $url = $job_item->toUrl()->setOption('query', ['destination' => $destination])->setAbsolute()->toString();
    return new RedirectResponse($url);
  }

}
