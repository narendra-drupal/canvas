# Canvas + TMGMT Translation Workflow Demo

## Purpose

Demonstrates how the TMGMT (Translation Management Tool) module integrates with Drupal Canvas to provide a streamlined translation workflow. The goal is to abstract away TMGMT's internal concepts (jobs, job items) so that content creators can translate Canvas pages and config entities without needing to understand the underlying translation management system.

## Features

- **Translation Dashboard** (`/admin/canvas/translations`) — Single filterable page showing per-language translation status. Filter dropdowns for Entity Type (Node, Taxonomy Term, Media, Canvas Page, Content Template, Page Regions), optional Bundle, Language, Title search, and Status. Shows only entity types with at least one translation-enabled bundle.
- **One-click translation** — Users click "Translate" or "Edit" and are taken directly to the TMGMT review form. Jobs and job items are created automatically behind the scenes.
- **Language selector integration** — The Canvas editor's language selector includes "Edit translation" in the per-language dots menu, opening the translation form in a new tab.
- **Smart routing** — The controller detects the current translation state and routes appropriately:
  - No translation exists → creates job item, opens editable form
  - Translation in progress → redirects to existing active job item
  - Translation already accepted → shows read-only view with "Update Translation" option
- **Context-aware redirects** — After saving a translation, users return to where they started (Canvas editor or translation dashboard).
- **Translation preservation** — Existing translations are carried forward when publishing new revisions via Canvas auto-save.
- **Language prefix handling** — Canvas editor URLs with language prefixes (e.g., `/es/canvas/...`) are redirected to the default language to prevent editor breakage.

## Workflows

### Translating from the Canvas Editor

1. Open a page in Canvas editor
2. Publish the page
3. Click the language selector (globe icon) in the toolbar
4. Click the dots menu (⋮) next to a non-default language
5. Click "Edit translation" — opens TMGMT review form in new tab
6. Fill in translations and save
7. Redirected back to Canvas editor

### Translating from the Dashboard

1. Navigate to Admin > Content > Canvas Translations (`/admin/canvas/translations`)
2. Select Entity Type and Language using the filter dropdowns
3. Optionally filter by Bundle, Title, or Status
4. Click "Translate" or "Edit" next to an entity
5. Fill in translations in the TMGMT review form and save
6. Redirected back to the dashboard (preserving entity type and language filters)

### Updating an Existing Translation

1. Click "Edit translation" for an entity that already has a translation
2. View the accepted (read-only) translation form
3. Click the "Click here to update it" link in the status message
4. Edit translations in the new active form and save

## Limitations

- **Demo-quality code** — Not intended for production use without further refinement.
- **Canvas editor does not render in non-default languages** — Language prefix URLs redirect to default language.
- **Translation loss on publish** — Workaround in place to copy translations to new revisions, but needs a proper fix in core Canvas auto-save logic.
- **Delete translation** button in language selector opens the standard Drupal translation deletion form (not a Canvas-native UX).
- **No continuous job support for config entities** — Only `ContentEntitySource` implements `ContinuousSourceInterface`.
- **One TMGMT translator must exist** — The system throws an error if no translator is configured.
- **Content Templates have no language selector** — The Canvas editor for content templates does not include the language selector. Translations for content templates must be initiated from the translation dashboard.
- **Page Regions have no editor page** — Regions are not editable via the Canvas UI. Translations for page regions must be initiated from the translation dashboard.

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

### 4. Create a TMGMT Translator

Navigate to Admin > Translation > Translators (`/admin/tmgmt/translators`) and create a translator:
- **Label:** "Local translator" (or any name)
- **Plugin:** Drupal user

This allows site users to provide translations directly via the TMGMT review form.

### 5. Clear Caches

```bash
drush cr
```

### 6. Verify

1. Create and publish a Canvas page
2. Visit `/admin/canvas/translations` — should see the page listed with language status after selecting Entity Type and Language
3. Click "Translate" for any language — should open the TMGMT review form
4. Open the page in Canvas editor — language selector should show with "Edit translation" in dots menu for non-default languages

## Key Files

| File | Purpose |
|------|---------|
| `src/Controller/TranslationJobController.php` | Auto-creates TMGMT jobs/items, handles smart routing |
| `src/Controller/TranslationDashboardController.php` | Single filterable translation dashboard |
| `src/Form/TranslationDashboardFilterForm.php` | Filter form embedded in dashboard (entity type, bundle, language, title, status) |
| `src/Controller/ApiLanguageController.php` | API endpoint for entity translation status |
| `src/EventSubscriber/CanvasRouteOptionsEventSubscriber.php` | Redirects language-prefixed Canvas URLs |
| `src/Controller/ApiAutoSaveController.php` | Translation preservation on publish |
| `ui/src/components/languageSelector/LanguageSelector.tsx` | Language selector with translation actions |
| `ui/src/services/languages.ts` | RTK Query hooks for languages and translations |
| `canvas.routing.yml` | Route definitions for dashboard and translation actions |
| `canvas.links.menu.yml` | Menu link for dashboard under Admin > Content |
