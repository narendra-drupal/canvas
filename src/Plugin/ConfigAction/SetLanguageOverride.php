<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\ConfigAction;

use Drupal\Core\Config\Action\Attribute\ConfigAction;
use Drupal\Core\Config\Action\ConfigActionException;
use Drupal\Core\Config\Action\ConfigActionPluginInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Config action that writes a language config override to the language collection.
 *
 * This is necessary because the Drupal recipe system's RecipeConfigInstaller
 * only processes the default config collection. Files placed in a recipe's
 * config/language/fr/ directory follow the correct Drupal filesystem convention
 * (FileStorage maps collection 'language.fr' to subdirectory 'language/fr/')
 * but are silently ignored by the recipe installer.
 *
 * Usage in a recipe:
 * @code
 * config:
 *   actions:
 *     canvas.page_region.stark.sidebar_first:
 *       setLanguageOverride:
 *         language: fr
 *         data:
 *           component_tree:
 *             uuid-here:
 *               label: 'French label'
 *               inputs:
 *                 text: 'Bonjour'
 * @endcode
 *
 * @internal
 *   This is a test-support config action for use in test recipes only.
 */
#[ConfigAction(
  id: 'setLanguageOverride',
  admin_label: new TranslatableMarkup('Set language config override'),
  entity_types: ['*'],
)]
final class SetLanguageOverride implements ConfigActionPluginInterface, ContainerFactoryPluginInterface {

  public function __construct(
    private readonly LanguageManagerInterface $languageManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $container->get(LanguageManagerInterface::class),
      $container->get(ConfigFactoryInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function apply(string $configName, mixed $value): void {
    if (!is_array($value) || !isset($value['language'], $value['data'])) {
      throw new ConfigActionException(sprintf(
        'setLanguageOverride for %s requires an array with "language" and "data" keys.',
        $configName,
      ));
    }

    $langcode = $value['language'];
    $data = $value['data'];

    if (!is_array($data)) {
      throw new ConfigActionException(sprintf(
        'setLanguageOverride "data" for %s must be an array.',
        $configName,
      ));
    }

    $language = $this->languageManager->getLanguage($langcode);
    if ($language === NULL) {
      throw new ConfigActionException(sprintf(
        'Language "%s" does not exist. Create language.entity.%s before using setLanguageOverride.',
        $langcode,
        $langcode,
      ));
    }

    // Verify the base config exists.
    if ($this->configFactory->get($configName)->isNew()) {
      throw new ConfigActionException(sprintf(
        'Config %s does not exist. Create it before setting a language override.',
        $configName,
      ));
    }

    // Write directly to the language collection storage, exactly as
    // ConfigurableLanguageManager::getLanguageConfigOverride()->setData()->save()
    // would do, but without loading the full override object.
    $override = $this->languageManager->getLanguageConfigOverride($langcode, $configName);
    $override->setData($data)->save();
  }

}

