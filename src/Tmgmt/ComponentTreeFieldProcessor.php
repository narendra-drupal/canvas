<?php

declare(strict_types=1);

namespace Drupal\canvas\Tmgmt;

use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\PropSource\PropSource;
use Drupal\canvas\PropSource\StaticPropSource;
use Drupal\Core\Render\Element;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\tmgmt_content\LinkFieldProcessor;

/**
 * TMGMT field processor for component_tree fields.
 *
 * Extracts each translatable prop of each component instance as a separate
 * translatable string in the TMGMT review form.
 *
 * @see \Drupal\canvas\Plugin\DataType\ComponentInputs::getTranslatableInputKeys()
 * @see \Drupal\canvas\Plugin\DataType\ComponentInputs::resolveConfigSchemaMapping()
 * @see https://www.drupal.org/project/canvas/issues/3583684
 */
final class ComponentTreeFieldProcessor extends LinkFieldProcessor {

  /**
   * {@inheritdoc}
   */
  public function extractTranslatableData(FieldItemListInterface $field): array {
    $data = [];
    $data['#label'] = $field->getFieldDefinition()->getLabel();

    foreach ($field as $delta => $item) {
      \assert($item instanceof ComponentTreeItem);

      $translatable_inputs = $item->get('inputs')->getTranslatableInputKeys();
      if (empty($translatable_inputs)) {
        continue;
      }

      $component = $item->getComponent();
      if ($component === NULL) {
        continue;
      }
      $component_label = $component->label() ?? $item->getComponentId();
      $has_delta_data = FALSE;

      $explicit_input = $component->getComponentSource()->getExplicitInput($item->getUuid(), $item);

      foreach ($translatable_inputs as $prop_name) {
        if (!isset($explicit_input['source'][$prop_name])) {
          continue;
        }
        $source_input = PropSource::parse($explicit_input['source'][$prop_name]);
        if (!$source_input instanceof StaticPropSource) {
          continue;
        }

        // Reuse TMGMT's extraction heuristics (shouldTranslateProperty()).
        // LinkFieldProcessor marks URI properties as non-translatable, but
        // Canvas treats static URIs as translatable.
        $field_data = parent::extractTranslatableData($source_input->fieldItemList);
        if (empty(Element::children($field_data))) {
          continue;
        }

        // Restore URI translatability for StaticPropSource fields (no entity
        // root means this is a static value, not a field on an entity).
        foreach (Element::children($field_data) as $field_delta) {
          if (isset($field_data[$field_delta]['uri'])) {
            $field_data[$field_delta]['uri']['#translate'] = TRUE;
          }
        }

        $field_data['#label'] = $prop_name;

        if (!$has_delta_data) {
          $data[$delta]['#label'] = $component_label . ' (' . \substr($item->getUuid(), 0, 8) . ')';
          $has_delta_data = TRUE;
        }

        $data[$delta][$prop_name] = $field_data;
      }
    }

    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function setTranslations($field_data, FieldItemListInterface $field): void {
    foreach (Element::children($field_data) as $delta) {
      $item_data = $field_data[$delta];
      if (!$field->offsetExists($delta)) {
        continue;
      }

      $item = $field->offsetGet($delta);
      \assert($item instanceof ComponentTreeItem);

      $component = $item->getComponent();
      if ($component === NULL) {
        continue;
      }

      $explicit_input = $component->getComponentSource()->getExplicitInput($item->getUuid(), $item);

      try {
        $inputs = $item->getInputs() ?? [];
      }
      catch (\Exception) {
        continue;
      }

      $changed = FALSE;
      foreach (Element::children($item_data) as $prop_key) {
        $prop_data = $item_data[$prop_key];

        if (!isset($explicit_input['source'][$prop_key])) {
          continue;
        }
        $source_input = PropSource::parse($explicit_input['source'][$prop_key]);
        if (!$source_input instanceof StaticPropSource) {
          continue;
        }

        parent::setTranslations($prop_data, $source_input->fieldItemList);
        $inputs[$prop_key] = $source_input->getValue();
        $changed = TRUE;
      }

      if ($changed) {
        $item->setInput($inputs);
      }
    }
  }

}
