<?php

declare(strict_types=1);

namespace Drupal\canvas\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * HTTP API for language information.
 *
 * @internal This HTTP API is intended only for the Canvas UI. These controllers
 *   and associated routes may change at any time.
 */
final class ApiLanguageController {

  public function __construct(
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Returns a list of configurable languages set up on the site.
   */
  public function list(): CacheableJsonResponse {
    // STATE_CONFIGURABLE excludes locked system placeholders (und/zxx) and
    // returns only languages visible at /admin/config/regional/language —
    // the only languages with URL prefixes a user can preview content in.
    // @see \Drupal\language\ConfigurableLanguageManager::isMultilingual()
    $languages = $this->languageManager->getLanguages(LanguageInterface::STATE_CONFIGURABLE);

    $data = [];
    foreach ($languages as $language) {
      $data[] = [
        'id' => $language->getId(),
        'name' => $language->getName(),
        'direction' => $language->getDirection(),
        'isDefault' => $language->isDefault(),
      ];
    }

    $cacheability = new CacheableMetadata();
    $cacheability->addCacheTags(['config:configurable_language_list']);
    $response = new CacheableJsonResponse($data);
    $response->addCacheableDependency($cacheability);
    return $response;
  }

}
