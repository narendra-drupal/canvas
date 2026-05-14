<?php

declare(strict_types=1);

namespace Drupal\Tests\canvas\Kernel\Controller;

use Drupal\Core\Http\Exception\CacheableAccessDeniedHttpException;
use Drupal\canvas\Controller\ApiLanguageController;
use Drupal\canvas\Entity\ContentTemplate;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\canvas\Kernel\CanvasKernelTestBase;
use Drupal\Tests\canvas\Kernel\Traits\RequestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the ApiLanguageController.
 */
#[RunTestsInSeparateProcesses]
#[CoversClass(ApiLanguageController::class)]
#[Group('canvas')]
class ApiLanguageControllerTest extends CanvasKernelTestBase {

  use UserCreationTrait;
  use RequestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'language',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installConfig(['language']);
  }

  public function testListReturnsDefaultLanguage(): void {
    $this->setUpCurrentUser([], [ContentTemplate::ADMIN_PERMISSION]);

    $response = $this->request(Request::create('/canvas/api/v0/languages'));
    self::assertSame(200, $response->getStatusCode());

    $responseData = static::decodeResponse($response);
    self::assertIsArray($responseData);
    self::assertArrayHasKey('data', $responseData);
    $languages = $responseData['data'];
    self::assertIsArray($languages);
    // Only English is configured by default; locked languages (und, zxx) are
    // excluded because the endpoint uses STATE_CONFIGURABLE.
    self::assertCount(1, $languages);

    $english = $languages[0];
    self::assertSame('en', $english['id']);
    self::assertSame('ltr', $english['direction']);
    self::assertTrue($english['isDefault']);
    self::assertIsString($english['name']);
  }

  public function testListIncludesAddedLanguage(): void {
    $this->setUpCurrentUser([], [ContentTemplate::ADMIN_PERMISSION]);

    ConfigurableLanguage::createFromLangcode('fr')->save();

    $response = $this->request(Request::create('/canvas/api/v0/languages'));
    self::assertSame(200, $response->getStatusCode());

    $responseData = static::decodeResponse($response);
    self::assertIsArray($responseData);
    self::assertArrayHasKey('data', $responseData);
    $languages = $responseData['data'];
    self::assertIsArray($languages);
    self::assertCount(2, $languages);

    $ids = array_column($languages, 'id');
    self::assertContains('en', $ids);
    self::assertContains('fr', $ids);

    $fr = current(array_filter($languages, fn($l) => $l['id'] === 'fr'));
    self::assertIsArray($fr);
    self::assertFalse($fr['isDefault']);
  }

  public function testListRequiresAuthentication(): void {
    // A user with no Canvas permissions — the _canvas_ui_access check denies
    // access before the authentication check can return 401.
    $this->setUpCurrentUser();

    $this->expectException(CacheableAccessDeniedHttpException::class);
    $this->request(Request::create('/canvas/api/v0/languages'));
  }

}
