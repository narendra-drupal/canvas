import { execDrush } from '@drupal-canvas/test-utils';
import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

/**
 * Tests for the Language Selector component.
 * Tests language switching functionality and URL query parameters.
 */

test.use({
  modules: ['canvas_test_sdc', 'language', 'content_translation'],
  enableTestExtensions: true,
});

test.describe('Language Selector', () => {
  // Since we're using isolatedPerTest, each test gets a fresh environment, so
  // this setup must run before each test.
  test.beforeEach(async ({ drupal }) => {
    const drupalSite = drupal.drupalSite;

    // Add French language.
    await execDrush('language:add fr', {
      url: drupalSite.url,
      userAgent: drupalSite.userAgent,
    });

    // Add Spanish language.
    await execDrush('language:add es', {
      url: drupalSite.url,
      userAgent: drupalSite.userAgent,
    });

    // Enable content translation for canvas_page entity type.
    await execDrush(
      `php-eval "\\Drupal::service('content_translation.manager')->setEnabled('canvas_page', 'canvas_page', TRUE);"`,
      {
        url: drupalSite.url,
        userAgent: drupalSite.userAgent,
      },
    );

    // Enable canvas_dev_translation module.
    await execDrush(
      'pm:enable canvas_dev_translation canvas_test_translation',
      {
        url: drupalSite.url,
        userAgent: drupalSite.userAgent,
      },
    );

    await drupal.loginAsAdmin();
  });

  test('Language selector is visible', async ({ page, drupal, canvas }) => {
    const canvasPage = await canvas.createCanvas();
    await page.goto(`/canvas/editor/canvas_page/${canvasPage.entity_id}`);
    await canvas.waitForEditorUi();

    // Language selector button should be visible.
    const languageButton = page
      .locator('[data-testid="canvas-topbar"] button')
      .filter({ hasText: /English/ })
      .first();
    await expect(languageButton).toBeVisible();
  });

  test('Language selector shows available languages when multiple languages exist', async ({
    page,
    drupal,
    canvas,
  }) => {
    const canvasPage = await canvas.createCanvas();
    await page.goto(`/canvas/editor/canvas_page/${canvasPage.entity_id}`);
    await canvas.waitForEditorUi();

    const languageButton = page
      .locator('[data-testid="canvas-topbar"] button')
      .filter({ hasText: /English/ })
      .first();
    await expect(languageButton).toBeVisible();

    await languageButton.click();

    const languageOptions = page.locator('[role="menuitem"]');
    const count = await languageOptions.count();

    expect(count).toBe(3);
  });

  test('Preview URL includes language query parameter when accessing language translation', async ({
    page,
    drupal,
    canvas,
  }) => {
    const canvasPage = await canvas.createCanvas();
    await page.goto(`/canvas/editor/canvas_page/${canvasPage.entity_id}`);
    await canvas.waitForEditorUi();

    const languageButton = page
      .locator('[data-testid="canvas-topbar"] button')
      .filter({ hasText: /English/ })
      .first();
    await expect(languageButton).toBeVisible();

    await languageButton.click();

    const frenchOption = page
      .locator('[role="menuitem"]')
      .filter({ hasText: /French/ })
      .first();
    await expect(frenchOption).toBeVisible();
    await frenchOption.click();

    await page.waitForURL(/\/preview\/canvas_page\/\d+\/full\?language=fr/, {
      timeout: 10000,
    });

    // Verify the URL contains the French language query parameter.
    const currentUrl = page.url();
    expect(currentUrl).toMatch(
      /\/preview\/canvas_page\/\d+\/full\?language=fr/,
    );

    const languageMatch = currentUrl.match(/language=([a-z]{2})/);
    expect(languageMatch).not.toBeNull();
    expect(languageMatch?.[1]).toBe('fr');
  });

  test('Switching back to default language returns to editor', async ({
    page,
    drupal,
    canvas,
  }) => {
    const canvasPage = await canvas.createCanvas();
    await page.goto(`/canvas/editor/canvas_page/${canvasPage.entity_id}`);
    await canvas.waitForEditorUi();

    let languageButton = page
      .locator('[data-testid="canvas-topbar"] button')
      .filter({ hasText: /English/ })
      .first();
    await expect(languageButton).toBeVisible();

    await languageButton.click();

    const frenchOption = page
      .locator('[role="menuitem"]')
      .filter({ hasText: /French/ })
      .first();
    await expect(frenchOption).toBeVisible();
    await frenchOption.click();

    await page.waitForURL(/\/preview\/canvas_page\/\d+\/full\?language=fr/, {
      timeout: 10000,
    });

    // Verify the URL contains the French language query parameter.
    let currentUrl = page.url();
    expect(currentUrl).toMatch(
      /\/preview\/canvas_page\/\d+\/full\?language=fr/,
    );

    const languageMatch = currentUrl.match(/language=([a-z]{2})/);
    expect(languageMatch).not.toBeNull();
    expect(languageMatch?.[1]).toBe('fr');

    languageButton = page
      .locator('[data-testid="canvas-topbar"] button')
      .filter({ hasText: /French/ })
      .first();
    await languageButton.click();

    const defaultLanguageItem = page
      .locator('[role="menuitem"]')
      .filter({ hasText: /Default/ })
      .first();
    await expect(defaultLanguageItem).toBeVisible();
    await defaultLanguageItem.click();

    await page.waitForURL(/\/editor\/canvas_page\/\d+/, { timeout: 10000 });

    // Verify we're back in the editor view.
    currentUrl = page.url();
    expect(currentUrl).not.toContain('?language=');
  });

  test('Translation test page displays French content and falls back to English for Spanish', async ({
    page,
    drupal,
    canvas,
  }) => {
    const drupalSite = drupal.drupalSite;

    // Get the ID of the pre-created translation test page from canvas_test_translation.install.
    const getPageIdCommand = `php-eval "
      \\$pages = \\Drupal::entityTypeManager()
        ->getStorage('canvas_page')
        ->loadByProperties(['title' => 'Canvas Translation Test Page']);
      \\$page = reset(\\$pages);
      if (\\$page) {
        echo \\$page->id();
      }
    "`;

    const pageIdResult = await execDrush(getPageIdCommand, {
      url: drupalSite.url,
      userAgent: drupalSite.userAgent,
    });

    const pageId = pageIdResult?.trim();
    expect(pageId).toBeTruthy();

    await page.goto(`/canvas/editor/canvas_page/${pageId}`);
    await canvas.waitForEditorUi();

    // Switch to French language.
    const languageButton = page
      .locator('[data-testid="canvas-topbar"] button')
      .filter({ hasText: /English/ })
      .first();
    await expect(languageButton).toBeVisible();
    await languageButton.click();

    const frenchOption = page
      .locator('[role="menuitem"]')
      .filter({ hasText: /French/ })
      .first();
    await expect(frenchOption).toBeVisible();
    await frenchOption.click();

    await page.waitForURL(/\/preview\/canvas_page\/\d+\/full\?language=fr/, {
      timeout: 10000,
    });

    let previewUrl = page.url();
    expect(previewUrl).toContain(`language=fr`);
    expect(previewUrl).toContain(`/preview/canvas_page/${pageId}/full`);

    let previewFrame = page.frameLocator('iframe[title="Page preview"]');
    await expect(previewFrame.locator('body')).not.toBeEmpty();

    // Verify French content "Bonjour, Canvas!" is displayed
    await expect(previewFrame.locator('text=Bonjour, Canvas!')).toBeVisible({
      timeout: 5000,
    });

    // Verify English content is not displayed.
    await expect(previewFrame.locator('text=Hello, Canvas!')).not.toBeVisible();

    let frameHtmlLang = await previewFrame.locator('html').getAttribute('lang');
    if (frameHtmlLang) {
      expect(frameHtmlLang).toMatch(/^fr/i);
    }

    // Switch to Spanish language (which has no translation).
    const frenchButton = page
      .locator('[data-testid="canvas-topbar"] button')
      .filter({ hasText: /French/ })
      .first();
    await expect(frenchButton).toBeVisible();
    await frenchButton.click();

    const spanishOption = page
      .locator('[role="menuitem"]')
      .filter({ hasText: /Spanish/ })
      .first();
    await expect(spanishOption).toBeVisible();
    await spanishOption.click();

    await page.waitForURL(/\/preview\/canvas_page\/\d+\/full\?language=es/, {
      timeout: 10000,
    });

    previewUrl = page.url();
    expect(previewUrl).toContain(`language=es`);
    expect(previewUrl).toContain(`/preview/canvas_page/${pageId}/full`);

    previewFrame = page.frameLocator('iframe[title="Page preview"]');
    await expect(previewFrame.locator('body')).not.toBeEmpty();

    // Verify English content "Hello, Canvas!" is displayed (fallback).
    await expect(previewFrame.locator('text=Hello, Canvas!')).toBeVisible({
      timeout: 5000,
    });

    // Verify French content is not displayed.
    await expect(
      previewFrame.locator('text=Bonjour, Canvas!'),
    ).not.toBeVisible();

    frameHtmlLang = await previewFrame.locator('html').getAttribute('lang');
    if (frameHtmlLang) {
      expect(frameHtmlLang).toMatch(/^es/i);
    }
  });
});
