<?php

declare(strict_types=1);

namespace Drupal\canvas\Tmgmt;

use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\PropSource\PropSource;
use Drupal\canvas\PropSource\StaticPropSource;
use Drupal\Core\Field\TypedData\FieldItemDataDefinitionInterface;
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

        // Restore URI translatability BEFORE collapsing: LinkFieldProcessor
        // marks URIs non-translatable, but Canvas treats static URIs as
        // translatable. Restoring first ensures link fields (uri + title)
        // have 2 translatable properties and won't be incorrectly collapsed.
        foreach ($field_deltas as $fd) {
          if (isset($field_data[$fd]['uri'])) {
            $field_data[$fd]['uri']['#translate'] = TRUE;
          }
        }

        // Collapse single-translatable-property within each field delta so
        // TMGMT's review form groups items correctly. The form calculates
        // grouping by stripping the last key segment from flattened keys.
        // handleFormat() leaves a non-translatable `format` child alongside
        // `value`, so we count only translatable properties.
        foreach ($field_deltas as $fd) {
          $fd_data = $field_data[$fd];
          $properties = Element::children($fd_data);
          $translatable = \array_filter(
            $properties,
            fn ($key) => !empty($fd_data[$key]['#translate'])
              && isset($fd_data[$key]['#text'])
              && $fd_data[$key]['#text'] !== '',
          );
          if (\count($translatable) === 1) {
            $prop_entry = $fd_data[\reset($translatable)];
            if (isset($fd_data['#label'])) {
              $prop_entry['#label'] = $fd_data['#label'];
            }
            $field_data[$fd] = $prop_entry;
          }
        }

        // For single-cardinality, additionally collapse the delta level.
        if (\count($field_deltas) === 1) {
          $only_fd = \reset($field_deltas);
          $field_data = \array_filter(
            $field_data,
            fn ($key) => \is_string($key) && \str_starts_with($key, '#'),
            \ARRAY_FILTER_USE_KEY,
          ) + $field_data[$only_fd];
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
        $wrapped = $this->rewrapForParentSetTranslations($prop_data, $source_input);
        parent::setTranslations($wrapped, $source_input->fieldItemList);
        $inputs[$prop_key] = $source_input->getValue();
        $changed = TRUE;
      }

      if ($changed) {
        $item->setInput($inputs);
      }
    }
  }

  /**
   * Re-wraps collapsed prop data for parent::setTranslations().
   *
   * The parent expects [delta => [property => ['#translation' => ...]]].
   */
  private function rewrapForParentSetTranslations(array $data, StaticPropSource $source): array {
    $children = Element::children($data);

    $field_item_definition = $source->fieldItemList->getItemDefinition();
    \assert($field_item_definition instanceof FieldItemDataDefinitionInterface);
    if (empty($children) && isset($data['#text'])) {
      // Fully collapsed leaf: wrap in delta + property.
      $main_property = $field_item_definition->getMainPropertyName();
      return [0 => [$main_property => $data]];
    }

    if (!empty($children) && \is_numeric(\reset($children))) {
      // Numeric children = deltas. Check if each delta is also collapsed.
      foreach ($children as $fd) {
        $fd_children = Element::children($data[$fd]);
        if (empty($fd_children) && isset($data[$fd]['#text'])) {
          $main_property ??= $field_item_definition->getMainPropertyName();
          $data[$fd] = [$main_property => $data[$fd]];
        }
      }
      return $data;
    }

    // Non-numeric children = properties (delta-collapsed multi-property).
    return [0 => $data];
  }

}
