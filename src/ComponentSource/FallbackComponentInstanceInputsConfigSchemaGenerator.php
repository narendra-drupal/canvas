<?php

declare(strict_types=1);

namespace Drupal\canvas\ComponentSource;

use Drupal\canvas\PropSource\PropSource;

/**
 * @internal
 *
 * Fallback strategy that generates `type: ignore` config schema for components
 * without precise schema support.
 */
final readonly class FallbackComponentInstanceInputsConfigSchemaGenerator implements ComponentInstanceInputsConfigSchemaGeneratorInterface {

  /**
   * {@inheritdoc}
   *
   * @return array<string, array{type: string, label: string, translatable?: bool, form_element_class?: string}>
   */
  public function getConfigSchemaMapping(ComponentSourceInterface $component_source): array {
    $valid_inputs = \array_keys($component_source->getDefaultExplicitInput());
    $required_inputs = \array_keys($component_source->getDefaultExplicitInput(TRUE));

    // Generate a mapping definition based on the source's default explicit
    // inputs that are valid vs required. Assume none to be translatable; only a
    // concrete strategy can know which are translatable.
    $mapping_definition = [];
    foreach ($valid_inputs as $key) {
      $mapping_definition[$key] = [
        'type' => 'ignore',
      ];
      if (!\in_array($key, $required_inputs, TRUE)) {
        $mapping_definition[$key]['requiredKey'] = FALSE;
      }
    }

    // It is impossible for this fallback strategy to generate an appropriate
    // `label` for each explicit input.
    // @phpstan-ignore-next-line return.type
    return $mapping_definition;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $mapping
   * @param array<string, mixed> $actual_inputs
   *
   * @return array<string, mixed>
   */
  public function refineForInstance(array $mapping, array $actual_inputs, string $component_id, string $component_version): array {
    // The Fallback generates only `type: ignore` with no `translatable` or
    // `form_element_class`, so no instance-level refinement is needed.
    return $mapping;
  }

  public static function isStaticPropSource(mixed $value): bool {
    // Detect an optimized explicit input.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::optimizeExplicitInputs()
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::collapse()
    if (!\is_array($value) || !\array_key_exists('sourceType', $value)) {
      return TRUE;
    }
    return PropSource::parse($value)->getSourceType() === PropSource::Static->value;
  }

}
