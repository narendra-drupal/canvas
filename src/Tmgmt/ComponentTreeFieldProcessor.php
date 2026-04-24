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
        $field_data = parent::extractTranslatableData($source_input->fieldItemList);
        $field_deltas = Element::children($field_data);
        if (empty($field_deltas)) {
          continue;
        }

        if (!$has_delta_data) {
          $data[$delta]['#label'] = $component_label . ' (' . \substr($item->getUuid(), 0, 8) . ')';
          $has_delta_data = TRUE;
        }

        // Collapse the nesting from parent::extractTranslatableData() so
        // TMGMT's review form groups all props under one component header.
        // The form calculates grouping by stripping the last key segment, so
        // single-translatable-property props must have #text directly on the
        // prop key.
        if (\count($field_deltas) === 1) {
          $delta_data = $field_data[\reset($field_deltas)];
          $properties = Element::children($delta_data);
          // Only count translatable properties: handleFormat() leaves a
          // non-translatable `format` child alongside `value`.
          $translatable = \array_filter(
            $properties,
            fn ($key) => !empty($delta_data[$key]['#translate']),
          );

          if (\count($translatable) === 1) {
            // Single translatable property (e.g. string, text_long): promote
            // #text directly onto the prop so flat key is "delta|prop_name".
            $prop_entry = $delta_data[\reset($translatable)];
            $prop_entry['#label'] = $prop_name;
            $data[$delta][$prop_name] = $prop_entry;
            continue;
          }

          // Multiple translatable properties (e.g. link with uri + title):
          // collapse delta but keep properties as children.
          $field_data = \array_filter($field_data, fn ($key) => \is_string($key) && \str_starts_with($key, '#'), \ARRAY_FILTER_USE_KEY) + $delta_data;
        }

        // Restore URI translatability: LinkFieldProcessor marks URIs as
        // non-translatable, but Canvas treats static URIs as translatable.
        if (isset($field_data['uri'])) {
          $field_data['uri']['#translate'] = TRUE;
        }
        else {
          foreach (Element::children($field_data) as $field_delta) {
            if (isset($field_data[$field_delta]['uri'])) {
              $field_data[$field_delta]['uri']['#translate'] = TRUE;
            }
          }
        }

        $field_data['#label'] = $prop_name;
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

        // Re-wrap the collapsed structure to match what parent expects:
        // [delta => [property => ['#translation' => ...]]].
        $prop_children = Element::children($prop_data);
        if (empty($prop_children) && isset($prop_data['#text'])) {
          // Fully collapsed single-property: wrap in delta + property.
          $main_property = $source_input->fieldItemList->getItemDefinition()->getMainPropertyName();
          $wrapped = [0 => [$main_property => $prop_data]];
        }
        elseif (!empty($prop_children) && !\is_numeric(\reset($prop_children))) {
          // Delta-collapsed multi-property: wrap in delta.
          $wrapped = [0 => $prop_data];
        }
        else {
          // Not collapsed (multi-cardinality): pass as-is.
          $wrapped = $prop_data;
        }
        parent::setTranslations($wrapped, $source_input->fieldItemList);
        $inputs[$prop_key] = $source_input->getValue();
        $changed = TRUE;
      }

      if ($changed) {
        $item->setInput($inputs);
      }
    }
  }

}
