<?php

declare(strict_types=1);

namespace Drupal\canvas\Hook;

use Drupal\canvas\Plugin\DataType\ComponentInputs;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Storage\ComponentTreeLoader;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for Canvas translation support.
 */
final class TranslationHooks {

  public function __construct(
    private readonly ComponentTreeLoader $componentTreeLoader,
  ) {}

  /**
   * Implements hook_entity_translation_create().
   *
   * For non-default translations, strip non-translatable input keys so that
   * only translatable values are stored per-language. Non-translatable values
   * are merged from the default translation at read time.
   *
   * @see \Drupal\canvas\ComponentSource\ComponentSourceBase::getExplicitInput()
   * @see \Drupal\canvas\ComponentSource\ComponentSourceBase::validateComponentInput()
   * @see https://www.drupal.org/project/canvas/issues/3583684
   */
  #[Hook('entity_translation_create')]
  public function entityTranslationCreate(ContentEntityInterface $translation): void {
    try {
      $component_tree_item_list = $this->componentTreeLoader->load($translation);
    }
    catch (\LogicException) {
      return;
    }
    if (!$component_tree_item_list->isTreeTranslationSynced() || $component_tree_item_list->isInputsTranslationSynced()) {
      return;
    }
    foreach ($component_tree_item_list as $item) {
      \assert($item instanceof ComponentTreeItem);
      $input_values = $item->getInputs();
      if ($input_values === NULL) {
        continue;
      }
      $inputs_typed_data = $item->get('inputs');
      \assert($inputs_typed_data instanceof ComponentInputs);
      $translatable_keys = $inputs_typed_data->getTranslatableInputKeys();
      $item->setInput(\array_intersect_key($input_values, \array_flip($translatable_keys)));
    }
  }

}
