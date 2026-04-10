<?php

declare(strict_types=1);

namespace Drupal\canvas\Config\Schema;

use Drupal\canvas\Entity\Component;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Config\Schema\Mapping;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\Core\TypedData\TypedDataInterface;

/**
 * Generates a mapping definition
 *
 * @internal
 *
 * @todo this is copied from https://git.drupalcode.org/project/canvas/-/merge_requests/868
 *   see local branch 3582478-config-schema-component-specific-inputs or
 *   /Users/ted.bowman/sites/exp-d-core99/modules/contrib/experience_builder/src/Config/Schema/ComponentSpecificInputs.php
 *   just here for reference as how to determine what props are translatable
 *   this class is for config but we want content entities to share same rules.
 */
final class ComponentSpecificInputs extends Mapping {

  private static function isStaticPropSource(mixed $value): bool {
    // Detect an optimized explicit input.
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::optimizeExplicitInputs()
    // @see \Drupal\canvas\Plugin\Canvas\ComponentSource\GeneratedFieldExplicitInputUxComponentSourceBase::collapse()
    if (!\is_array($value) || !\array_key_exists('sourceType', $value)) {
      return TRUE;
    }

    \assert(\is_array($value));
    \assert(\array_key_exists('sourceType', $value));
    return PropSource::parse($value)->getSourceType() === PropSource::Static->value;
  }

  public function __construct(DataDefinitionInterface $definition, $name = NULL, ?TypedDataInterface $parent = NULL) {
    \assert($definition instanceof MapDataDefinition);

    if ($parent === NULL) {
      throw new \LogicException('$parent cannot be NULL; this can only be used for the `inputs` key in `type: canvas.component_tree_node`.');
    }
    // `field.value.component_tree` is a subtype of the
    // `canvas.component_tree_node` config schema type.
    if (!in_array($parent->getDataDefinition()->getDataType(), ['canvas.component_tree_node', 'field.value.component_tree'], TRUE)) {
      throw new \LogicException(\sprintf('$parent must be of type `canvas.component_tree_node`, `%s` given.', $parent->getDataDefinition()->getDataType()));
    }

    // Per `type: canvas.component_tree_node`, some keys must definitely exist,
    // assert the ones that this class needs.
    $component_instance = $parent->getValue();
    \assert(\array_key_exists('component_id', $component_instance));
    \assert(\array_key_exists('component_version', $component_instance));
    \assert(\array_key_exists('inputs', $component_instance));
    $component_id = $component_instance['component_id'];
    $component_version = $component_instance['component_version'];
    $actual_inputs = $component_instance['inputs'];

    $component = Component::load($component_id);
    // Non-existent Component. Validation will detect this.
    if ($component === NULL) {
      // @see https://en.wikipedia.org/wiki/Robustness_principle
      parent::__construct($definition, $name, $parent);
      return;
    }

    try {
      $component_source = $component->loadVersion($component_version)
        ->getComponentSource();
    }
    // Non-existent Component version. Validation will detect this. Minimize
    // noise in validation for the component instance's `inputs`: assume
    // should have referred to the active version instead.
    // @see \Drupal\canvas\Entity\VersionedConfigEntityBase::assertVersionExists()
    catch (\OutOfRangeException) {
      $component_source = $component->loadVersion($component->getActiveVersion())
        ->getComponentSource();
    }

    $explicit_inputs_as_config_schema = $component_source->getExplicitInputDefinitionsAsConfigSchemaMapping();

    // Only user input provided by the Content Author (so: StaticPropSource) is
    // translatable. Structured data is not.
    foreach (\array_keys($explicit_inputs_as_config_schema) as $key) {
      if (\array_key_exists($key, $actual_inputs) && !self::isStaticPropSource($actual_inputs[$key])) {
        $explicit_inputs_as_config_schema[$key]['translatable'] = FALSE;
        unset($explicit_inputs_as_config_schema[$key]['form_element_class']);
      }
    }

    $definition['mapping'] = $explicit_inputs_as_config_schema;

    parent::__construct($definition, $name, $parent);
  }

}
