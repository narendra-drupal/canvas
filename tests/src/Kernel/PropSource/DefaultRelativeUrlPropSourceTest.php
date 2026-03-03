<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\PropSource;

use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Plugin\Canvas\ComponentSource\SingleDirectoryComponent;
use Drupal\canvas\PropSource\DefaultRelativeUrlPropSource;
use Drupal\canvas\PropSource\PropSource;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\Url;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[CoversClass(DefaultRelativeUrlPropSource::class)]
#[CoversMethod(SingleDirectoryComponent::class, 'rewriteExampleUrl')]
#[Group('canvas')]
#[Group('canvas_component_sources')]
#[Group('canvas_data_model')]
#[RunTestsInSeparateProcesses]
class DefaultRelativeUrlPropSourceTest extends PropSourceTestBase {

  public function test(): void {
    $this->enableModules(['canvas_test_sdc', 'link', 'image', 'options', 'text']);
    self::assertNull(Component::load('sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop'));
    $this->container->get(ComponentSourceManager::class)->generateComponents();
    self::assertNotNull(Component::load('sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop'));

    $source = new DefaultRelativeUrlPropSource(
      value: [
        'src' => 'gracie.jpg',
        'alt' => 'A good dog',
        'width' => 601,
        'height' => 402,
      ],
      jsonSchema: [
        'title' => 'image',
        'type' => 'object',
        'required' => ['src'],
        'properties' => [
          'src' => [
            'type' => 'string',
            'contentMediaType' => 'image/*',
            'format' => 'uri-reference',
            'title' => 'Image URL',
            'x-allowed-schemes' => ['http', 'https'],
          ],
          'alt' => [
            'type' => 'string',
            'title' => 'Alternate text',
          ],
          'width' => [
            'type' => 'integer',
            'title' => 'Image width',
          ],
          'height' => [
            'type' => 'integer',
            'title' => 'Image height',
          ],
        ],
      ],
      componentId: 'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop',
    );
    // First, get the string representation and parse it back, to prove
    // serialization and deserialization works.
    // Note: title of properties have been omitted; only essential data is kept.
    $json_representation = (string) $source;
    self::assertSame('{"sourceType":"default-relative-url","value":{"src":"gracie.jpg","alt":"A good dog","width":601,"height":402},"jsonSchema":{"type":"object","properties":{"src":{"type":"string","contentMediaType":"image\/*","format":"uri-reference","x-allowed-schemes":["http","https"]},"alt":{"type":"string"},"width":{"type":"integer"},"height":{"type":"integer"}},"required":["src"]},"componentId":"sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop"}', $json_representation);
    $decoded = json_decode($json_representation, TRUE);
    // Ensure that DefaultRelativeUrlPropSource::parse() does not care about key
    // order for the JSON Schema definition it contains.
    $decoded['jsonSchema'] = array_reverse($decoded['jsonSchema']);
    $source = PropSource::parse($decoded);
    self::assertInstanceOf(DefaultRelativeUrlPropSource::class, $source);
    self::assertSame('default-relative-url', $source->getSourceType());
    $path = $this->container->get(ExtensionPathResolver::class)->getPath('module', 'canvas_test_sdc') . '/components/image-optional-with-example-and-additional-prop';
    // Prove that using a `$ref` results in the same JSON representation.
    $equivalent_source = new DefaultRelativeUrlPropSource(
      value: [
        'src' => 'gracie.jpg',
        'alt' => 'A good dog',
        'width' => 601,
        'height' => 402,
      ],
      jsonSchema: [
        '$ref' => 'json-schema-definitions://canvas.module/image',
      ],
      componentId: 'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop',
    );
    self::assertSame((string) $equivalent_source, $json_representation);
    // Test that the URL resolves on evaluation.
    $evaluation_result = $source->evaluate(NULL, is_required: TRUE);
    self::assertSame([
      'src' => Url::fromUri(\sprintf('base:%s/gracie.jpg', $path))->toString(),
      'alt' => 'A good dog',
      'width' => 601,
      'height' => 402,
    ], $evaluation_result->value);
    self::assertEqualsCanonicalizing(['component_plugins'], $evaluation_result->getCacheTags());
    self::assertEqualsCanonicalizing([], $evaluation_result->getCacheContexts());
    self::assertSame(Cache::PERMANENT, $evaluation_result->getCacheMaxAge());
    self::assertSame([
      'config' => ['canvas.component.sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop'],
    ], $source->calculateDependencies());

    // Ensure that DefaultRelativeUrlPropSource::parse() does not care about key
    // order for the JSON Schema definition properties it contains.
    $decoded['jsonSchema']['properties'] = array_reverse($decoded['jsonSchema']['properties']);
    $source = PropSource::parse($decoded);
    self::assertInstanceOf(DefaultRelativeUrlPropSource::class, $source);
    self::assertSame('default-relative-url', $source->getSourceType());

    // Ensure that DefaultRelativeUrlPropSource::parse() does not care about key
    // order for the JSON Schema definition properties attributes it contains.
    $decoded['jsonSchema']['properties']['src'] = array_reverse($decoded['jsonSchema']['properties']['src']);
    $source = PropSource::parse($decoded);
    self::assertInstanceOf(DefaultRelativeUrlPropSource::class, $source);
    self::assertSame('default-relative-url', $source->getSourceType());

    // This is never a choice presented to the end user; this is a purely internal prop source.
    $this->expectException(\LogicException::class);
    $source->asChoice();
  }

  /**
   * Test array-type prop with multiple URL items.
   *
   * Tests:
   * - Happy path for nested arrays
   * - Tests URL rewriting in array items
   * - Validates recursive processing
   */
  public function testArrayTypePropWithMultipleUrlItems(): void {
    $this->enableModules(['canvas_test_sdc', 'link', 'image', 'options', 'text']);
    $this->container->get(ComponentSourceManager::class)->generateComponents();

    $path = $this->container->get(ExtensionPathResolver::class)->getPath('module', 'canvas_test_sdc') . '/components/image-optional-with-example-and-additional-prop';

    // Test array of URL strings.
    $source = new DefaultRelativeUrlPropSource(
      value: [
        'gracie.jpg',
        'another-image.jpg',
      ],
      jsonSchema: [
        'type' => 'array',
        'items' => [
          'type' => 'string',
          'format' => 'uri-reference',
        ],
      ],
      componentId: 'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop',
    );

    $evaluation_result = $source->evaluate(NULL, is_required: TRUE);
    self::assertIsArray($evaluation_result->value);
    self::assertCount(2, $evaluation_result->value);
    self::assertSame(Url::fromUri(\sprintf('base:%s/gracie.jpg', $path))->toString(), $evaluation_result->value[0]);
    self::assertSame(Url::fromUri(\sprintf('base:%s/another-image.jpg', $path))->toString(), $evaluation_result->value[1]);

    // Test array of objects with URL properties.
    $source = new DefaultRelativeUrlPropSource(
      value: [
        [
          'src' => 'gracie.jpg',
          'alt' => 'A good dog',
        ],
        [
          'src' => 'another.jpg',
          'alt' => 'Another image',
        ],
      ],
      jsonSchema: [
        'type' => 'array',
        'items' => [
          'type' => 'object',
          'properties' => [
            'src' => [
              'type' => 'string',
              'format' => 'uri-reference',
            ],
            'alt' => [
              'type' => 'string',
            ],
          ],
        ],
      ],
      componentId: 'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop',
    );

    $evaluation_result = $source->evaluate(NULL, is_required: TRUE);
    self::assertIsArray($evaluation_result->value);
    self::assertCount(2, $evaluation_result->value);
    self::assertSame([
      'src' => Url::fromUri(\sprintf('base:%s/gracie.jpg', $path))->toString(),
      'alt' => 'A good dog',
    ], $evaluation_result->value[0]);
    self::assertSame([
      'src' => Url::fromUri(\sprintf('base:%s/another.jpg', $path))->toString(),
      'alt' => 'Another image',
    ], $evaluation_result->value[1]);
  }

  /**
   * Test array-type prop with non-array value.
   *
   * Tests:
   * - Edge case: empty string instead of array
   * - Tests defensive code: if (!is_array($value)) { $value = []; }
   */
  public function testArrayTypePropWithNonArrayValue(): void {
    $this->enableModules(['canvas_test_sdc', 'link', 'image', 'options', 'text']);
    $this->container->get(ComponentSourceManager::class)->generateComponents();

    // Create a DefaultRelativeUrlPropSource with an array-type schema but provide a non-array value.
    $source = new DefaultRelativeUrlPropSource(
      value: 'not-an-array',
      jsonSchema: [
        'type' => 'array',
        'items' => [
          'type' => 'string',
          'format' => 'uri-reference',
        ],
      ],
      componentId: 'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop',
    );

    // Attempting to evaluate should throw an InvalidArgumentException.
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Schema defines type "array" but example value is string.');
    $source->evaluate(NULL, is_required: TRUE);
  }

  /**
   * Test array-type prop with non-list array.
   *
   * Tests:
   * - Edge case: associative array with string keys
   * - Tests normalization: array_values($value) re-indexing
   * - Validates keys are converted from ['first', 'second'] to [0, 1]
   */
  public function testArrayTypePropWithNonListArray(): void {
    $this->enableModules(['canvas_test_sdc', 'link', 'image', 'options', 'text']);
    $this->container->get(ComponentSourceManager::class)->generateComponents();

    // Create a DefaultRelativeUrlPropSource with an associative array (non-list).
    $source = new DefaultRelativeUrlPropSource(
      value: [
        'first' => 'gracie.jpg',
        'second' => 'another.jpg',
      ],
      jsonSchema: [
        'type' => 'array',
        'items' => [
          'type' => 'string',
          'format' => 'uri-reference',
        ],
      ],
      componentId: 'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop',
    );

    // Attempting to evaluate should throw an InvalidArgumentException for non-list array.
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Array-type props must use sequential numeric keys [0, 1, 2, ...] to form a proper list. Got associative array with keys: [first, second].');
    $source->evaluate(NULL, is_required: TRUE);
  }

  /**
   * Test object-type prop with non-array value.
   *
   * Tests:
   * - Edge case: string instead of object/array
   * - Tests defensive code for objects
   */
  public function testObjectTypePropWithNonArrayValue(): void {
    $this->enableModules(['canvas_test_sdc', 'link', 'image', 'options', 'text']);
    $this->container->get(ComponentSourceManager::class)->generateComponents();

    // Create a DefaultRelativeUrlPropSource with an object-type schema but provide a non-array value.
    $source = new DefaultRelativeUrlPropSource(
      value: 'not-an-object',
      jsonSchema: [
        'type' => 'object',
        'properties' => [
          'src' => [
            'type' => 'string',
            'format' => 'uri-reference',
          ],
        ],
      ],
      componentId: 'sdc.canvas_test_sdc.image-optional-with-example-and-additional-prop',
    );

    // Attempting to evaluate should throw an InvalidArgumentException.
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Schema defines type "object" but example value is string.');
    $source->evaluate(NULL, is_required: TRUE);
  }

}
