<?php

declare(strict_types=1);

// cspell:ignore magnifique

namespace Drupal\Tests\canvas\Functional;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\DataProvider;
use Drupal\canvas\Entity\Component;
use Drupal\canvas\Entity\Page;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;
use Drupal\Core\Url;
use Drupal\canvas\Plugin\DataType\ComponentInputs;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem;
use Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItemList;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\tmgmt\Entity\Job;
use Drupal\tmgmt\Entity\Translator;
use Drupal\Tests\ApiRequestTrait;
use Drupal\Tests\content_translation\Traits\ContentTranslationTestTrait;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Translation.
 *
 * @todo Add test coverage for entity field prop sources used in the content
 *   templates in https://drupal.org/i/3455629. This will most likely require
 *   adding back `canvas_entity_prepare_view()` which was removed in
 *   https://www.drupal.org/i/3481720.
 * @see https://www.drupal.org/project/canvas/issues/3455629#comment-15831060
 */
#[RunTestsInSeparateProcesses]
#[Group('canvas')]
class TranslationTest extends FunctionalTestBase {

  use ApiRequestTrait;
  use ContentTranslationTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'canvas',
    'canvas_test_sdc',
    'content_translation',
    'language',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected $profile = 'minimal';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // In 11.2 and above we install modules in groups, which means this module
    // cannot be installed in the same group as canvas
    \Drupal::service(ModuleInstallerInterface::class)->install(['canvas_test_config_node_article']);

    // Display the `field_canvas_test` field.
    \Drupal::service('entity_display.repository')
      ->getViewDisplay('node', 'article')
      ->setComponent('field_canvas_test', [
        'label' => 'hidden',
        'type' => 'canvas_naive_render_sdc_tree',
      ])
      ->save();

    $page = $this->getSession()->getPage();
    $this->drupalLogin($this->rootUser);
    $this->drupalGet('admin/config/regional/language');
    $this->clickLink('Add language');
    $page->selectFieldOption('predefined_langcode', 'fr');
    $page->pressButton('Add language');
    $this->assertSession()->pageTextContains('The language French has been created and can now be used.');
    // Rebuild the container so that the new languages are picked up by services
    // that hold a list of languages.
    $this->rebuildContainer();
    $this->enableContentTranslation('node', 'article');
  }

  /**
   * Tests that component_tree is translatable in config entities when canvas_dev_translation is enabled.
   */
  public function testComponentTreeTranslatableInConfigEntities(): void {
    // First test that component_tree is not translatable when canvas_dev_translation is disabled.
    $typed_config = $this->container->get('config.typed');
    \assert($typed_config instanceof TypedConfigManagerInterface);
    // Test canvas.page_region.* schema.
    $page_region_schema = $typed_config->getDefinition('canvas.page_region.*');
    $this->assertIsArray($page_region_schema);
    $this->assertArrayHasKey('mapping', $page_region_schema);
    $this->assertArrayHasKey('component_tree', $page_region_schema['mapping']);
    $this->assertArrayNotHasKey('translatable', $page_region_schema['mapping']['component_tree'], 'component_tree should not be translatable for canvas.page_region.* without canvas_dev_translation');

    // Test canvas.content_template.*.*.* schema.
    $content_template_schema = $typed_config->getDefinition('canvas.content_template.*.*.*');
    $this->assertIsArray($content_template_schema);
    $this->assertArrayHasKey('mapping', $content_template_schema);
    $this->assertArrayHasKey('component_tree', $content_template_schema['mapping']);
    $this->assertArrayNotHasKey('translatable', $content_template_schema['mapping']['component_tree'], 'component_tree should not be translatable for canvas.content_template.*.*.* without canvas_dev_translation');

    // Enable the canvas_dev_translation module and test that component_tree is now translatable.
    $module_installer = $this->container->get('module_installer');
    \assert($module_installer instanceof ModuleInstallerInterface);
    $module_installer->install(['canvas_dev_translation']);
    $this->rebuildContainer();
    $typed_config = $this->container->get('config.typed');

    // Test canvas.page_region.* schema.
    $page_region_schema = $typed_config->getDefinition('canvas.page_region.*');
    $this->assertIsArray($page_region_schema);
    $this->assertArrayHasKey('mapping', $page_region_schema);
    $this->assertArrayHasKey('component_tree', $page_region_schema['mapping']);
    $this->assertArrayHasKey('translatable', $page_region_schema['mapping']['component_tree']);
    $this->assertTrue($page_region_schema['mapping']['component_tree']['translatable'], 'component_tree should be translatable for canvas.page_region.*');

    // Test canvas.content_template.*.*.* schema.
    $content_template_schema = $typed_config->getDefinition('canvas.content_template.*.*.*');
    $this->assertIsArray($content_template_schema);
    $this->assertArrayHasKey('mapping', $content_template_schema);
    $this->assertArrayHasKey('component_tree', $content_template_schema['mapping']);
    $this->assertArrayHasKey('translatable', $content_template_schema['mapping']['component_tree']);
    $this->assertTrue($content_template_schema['mapping']['component_tree']['translatable'], 'component_tree should be translatable for canvas.content_template.*.*.*');
  }

  /**
   * Data provider for testTranslation().
   *
   * @return array<array{0: array, 1: bool}>
   */
  public static function translationDataProvider(): array {
    return [
      // In the symmetric case, the 'tree' property is not translatable. This
      // means every translation has the same components but can have different
      // properties.
      'symmetric' => [['inputs'], TRUE],
      // In the asymmetric case, both 'tree' and 'inputs' properties are
      // translatable. This means every translation can have different components
      // and properties for those components. There no connection at all between
      // the components in the different translations.
      'asymmetric' => [['tree', 'inputs'], FALSE],
      // This case tests when the field is not translatable, but it is used on
      // an entity that has translations. In this case, the components and their
      // properties are shared between the translations.
      'not translatable' => [[], TRUE],
    ];
  }

  /**
   * Tests translating the Canvas field.
   *
   * @param array<string> $translatable_properties
   *   The properties on the Canvas field that should be
   *   translatable.
   * @param bool $expect_component_removed_on_translation
   *   Whether the last component in Canvas tree is expected to be removed from the
   *   translation. The component is always removed from the default
   *   translation.
   */
  #[DataProvider('translationDataProvider')]
  public function testTranslation(array $translatable_properties, bool $expect_component_removed_on_translation): void {
    $assert_session = $this->assertSession();

    $field_is_translatable = !empty($translatable_properties);

    $this->setFieldTranslatble($translatable_properties);

    $original_node = $this->createCanvasNodeWithTranslation(\in_array('inputs', $translatable_properties, TRUE));
    $this->assertTrue($original_node->isDefaultTranslation());
    $translated_node = $original_node->getTranslation('fr');
    $this->assertSame('The French title', (string) $translated_node->getTitle());

    $this->drupalGet($original_node->toUrl());
    $hero_component = $assert_session->elementExists('css', '[data-component-id="canvas_test_sdc:my-hero"]');

    // Confirm the translated property is not on the page anywhere.
    $assert_session->pageTextNotContains('bonjour');
    // Confirm the first hero component does not use the translated properties
    // because it uses a StaticPropSource.
    $this->assertSame('hello, new world!', $hero_component->find('css', 'h1')?->getText());
    // Confirm the heading has been removed from display. This was changed on
    // the default translation.
    $assert_session->elementsCount('css', '[data-component-id="canvas_test_sdc:heading"]', 0);

    $this->drupalGet($translated_node->toUrl());
    $assert_session->elementTextEquals('css', '#block-stark-page-title h1', 'The French title');

    $hero_component = $assert_session->elementExists('css', '[data-component-id="canvas_test_sdc:my-hero"]');
    if ($field_is_translatable) {
      // If the field is translatable updating inputs in the default translation
      // should not have updated the French translation.
      $this->assertSame('bonjour, monde!', $hero_component->find('css', 'h1')?->getText());
      $assert_session->pageTextNotContains('hello, new world!');
    }
    else {
      // If the field is not translatable updating inputs in the default translation
      // should have also updated the French translation.
      $assert_session->pageTextNotContains('bonjour');
      $this->assertSame('hello, new world!', $hero_component->find('css', 'h1')?->getText());
    }

    // Confirm the heading component has been removed or not based the test case
    // expectation.
    $assert_session->elementsCount(
      'css',
      '[data-component-id="canvas_test_sdc:heading"]',
      $expect_component_removed_on_translation ? 0 : 1
    );

    // Verify the `name` for a single component instance is only present on the
    // original translation — both in the server-side storage, and in the
    // information provided to the client for the UI.
    $get_name = function (NodeInterface $node): ?string {
      $component_tree = $node->get('field_canvas_test');
      \assert($component_tree instanceof ComponentTreeItemList);
      return $component_tree->getComponentTreeItemByUuid('208452de-10d6-4fb8-89a1-10e340b3744c')?->getLabel();
    };
    // If the field is not translatable updating inputs in the French
    // translation should have also updated the default translation.
    $expected_original_label = $field_is_translatable ? 'Starring … Drupal as the hero! 🤩' : "Drupal, c'est magnifique !";
    self::assertSame($expected_original_label, $get_name($original_node));
    self::assertSame("Drupal, c'est magnifique !", $get_name($translated_node));
    $get_name_in_api_response = function (string $root_relative_url): ?string {
      $response = $this->makeApiRequest('GET', Url::fromUri("base:$root_relative_url"), []);
      self::assertSame(200, $response->getStatusCode());
      $layout = json_decode((string) $response->getBody(), TRUE)['layout'];
      return $layout[0]['components'][0]['slots'][0]['components'][0]['name'];
    };
    self::assertSame($expected_original_label, $get_name_in_api_response('/canvas/api/v0/layout/node/1'));
    self::assertSame("Drupal, c'est magnifique !", $get_name_in_api_response('/fr/canvas/api/v0/layout/node/1'));
  }

  /**
   * Tests that preSave() automatically strips non-translatable input keys.
   *
   * When saving a non-default translation, ComponentTreeItem::preSave() should
   * use getTranslatableInputKeys() to strip non-translatable keys from inputs.
   * Non-translatable values are then merged from the default translation at
   * read time by ComponentSourceBase::getExplicitInput().
   *
   * Uses the 'my-cta' SDC which has:
   * - text: type: string (translatable)
   * - href: type: string, format: uri (translatable)
   * - target: type: string, enum: [_self, _blank] (NOT translatable — enums)
   *
   * @see \Drupal\canvas\Plugin\Field\FieldType\ComponentTreeItem::preSave()
   * @see \Drupal\canvas\Plugin\DataType\ComponentInputs::getTranslatableInputKeys()
   * @see \Drupal\canvas\ComponentSource\ComponentSourceBase::getExplicitInput()
   * @see https://www.drupal.org/project/canvas/issues/3583684
   */
  public function testInvalidTranslationProps(): void {
    $this->setFieldTranslatble(['inputs']);

    $cta_uuid = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
    $component = Component::load('sdc.canvas_test_sdc.my-cta');
    self::assertNotNull($component);
    $version = $component->getActiveVersion();

    $node = Node::create([
      'type' => 'article',
      'title' => 'Test node',
      'field_canvas_test' => [
        [
          'uuid' => $cta_uuid,
          'component_id' => 'sdc.canvas_test_sdc.my-cta',
          'component_version' => $version,
          'inputs' => [
            'text' => 'Click here',
            'href' => 'https://drupal.org',
            'target' => '_self',
          ],
        ],
      ],
    ]);
    $node->save();

    // Reload so addTranslation() can properly synchronize field values.
    $node = Node::load($node->id());
    self::assertNotNull($node);

    // Verify which keys are translatable per the config schema.
    $list = $node->get('field_canvas_test');
    \assert($list instanceof ComponentTreeItemList);
    $cta = $list->getComponentTreeItemByUuid($cta_uuid);
    \assert($cta instanceof ComponentTreeItem);
    $inputs_typed_data = $cta->get('inputs');
    \assert($inputs_typed_data instanceof ComponentInputs);
    $translatable_keys = $inputs_typed_data->getTranslatableInputKeys();
    self::assertContains('text', $translatable_keys);
    self::assertNotContains('target', $translatable_keys, 'enum prop should not be translatable');

    // Create a French translation. Explicitly set the component tree with
    // ALL inputs including the non-translatable 'target' — preSave() should
    // strip it automatically.
    $translation = $node->addTranslation('fr');
    $this->container->get('content_translation.manager')
      ->getTranslationMetadata($translation)
      ->setSource($node->language()->getId());
    // @phpstan-ignore-next-line
    $translation->title = 'French title';
    $translation->set('field_canvas_test', [
      [
        'uuid' => $cta_uuid,
        'component_id' => 'sdc.canvas_test_sdc.my-cta',
        'component_version' => $version,
        'inputs' => [
          'text' => 'Cliquez ici',
          'href' => 'https://drupal.fr',
          'target' => '_self',
        ],
      ],
    ]);
    $translation->save();

    // Reload and verify non-translatable 'target' was stripped.
    $node = Node::load($node->id());
    self::assertNotNull($node);
    $fr_node = $node->getTranslation('fr');
    $fr_list = $fr_node->get('field_canvas_test');
    \assert($fr_list instanceof ComponentTreeItemList);
    $fr_cta = $fr_list->getComponentTreeItemByUuid($cta_uuid);
    \assert($fr_cta instanceof ComponentTreeItem);
    $fr_stored_inputs = $fr_cta->getInputs();
    self::assertArrayHasKey('text', $fr_stored_inputs);
    self::assertSame('Cliquez ici', $fr_stored_inputs['text']);
    self::assertArrayNotHasKey('target', $fr_stored_inputs, 'Non-translatable target should have been stripped on save');

    // Now update the non-translatable 'target' on the default translation.
    $en_node = $node->getTranslation('en');
    $en_list = $en_node->get('field_canvas_test');
    \assert($en_list instanceof ComponentTreeItemList);
    $en_cta = $en_list->getComponentTreeItemByUuid($cta_uuid);
    \assert($en_cta instanceof ComponentTreeItem);
    $en_cta->setInput([
      'text' => 'Click here',
      'href' => 'https://drupal.org',
      'target' => '_blank',
    ]);
    $en_node->save();

    // Verify the French translation's merge-on-read picks up the updated
    // non-translatable 'target' from the default translation.
    $node = Node::load($node->id());
    self::assertNotNull($node);
    $fr_node = $node->getTranslation('fr');
    $fr_list = $fr_node->get('field_canvas_test');
    \assert($fr_list instanceof ComponentTreeItemList);
    $fr_cta = $fr_list->getComponentTreeItemByUuid($cta_uuid);
    \assert($fr_cta instanceof ComponentTreeItem);
    $component_source = $fr_cta->getComponent()?->getComponentSource();
    self::assertNotNull($component_source);
    $resolved = $component_source->getResolvedExplicitInput($cta_uuid, $fr_cta, $fr_node);
    self::assertSame('Cliquez ici', $resolved['text']->value, 'Translated text should be preserved');
    self::assertSame('_blank', $resolved['target']->value, 'Updated non-translatable target should be merged from default');
  }

  /**
   * Creates an article node with a translation.
   *
   * @return \Drupal\node\Entity\Node
   *   The default translation of the node.
   */
  protected function createCanvasNodeWithTranslation(bool $translatable_inputs): Node {
    $node = $this->createTestNode();
    $list = $node->get('field_canvas_test');
    \assert($list instanceof ComponentTreeItemList);
    // There are five items in the default values for this field.
    self::assertEquals(5, $list->count());

    // Create a translation from the original English node.
    $translation = $node->addTranslation('fr');
    $this->assertInstanceOf(Node::class, $translation);
    $this->container->get('content_translation.manager')->getTranslationMetadata($translation)->setSource($node->language()->getId());
    // @phpstan-ignore-next-line
    $translation->title = 'The French title';
    $translation->save();
    $translation = $node->getTranslation('fr');
    $updated_item = $list->getComponentTreeItemByUuid('208452de-10d6-4fb8-89a1-10e340b3744c');
    \assert($updated_item instanceof ComponentTreeItem);
    $updated_item_inputs = $updated_item->getInputs();
    // In both the Symmetric and Asymmetric translation cases, the `inputs` and
    // `label` field properties are translatable and this should only change the
    // translation.
    $french_inputs = $updated_item_inputs;
    $french_inputs['heading'] = 'bonjour, monde!';
    if ($translatable_inputs) {
      // The `cta1href` prop even though it is `type: string` also has
      // `format: uri-reference` so it should not be translatable in symmetric
      // translations.
      unset($french_inputs['cta1href']);
      // `attributes` should also not be translatable.
      unset($french_inputs['attributes']);
    }

    $french_list = $translation->get('field_canvas_test');
    \assert($french_list instanceof ComponentTreeItemList);
    $french_item = $french_list->getComponentTreeItemByUuid('208452de-10d6-4fb8-89a1-10e340b3744c');
    \assert($french_item instanceof ComponentTreeItem);
    $french_item->setInput($french_inputs)
      ->setLabel("Drupal, c'est magnifique !");
    $translation->save();

    // Update the English version.
    $updated_item_inputs['heading'] = 'hello, new world!';
    // In both the Symmetric and Asymmetric cases, the `inputs` property is
    // translatable and this should only change the original. If the field is
    // not translatable, this should change both the original and the
    // translation.
    $updated_item->setInput($updated_item_inputs);
    // Remove the heading from the tree.
    // In the asymmetric case, where 'tree' is translatable, this should only
    // affect the untranslated node.
    // In the symmetric case, where 'tree' is not translatable, this should
    // change both the original and the translation.
    $delta_to_remove = $list->getComponentTreeDeltaByUuid('e660e407-0901-4639-9726-9f99bc250c4c');
    \assert(\is_int($delta_to_remove));
    $list->removeItem($delta_to_remove);
    $node->save();
    return $node;
  }

  public function testTmgmtComponentTreeFieldProcessor(): void {
    // Install TMGMT after other modules — rebuildContainer() triggers
    // ShapeMatchingHooks::fieldInfoAlter() with tmgmt_content present,
    // registering ComponentTreeFieldProcessor for component_tree fields.
    $module_installer = $this->container->get(ModuleInstallerInterface::class);
    $module_installer->install(['tmgmt', 'tmgmt_content', 'tmgmt_test']);
    $this->rebuildContainer();

    // Enable content translation for canvas_page so TMGMT content source
    // can create French translations.
    $this->enableContentTranslation('canvas_page', 'canvas_page');

    $component_uuid = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';
    $component = Component::load('sdc.canvas_test_sdc.required-plain-string');
    self::assertNotNull($component);
    $canvas_page = Page::create([
      'title' => 'TMGMT test page',
      'components' => [[
        'uuid' => $component_uuid,
        'component_id' => 'sdc.canvas_test_sdc.required-plain-string',
        'component_version' => $component->getActiveVersion(),
        'inputs' => [
          'title' => 'Click here',
        ],
      ],
      ],
    ]);
    $canvas_page->save();
    $page_id = $canvas_page->id();

    // TestTranslator::getSupportedTargetLanguages() excludes 'fr', so bypass
    // requestTranslation() and call requestJobItemsTranslation() directly.
    $translator = Translator::create([
      'name' => 'test_tmgmt',
      'label' => 'Test TMGMT',
      'plugin' => 'test_translator',
      'remote_languages_mappings' => [],
      'settings' => ['key' => 'test', 'another_key' => 'test'],
    ]);
    $translator->save();

    $job = tmgmt_job_create('en', 'fr', $this->rootUser->id());
    $job->translator = $translator->id();
    $job->save();
    $job_item = $job->addItem('content', 'canvas_page', $page_id);

    $job->setState(Job::STATE_ACTIVE);
    $translator->getPlugin()->requestJobItemsTranslation($job->getItems());

    $this->drupalLogin($this->rootUser);
    $this->drupalGet('admin/tmgmt/items/' . $job_item->id());
    $assert = $this->assertSession();
    $assert->statusCodeEquals(200);
    // extractTranslatableData() surfaces the 'text' prop value as source text.
    $assert->pageTextContains('Click here');
    // TestTranslator prefixes translations: "fr: {original}".
    $assert->pageTextContains('fr: Click here');

    $this->submitForm([], 'Save as completed');
    $assert->pageTextContains((string) t('The translation for @source has been accepted', [
      '@source' => $canvas_page->label(),
    ]));

    $canvas_page = Page::load($page_id);
    self::assertNotNull($canvas_page);
    $fr_page = $canvas_page->getTranslation('fr');
    $fr_component = $fr_page->getComponentTree()->getComponentTreeItemByUuid($component_uuid);
    self::assertNotNull($fr_component);
    $fr_inputs = $fr_component->getInputs();
    self::assertSame('fr: Click here', $fr_inputs['title']);

    $this->drupalGet('/fr/page/' . $page_id);
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('fr: Click here');
  }

  private function setFieldTranslatble(array $translatable_properties): void {
    $page = $this->getSession()->getPage();
    $field_is_translatable = !empty($translatable_properties);
    $this->drupalGet('admin/config/regional/content-language');
    if ($field_is_translatable) {
      $page->checkField('settings[node][article][fields][field_canvas_test]');
      foreach (['tree', 'inputs'] as $field_property) {
        \in_array($field_property, $translatable_properties, TRUE)
          ? $page->checkField("settings[node][article][columns][field_canvas_test][$field_property]")
          : $page->uncheckField("settings[node][article][columns][field_canvas_test][$field_property]");
      }
    }
    else {
      $page->uncheckField('settings[node][article][fields][field_canvas_test]');
    }
    $page->pressButton('Save configuration');
    $this->assertSession()->pageTextContains('Settings successfully updated.');
  }

}
