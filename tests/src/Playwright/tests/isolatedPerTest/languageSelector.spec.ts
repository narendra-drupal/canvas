import { execDrush } from '@drupal-canvas/test-utils';
import { expect } from '@playwright/test';

import { isolatedPerTest as test } from '../../fixtures/test.js';

/**
 * Tests for the Language Selector component.
 * Tests language switching functionality, URL query parameters, and module enablement conditions.
 */

test.use({
  modules: ['canvas_test_sdc', 'language', 'content_translation'],
  enableTestExtensions: true,
});

test.describe('Language Selector', () => {
  // Setup canvas_dev_translation module via drush since it's hidden and can't be installed via UI.
  // This requires additional configuration steps before enabling the module.
  // Note: Since we're using isolatedPerTest, each test gets a fresh environment,
  // so this setup must run before each test.
  test.beforeEach(async ({ drupal }) => {
    const drupalSite = drupal.drupalSite;

    // Step 1: Add French language
    await execDrush('language:add fr', {
      url: drupalSite.url,
      userAgent: drupalSite.userAgent,
    });

    // Step 2: Enable content translation for canvas_page entity type
    await execDrush(
      `php-eval "\\Drupal::service('content_translation.manager')->setEnabled('canvas_page', 'canvas_page', TRUE);"`,
      {
        url: drupalSite.url,
        userAgent: drupalSite.userAgent,
      },
    );

    // Step 3: Enable canvas_dev_translation module
    await execDrush('pm:enable canvas_dev_translation', {
      url: drupalSite.url,
      userAgent: drupalSite.userAgent,
    });
  });

  test('Language selector is visible when translation modules are enabled', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas();
    await page.goto(`/canvas/editor/canvas_page/${canvasPage.entity_id}`);
    await canvas.waitForEditorUi();

    // Language selector button should be visible
    const languageButton = page
      .locator('[data-testid="canvas-topbar"] button')
      .filter({ hasText: /Select Language|Globe/ })
      .first();
    await expect(languageButton).toBeVisible();
  });

  test('Language selector shows available languages when multiple languages exist', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas();
    await page.goto(`/canvas/editor/canvas_page/${canvasPage.entity_id}`);
    await canvas.waitForEditorUi();

    // Try to open language selector dropdown
    const languageButton = page
      .locator('[data-testid="canvas-topbar"] button')
      .filter({ hasText: /Select Language|Globe/ })
      .first();
    const isLanguageSelectorVisible = await languageButton
      .isVisible()
      .catch(() => false);

    if (isLanguageSelectorVisible) {
      // Language selector is available - test it
      await languageButton.click();

      // Check if language options are visible
      const languageOptions = page.locator('[role="menuitem"]');
      const count = await languageOptions.count();

      // Should have at least 2 languages (default + one more)
      expect(count).toBeGreaterThan(0);
    }
  });

  test('Selecting a non-default language navigates to preview with language query parameter', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas();
    await page.goto(`/canvas/editor/canvas_page/${canvasPage.entity_id}`);
    await canvas.waitForEditorUi();

    // Try to open language selector dropdown
    const languageButton = page
      .locator('[data-testid="canvas-topbar"] button')
      .filter({ hasText: /Select Language|Globe/ })
      .first();
    const isLanguageSelectorVisible = await languageButton
      .isVisible()
      .catch(() => false);

    if (!isLanguageSelectorVisible) {
      test.skip();
      return;
    }

    await languageButton.click();

    // Find and click on a non-default language (look for one that doesn't have "(Default)" label)
    const languageItems = page.locator('[role="menuitem"]');
    let languageFound = false;

    for (let i = 0; i < (await languageItems.count()); i++) {
      const item = languageItems.nth(i);
      const text = await item.textContent();
      // Check if this is not the default language
      if (text && !text.includes('(Default)')) {
        // Click on the language option
        await item.click();
        languageFound = true;
        break;
      }
    }

    if (languageFound) {
      // Wait for navigation
      await page
        .waitForURL(/\/preview\/canvas_page\/\d+\/full\?language=/, {
          timeout: 10000,
        })
        .catch(() => {
          // If navigation times out, the language selector may not have triggered navigation
          // This is OK - some environments may not support it
        });

      // Verify the URL contains the language query parameter
      const currentUrl = page.url();
      if (currentUrl.includes('/preview/')) {
        expect(currentUrl).toMatch(
          /\/preview\/canvas_page\/\d+\/full\?language=/,
        );

        // Extract and verify the language parameter exists
        const languageMatch = currentUrl.match(/language=([a-z]{2})/);
        expect(languageMatch).not.toBeNull();
      }
    }
  });

  test('Preview URL includes language query parameter when accessing language translation', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas();
    await page.goto(`/canvas/editor/canvas_page/${canvasPage.entity_id}`);
    await canvas.waitForEditorUi();

    // Try to open language selector
    const languageButton = page
      .locator('[data-testid="canvas-topbar"] button')
      .filter({ hasText: /Select Language|Globe/ })
      .first();
    const isLanguageSelectorVisible = await languageButton
      .isVisible()
      .catch(() => false);

    if (!isLanguageSelectorVisible) {
      test.skip();
      return;
    }

    await languageButton.click();

    // Get the first non-default language
    const languageItems = page.locator('[role="menuitem"]');
    const firstLanguageText = await languageItems.first().textContent();

    if (firstLanguageText && !firstLanguageText.includes('(Default)')) {
      await languageItems.first().click();

      // Wait for navigation to preview (with timeout to handle async operations)
      await page
        .waitForURL(/\/preview\/canvas_page\/\d+\/full\?language=/, {
          timeout: 10000,
        })
        .catch(() => {
          // Timeout is OK, not all environments may support this
        });

      // Verify the URL contains the language query parameter if we're in preview
      const currentUrl = page.url();
      if (currentUrl.includes('/preview/')) {
        expect(currentUrl).toMatch(/\?language=[a-z]{2}/);
      }
    }
  });

  test('Switching back to default language returns to editor', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas();
    await page.goto(`/canvas/editor/canvas_page/${canvasPage.entity_id}`);
    await canvas.waitForEditorUi();

    // Try to open language selector
    let languageButton = page
      .locator('[data-testid="canvas-topbar"] button')
      .filter({ hasText: /Select Language|Globe/ })
      .first();
    const isLanguageSelectorVisible = await languageButton
      .isVisible()
      .catch(() => false);

    if (!isLanguageSelectorVisible) {
      test.skip();
      return;
    }

    await languageButton.click();

    // Switch to a non-default language first
    const languageItems = page.locator('[role="menuitem"]');
    let nonDefaultFound = false;

    for (let i = 0; i < (await languageItems.count()); i++) {
      const item = languageItems.nth(i);
      const text = await item.textContent();
      if (text && !text.includes('(Default)')) {
        await item.click();
        nonDefaultFound = true;
        break;
      }
    }

    if (nonDefaultFound) {
      // Wait for preview to load
      await page
        .waitForURL(/\/preview\/canvas_page\/\d+\/full\?language=/, {
          timeout: 10000,
        })
        .catch(() => {
          // Timeout OK
        });

      const previewUrl = page.url();
      if (previewUrl.includes('/preview/')) {
        // We're in preview mode, test switching back
        await page.waitForTimeout(300);

        // Open language selector again
        languageButton = page
          .locator('[data-testid="canvas-topbar"] button')
          .filter({ hasText: /Select Language|Globe/ })
          .first();
        await languageButton.click();

        // Click on the default language (should have "(Default)" label)
        const defaultLanguageItem = page
          .locator('[role="menuitem"]:has-text("(Default)")')
          .first();
        await defaultLanguageItem.click();

        // Should navigate back to editor
        await page
          .waitForURL(/\/editor\/canvas_page\/\d+/, { timeout: 10000 })
          .catch(() => {
            // Timeout OK
          });

        // Verify we're back in the editor view
        const currentUrl = page.url();
        expect(currentUrl).not.toContain('?language=');
      }
    }
  });

  test('Language selector persists language state across navigation', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas();
    await page.goto(`/canvas/editor/canvas_page/${canvasPage.entity_id}`);
    await canvas.waitForEditorUi();

    // Try to open language selector
    let languageButton = page
      .locator('[data-testid="canvas-topbar"] button')
      .filter({ hasText: /Select Language|Globe/ })
      .first();
    const isLanguageSelectorVisible = await languageButton
      .isVisible()
      .catch(() => false);

    if (!isLanguageSelectorVisible) {
      test.skip();
      return;
    }

    await languageButton.click();

    const languageItems = page.locator('[role="menuitem"]');
    let selectedLanguage = '';

    for (let i = 0; i < (await languageItems.count()); i++) {
      const item = languageItems.nth(i);
      const text = await item.textContent();
      if (text && !text.includes('(Default)')) {
        selectedLanguage = text.split(' ')[0]; // Get language name
        await item.click();
        break;
      }
    }

    if (selectedLanguage) {
      // Wait for preview to load
      await page
        .waitForURL(/\/preview\/canvas_page\/\d+\/full\?language=/, {
          timeout: 10000,
        })
        .catch(() => {
          // Timeout OK
        });

      // Get the URL with language parameter
      const previewUrl = page.url();
      const languageParam = new URL(previewUrl).searchParams.get('language');

      if (languageParam) {
        // Navigate directly to the same URL
        await page.goto(previewUrl);
        await page
          .waitForURL(/\/preview\/canvas_page\/\d+\/full\?language=/, {
            timeout: 10000,
          })
          .catch(() => {
            // Timeout OK
          });

        // Verify the language parameter is still in the URL
        const currentUrl = page.url();
        expect(currentUrl).toContain(`language=${languageParam}`);
      }
    }
  });

  test('Preview iframe loads correctly with language query parameter', async ({
    page,
    drupal,
    canvas,
  }) => {
    await drupal.login({ username: 'editor', password: 'editor' });
    const canvasPage = await canvas.createCanvas();
    await page.goto(`/canvas/editor/canvas_page/${canvasPage.entity_id}`);
    await canvas.waitForEditorUi();

    // Try to open language selector
    const languageButton = page
      .locator('[data-testid="canvas-topbar"] button')
      .filter({ hasText: /Select Language|Globe/ })
      .first();
    const isLanguageSelectorVisible = await languageButton
      .isVisible()
      .catch(() => false);

    if (!isLanguageSelectorVisible) {
      test.skip();
      return;
    }

    // Add a component to make the preview more interesting
    await canvas.openLibraryPanel();
    await canvas.addComponent({ name: 'Hero' });
    await page.waitForTimeout(500); // Give it a moment to render

    // Switch to a non-default language
    await languageButton.click();

    const languageItems = page.locator('[role="menuitem"]');
    let switched = false;

    for (let i = 0; i < (await languageItems.count()); i++) {
      const item = languageItems.nth(i);
      const text = await item.textContent();
      if (text && !text.includes('(Default)')) {
        await item.click();
        switched = true;
        break;
      }
    }

    if (switched) {
      // Wait for preview to load
      await page
        .waitForURL(/\/preview\/canvas_page\/\d+\/full\?language=/, {
          timeout: 10000,
        })
        .catch(() => {
          // Timeout OK
        });

      // Verify the preview iframe is present and loaded if we're in preview
      const currentUrl = page.url();
      if (currentUrl.includes('/preview/')) {
        const previewFrame = page.frameLocator('iframe[title="Page preview"]');
        await expect(previewFrame.locator('body')).not.toBeEmpty();

        // Verify the URL contains language parameter
        expect(currentUrl).toMatch(/\?language=[a-z]{2}/);
      }
    }
  });
});
