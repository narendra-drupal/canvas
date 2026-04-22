<?php

declare(strict_types=1);

namespace Drupal\canvas\Tmgmt;

use Drupal\canvas\Plugin\DataType\ComponentInputs;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\Core\Render\Element;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\tmgmt_content\FieldProcessorInterface;

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
final class ComponentTreeFieldProcessor implements FieldProcessorInterface {

  /**
   * {@inheritdoc}
   */
  public function extractTranslatableData(FieldItemListInterface $field): array {
    $data = [];
    $data['#label'] = $field->getFieldDefinition()->getLabel();

    foreach ($field as $delta => $item) {
      \assert($item instanceof ComponentTreeItem);

      $inputs_typed_data = $item->get('inputs');
      \assert($inputs_typed_data instanceof ComponentInputs);

      try {
        $actual_inputs = $item->getInputs() ?? [];
      }
      catch (\Exception) {
        continue;
      }

      $mapping = ComponentInputs::resolveConfigSchemaMapping(
        $item->getComponentId(),
        $item->getComponentVersion(),
        $actual_inputs,
      );

      if (empty($mapping)) {
        continue;
      }

      $component = $item->getComponent();
      $component_label = $component?->label() ?? $item->getComponentId();
      $has_delta_data = FALSE;

      foreach ($mapping as $prop_name => $schema_def) {
        // resolveConfigSchemaMapping() already calls refineForInstance() which
        // strips 'translatable' from non-static-prop-source entries.
        if (empty($schema_def['translatable']) || !\array_key_exists($prop_name, $actual_inputs)) {
          continue;
        }

        $value = $actual_inputs[$prop_name];
        $text = $this->extractTextValue($value);
        if ($text === NULL) {
          continue;
        }

        if (!$has_delta_data) {
          $data[$delta]['#label'] = $component_label . ' (' . \substr($item->getUuid(), 0, 8) . ')';
          $has_delta_data = TRUE;
        }

        $prop_label = $schema_def['label'] ?? $prop_name;
        $element = [
          '#label' => $prop_label,
          '#text' => $text,
          '#translate' => TRUE,
        ];

        if (\is_array($value) && isset($value['format'])) {
          $element['#format'] = $value['format'];
        }

        $data[$delta][$prop_name] = $element;
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

      try {
        $inputs = $item->getInputs() ?? [];
      }
      catch (\Exception) {
        continue;
      }

      $changed = FALSE;
      foreach (Element::children($item_data) as $prop_key) {
        $prop_data = $item_data[$prop_key];
        if (empty($prop_data['#translate']) || !isset($prop_data['#translation']['#text'])) {
          continue;
        }

        $translated_text = $prop_data['#translation']['#text'];

        if (\array_key_exists($prop_key, $inputs) && \is_array($inputs[$prop_key]) && isset($inputs[$prop_key]['value'])) {
          $inputs[$prop_key]['value'] = $translated_text;
        }
        else {
          $inputs[$prop_key] = $translated_text;
        }
        $changed = TRUE;
      }

      if ($changed) {
        $item->setInput($inputs);
      }
    }
  }

  /**
   * Extracts a plain text value from a component input.
   *
   * Handles collapsed StaticPropSource (plain string), text format arrays
   * (['value' => ..., 'format' => ...]), and link arrays (['uri' => ...]).
   */
  private function extractTextValue(mixed $value): ?string {
    if (\is_string($value)) {
      return $value;
    }
    if (\is_array($value)) {
      if (isset($value['value']) && \is_string($value['value'])) {
        return $value['value'];
      }
      if (isset($value['uri']) && \is_string($value['uri'])) {
        return $value['uri'];
      }
    }
    return NULL;
  }

}
