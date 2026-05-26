# Canvas + TMGMT Translation Workflow Demo

## Purpose

Demonstrates how the TMGMT (Translation Management Tool) module integrates with Drupal Canvas to support translation workflows for Canvas pages and config entities.

## Features

- **Language selector integration** — The Canvas editor's language selector lets users switch languages, delete translations, and configure available languages via the per-language dots menu.
- **Translation preservation** — Existing translations are carried forward when publishing new revisions via Canvas auto-save.
- **Language prefix handling** — Canvas editor URLs with language prefixes (e.g., `/es/canvas/...`) are redirected to the default language to prevent editor breakage.

## Limitations

- **Demo-quality code** — Not intended for production use without further refinement.
- **Canvas editor does not render in non-default languages** — Language prefix URLs redirect to default language.
- **Translation loss on publish** — Workaround in place to copy translations to new revisions, but needs a proper fix in core Canvas auto-save logic.
- **Delete translation** button in language selector opens the standard Drupal translation deletion form (not a Canvas-native UX).

## Setup Instructions

### 1. Enable Required Modules

```bash
drush en canvas canvas_sdc_test canvas_dev_translation tmgmt_content tmgmt_local tmgmt_config language content_translation -y
```

Required modules:
- `canvas` — Canvas / Experience Builder
- `canvas_sdc_test` — Test components for Canvas
- `canvas_dev_translation` — Enables translation features for Canvas entities
- `tmgmt_content` — TMGMT content entity source
- `tmgmt_local` — TMGMT local translator (allows Drupal users to translate)
- `tmgmt_config` — TMGMT config entity source
- `language` — Language management
- `content_translation` — Content translation

### 2. Add Languages

```bash
drush language-add fr
drush language-add es
```

Or navigate to Admin > Configuration > Regional and language > Languages (`/admin/config/regional/language`) and add languages.

### 3. Configure Canvas Page for Translation

Navigate to Admin > Configuration > Regional and language > Content language (`/admin/config/regional/content-language`) and enable translation for the "Canvas Page" entity type.

### 4. Clear Caches

```bash
drush cr
```

## Key Files

| File | Purpose |
|------|---------|
| `src/Controller/ApiLanguageController.php` | API endpoint for entity translation status |
| `src/EventSubscriber/CanvasRouteOptionsEventSubscriber.php` | Redirects language-prefixed Canvas URLs |
| `src/Controller/ApiAutoSaveController.php` | Translation preservation on publish |
| `src/Hook/TranslationHooks.php` | Strips non-translatable fields on translation create |
| `ui/src/components/languageSelector/LanguageSelector.tsx` | Language selector with translation actions |
| `ui/src/services/languages.ts` | RTK Query hooks for languages and translations |
