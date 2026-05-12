<?php

namespace Drupal\canvas\Tmgmt;

use Drupal\canvas\ComponentSource\ComponentSourceInterface;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\ComponentInterface;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaType;
use Drupal\canvas\Plugin\Canvas\ComponentSource\BlockComponent;
use Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase;
use Drupal\canvas\PropExpressions\Component\ComponentPropExpression;
use Drupal\Core\Render\Element;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\tmgmt_config\DefaultConfigProcessor;

class InputsConfigProcessor extends DefaultConfigProcessor {

  public function extractTranslatables($schema, $config_data, $base_key = '') {
    if (!$schema instanceof TypedDataInterface || $schema->getDataDefinition()->getDataType() !== 'canvas.component_tree_node') {
      return parent::extractTranslatables($schema, $config_data, $base_key);
    }
    $translatables = parent::extractTranslatables($schema, $config_data, $base_key);

    \assert(\array_keys($translatables) === ['inputs']);
    \assert(\is_array($config_data) && isset($config_data['component_id']));
    \assert(isset($config_data['uuid']) && \is_string($config_data['uuid']));

    $component_id = $config_data['component_id'];
    $component = Component::load($component_id);
    \assert($component instanceof ComponentInterface);
    $source = $component->getComponentSource();
    \assert($source instanceof ComponentSourceInterface);

    if ($source instanceof BlockComponent) {
      // For block components, inputs is an array. Use the block's config schema
      // to find translatable string settings.
      $inputs = $config_data['inputs'];
      if (!\is_array($inputs)) {
        $inputs = json_decode($inputs, TRUE);
      }

      $block_plugin_id = $source->getSourceSpecificComponentId();
      /** @var \Drupal\Core\Config\TypedConfigManagerInterface $typed_config_manager */
      $typed_config_manager = \Drupal::service('config.typed');
      $schema_name = 'block.settings.' . $block_plugin_id;

      // Create typed data to traverse the schema.
      $typed_data = $typed_config_manager->createFromNameAndData($schema_name, $inputs);

      // Find string settings that are translatable.
      $string_setting_names = [];
      foreach ($typed_data as $key => $element) {
        $definition = $element->getDataDefinition();
        $type = $definition->getDataType();
        // Check if it's a string type and translatable.
        if (\in_array($type, ['string', 'text', 'label'], TRUE) && !empty($definition['translatable'])) {
          $string_setting_names[] = $key;
        }
      }

      // Build translatables for string settings.
      $new_translatables = $translatables;
      unset($new_translatables['inputs']['#text']);
      unset($new_translatables['inputs']['#translate']);
      $new_translatables['inputs']['#original_inputs'] = $inputs;

      foreach ($string_setting_names as $setting_name) {
        $value = $inputs[$setting_name] ?? '';
        if (\is_string($value) && $value !== '') {
          $new_translatables['inputs'][$setting_name] = [
            '#label' => $setting_name,
            '#text' => $value,
            '#translate' => TRUE,
          ];
        }
      }

      return $new_translatables;
    }
    // Only GeneratedFieldExplicitInputUxComponentSourceBase sources have
    // prop shapes we can analyze for string types.
    if (!$source instanceof GeneratedFieldExplicitInputUxComponentSourceBase) {
      return $translatables;
    }

    // SDC components store inputs as JSON strings.
    if (!\is_string($config_data['inputs'] ?? NULL)) {
      return $translatables;
    }
    $inputs = json_decode($config_data['inputs'], TRUE);

    // Get prop shapes for the component to determine which props are strings.
    $prop_shapes = GeneratedFieldExplicitInputUxComponentSourceBase::getComponentInputsForMetadata(
      $source->getSourceSpecificComponentId(),
      $source->getMetadata()
    );

    // Filter to only string type props.
    $string_prop_names = [];
    foreach ($prop_shapes as $cpe_string => $prop_shape) {
      if ($prop_shape->getType() === JsonSchemaType::String) {
        if (!\is_array($prop_shape->resolvedSchema) || isset($prop_shape->resolvedSchema['enum'])) {
          continue;
        }
        $cpe = ComponentPropExpression::fromString($cpe_string);
        $string_prop_names[] = $cpe->propName;
      }
    }

    // Now $string_prop_names contains all props of type: string
    // Filter $inputs to only those props.
    $string_inputs = array_intersect_key($inputs, array_flip($string_prop_names));

    // Build the new translatables structure with individual string props.
    $new_translatables = $translatables;
    unset($new_translatables['inputs']['#text']);
    unset($new_translatables['inputs']['#translate']);
    // Store original inputs for re-encoding in convertToTranslation.
    $new_translatables['inputs']['#original_inputs'] = $inputs;

    foreach ($string_inputs as $prop_name => $string_input) {
      if (\is_string($string_input) && $string_input !== '') {
        $new_translatables['inputs'][$prop_name] = [
          '#label' => $prop_name,
          '#text' => $string_input,
          '#translate' => TRUE,
        ];
      }
    }

    return $new_translatables;

  }

  public function convertToTranslation($data) {
    $children = Element::children($data);
    if ($children) {
      $translation = [];
      foreach ($children as $name) {
        $property_data = $data[$name];
        // Check if this is an 'inputs' element with nested string props.
        if ($name === 'inputs' && isset($property_data['#original_inputs'])) {
          $translated_inputs = [];

          // Merge translated values into the original inputs.
          foreach (Element::children($property_data) as $prop_name) {
            if (isset($property_data[$prop_name]['#translation']['#text'])) {
              $translated_inputs[$prop_name] = $property_data[$prop_name]['#translation']['#text'];
            }
          }

          $translation[$name] = json_encode($translated_inputs, JSON_UNESCAPED_UNICODE);
        }
        else {
          $translation[$name] = $this->convertToTranslation($property_data);
        }
      }
      return $translation;
    }
    elseif (isset($data['#translation']['#text'])) {
      return $data['#translation']['#text'];
    }
    return NULL;
  }

}
