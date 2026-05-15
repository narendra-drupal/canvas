import { execDrush } from '@drupal-canvas/test-utils';
import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

// cspell:ignore région
/**
 * Tests for the Language Selector component.
 * Tests language switching functionality and URL query parameters.
 */

test.use({
  modules: ['canvas_test_sdc', 'canvas_test_recipe'],
  enableTestExtensions: true,
});

test.describe('Language Selector', () => {
  // Since we're using isolatedPerTest, each test gets a fresh environment, so
  // this setup must run before each test.
  test.beforeEach(async ({ drupal }) => {
    await drupal.loginAsAdmin();
    await drupal.applyRecipe(
      `modules/contrib/canvas/tests/fixtures/recipes/test_translation`,
    );
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
    const count = languageOptions;

    await expect(count).toHaveCount(3);
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
    await expect(languageButton).toBeVisible();
    await languageButton.click();

    // Wait for the dropdown menu to open.
    await page.waitForSelector('[role="menuitem"]', {
      state: 'visible',
      timeout: 5000,
    });

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

    // Get the ID of the pre-created translation test page from the recipe content fixture.
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

    // Verify French page content "Bonjour, Canvas!" is displayed.
    await expect(previewFrame.locator('text=Bonjour, Canvas!')).toBeVisible({
      timeout: 5000,
    });

    // Verify French page region content "Bonjour de la région" is displayed.
    await expect(previewFrame.locator('text=Bonjour de la région')).toBeVisible(
      {
        timeout: 5000,
      },
    );

    // Verify English content is not displayed.
    await expect(previewFrame.locator('text=Hello, Canvas!')).toBeHidden();
    await expect(
      previewFrame.locator('text=Hello from region'),
    ).toBeHidden();

    // Verify page region is in French.
    let frameHtmlLang = await previewFrame.locator('html').getAttribute('lang');
    expect(frameHtmlLang).toMatch(/^fr/i);

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

    // Verify English page content "Hello, Canvas!" is displayed (fallback).
    await expect(previewFrame.locator('text=Hello, Canvas!')).toBeVisible({
      timeout: 5000,
    });

    // Verify English page region content "Hello from region" is displayed (fallback).
    await expect(previewFrame.locator('text=Hello from region')).toBeVisible({
      timeout: 5000,
    });

    // Verify French content is not displayed.
    await expect(
      previewFrame.locator('text=Bonjour, Canvas!'),
    ).toBeHidden();
    await expect(
      previewFrame.locator('text=Bonjour de la région'),
    ).toBeHidden();

    // Verify page region is in Spanish.
    frameHtmlLang = await previewFrame.locator('html').getAttribute('lang');
    expect(frameHtmlLang).toMatch(/^es/i);
  });
});
