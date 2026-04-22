<?php

declare(strict_types=1);

namespace Drupal\canvas\Plugin\Canvas\ComponentSource;

use Drupal\canvas\ComponentSource\ComponentInstanceInputsConfigSchemaGeneratorInterface;
use Drupal\canvas\ComponentSource\ComponentSourceInterface;
use Drupal\canvas\ComponentSource\FallbackComponentInstanceInputsConfigSchemaGenerator;
use Drupal\canvas\ConfigTranslation\CanvasStaticPropSourceFieldWidget;
use Drupal\canvas\JsonSchemaInterpreter\JsonSchemaStringFormat;
use Drupal\canvas\PropShape\PropShape;

/**
 * @internal
 */
final readonly class GeneratedFieldExplicitInputUxComponentInstanceInputsConfigSchemaGenerator implements ComponentInstanceInputsConfigSchemaGeneratorInterface {

  /**
   * {@inheritdoc}
   */
  public function getConfigSchemaMapping(ComponentSourceInterface $component_source): array {
    \assert($component_source instanceof GeneratedFieldExplicitInputUxComponentSourceBase);
    ['required' => $required, 'shapes' => $shapes] = $component_source->getExplicitInputDefinitions();

    $normalized_shapes = \array_map(
      fn (array $raw_json_schema): array => PropShape::normalize(PropShape::standardize($raw_json_schema)->resolvedSchema)->schema,
      $shapes,
    );

    $mapping_definition = [];
    foreach ($normalized_shapes as $prop_name => $prop_shape) {
      $mapping_definition[$prop_name] = [
        'type' => 'ignore',
      ];
      if (!\in_array($prop_name, $required, TRUE)) {
        $mapping_definition[$prop_name]['requiredKey'] = FALSE;
      }
      // Plain strings, HTML strings and URLs are considered translatable. So:
      // - type: string
      // - type: string: format: iri
      // - type: string: format: iri-reference
      // - type: string: format: uri
      // - type: string: format: uri-reference
      // - type: string, contentMediaType: text/html
      // - type: string, contentMediaType: text/html,
      //   x-formatting-context: inline
      // - type: string, contentMediaType: text/html,
      //   x-formatting-context: block
      // @todo Fix arrays of either of those.
      $translatable = $prop_shape['type'] === 'string'
        && !\array_key_exists('enum', $prop_shape)
        && (
          !\array_key_exists('format', $prop_shape)
          || \in_array($prop_shape['format'], [
            JsonSchemaStringFormat::Iri->value,
            JsonSchemaStringFormat::IriReference->value,
            JsonSchemaStringFormat::Uri->value,
            JsonSchemaStringFormat::UriReference->value,
          ], TRUE)
        )
        && (
          !\array_key_exists('contentMediaType', $prop_shape)
          || $prop_shape['contentMediaType'] === 'text/html'
        );
      if ($translatable) {
        \assert(\array_key_exists('type', $mapping_definition[$prop_name]));
        $mapping_definition[$prop_name]['translatable'] = TRUE;
        $mapping_definition[$prop_name]['label'] = $component_source->getMetadata()->schema['properties'][$prop_name]['title'] ?? $prop_name;
        // Reuse Canvas field widgets rather than core's config_translation
        // Textfield/TextFormat form element classes. This single class handles
        // all field types — both single-property (StringItem) and
        // multi-property (TextLongItem, LinkItem) — by conjuring the same
        // field widget that the Canvas UI uses.
        $mapping_definition[$prop_name]['form_element_class'] = CanvasStaticPropSourceFieldWidget::class;
      }
    }

    return $mapping_definition;
  }

  /**
   * {@inheritdoc}
   */
  public function refineForInstance(array $mapping, array $actual_inputs, string $component_id, string $component_version): array {
    // Only user input provided by the Content Author (so: StaticPropSource) is
    // translatable. Structured data is not.
    foreach (\array_keys($mapping) as $key) {
      if (\array_key_exists($key, $actual_inputs) && !FallbackComponentInstanceInputsConfigSchemaGenerator::isStaticPropSource($actual_inputs[$key])) {
        // TRICKY: `translatable: false` is not respected by TMGMT!
        // @see \Drupal\tmgmt_config\DefaultConfigProcessor::extractTranslatables()
        unset($mapping[$key]['translatable']);
        unset($mapping[$key]['form_element_class']);
      }
    }

    // Inject component context into translatable prop definitions so that
    // \Drupal\canvas\ConfigTranslation\CanvasStaticPropSourceFieldWidget can
    // conjure the correct field widget at config translation time.
    // TRICKY: the component source plugin does not have access to its own
    // config entity ID or version — those live on the config entity, not in
    // the plugin's configuration array.
    foreach (\array_keys($mapping) as $key) {
      if (\array_key_exists('form_element_class', $mapping[$key])) {
        $mapping[$key]['_canvas_config_translation_form_element_context'] = [
          'component_id' => $component_id,
          'component_version' => $component_version,
          'prop_name' => $key,
        ];
      }
    }

    return $mapping;
  }

}
