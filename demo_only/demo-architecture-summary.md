# Canvas + TMGMT Translation Workflow Demo — Architecture Summary

Branch: `tmgmt-workflow-demo` (50 commits ahead of `1.x`)

> **Translation dashboard architecture** (dashboard controller, job controller, filter form, TMGMT integration) is documented in `modules/tmgmt_canvas/architecture-summary.md`.

## Entry Points (How Users Start Translating)

### 1. Translation Dashboard (Drupal admin)
See `modules/tmgmt_canvas/architecture-summary.md`.

### 2. Canvas Editor Language Selector (frontend)
- **File:** `ui/src/components/languageSelector/LanguageSelector.tsx`
- Globe icon in toolbar → popover with per-language dots menu
- "Edit translation" → opens `/admin/canvas/translate/{type}/{id}/{lang}?origin=canvas` in new tab
- "Delete translation" → opens standard Drupal deletion form
- Only shows "Edit translation" for published entities (reads from `useGetPageLayoutQuery`)
- `?origin=canvas` tells backend to redirect back to Canvas editor after save

## Hook Implementations (canvas.module)

### `canvas_entity_presave()`
- Marks non-source translations as `content_translation_outdated = TRUE` when a new revision is saved
- Needed because Canvas auto-save bypasses the `content_translation` form which normally handles this
- **@todo 🔥🔥🐛🐛** — likely a core bug (affects REST, JSON:API, any non-form save)

### `canvas_entity_prepare_form()`
- Fires before `JobItemForm::buildForm()`
- Pre-populates TMGMT job item form with existing translation data **in memory only**
- Only activates when: form is `JobItemForm`, job item has no translated data in DB, entity has existing translation
- Uses `ContentEntitySource::extractTranslatableData()` on the translated entity
- Sets `#translation.#text` and `#status = TMGMT_DATA_ITEM_STATE_TRANSLATED` via `updateData()`
- Data persists to DB only when user submits — abandoned items remain empty

## Frontend Architecture (Language Selector)

### Key State
- `isPublished` — from `useGetPageLayoutQuery` (NOT config slice — that was a bug)
- `selectIsPublished` from config slice was always false; fixed to read from API response

### Translation Status API
- **Endpoint:** `canvas.api.languages` and `canvas.api.entity_translations`
- **Controller:** `src/Controller/ApiLanguageController.php`
- Returns per-language translation status for the current entity
- Used by language selector to show checkmarks for translated languages

## Event Subscribers

### `CanvasRouteOptionsEventSubscriber`
- Redirects language-prefixed Canvas editor URLs (e.g., `/es/canvas/editor/...`) to default language
- Canvas editor cannot render in non-default languages

## Other Translation-Related Files

| File | Purpose |
|---|---|
| `src/Controller/ApiAutoSaveController.php` | Translation preservation on publish (copies translations to new revisions) |
| `ui/src/services/languages.ts` | RTK Query hooks for languages and translation status |
| `modules/tmgmt_canvas/src/Controller/TranslationDashboardController.php` | Dashboard controller |
| `modules/tmgmt_canvas/src/Controller/TranslationJobController.php` | Job routing controller |
| `modules/tmgmt_canvas/src/Form/TranslationDashboardFilterForm.php` | Filter form embedded in dashboard |
| `modules/tmgmt_canvas/tmgmt_canvas.routing.yml` | Translation routes (dashboard + translate + update) |
| `modules/tmgmt_canvas/tmgmt_canvas.links.menu.yml` | Menu link under Admin > Content |

## Bugs Needing Real Issues (🔥🔥🐛🐛)

These are bugs discovered during demo work that exist independent of this demo workflow and need proper Drupal.org issues:

1. **Core: content_translation_outdated never set outside form layer** (`canvas.module` presave hook)
   - Any entity saved via REST, JSON:API, or custom controllers never flags translations outdated
   - Our workaround: `canvas_entity_presave()` sets the flag manually for `canvas_page`

2. **Canvas: Publishing drops translations** (`src/Controller/ApiAutoSaveController.php:367`)
   - When creating a new revision from auto-save data, existing translations from the previous revision are not carried forward
   - Publishing a page drops all non-default-language translations
   - Issue: https://drupal.org/i/3583684

3. **Canvas: Editor breaks with language prefix URLs** (`src/EventSubscriber/CanvasRouteOptionsEventSubscriber.php:30`)
   - Canvas editor does not support rendering in non-default languages
   - Workaround: redirect `/es/canvas/...` to unprefixed path

4. **Canvas: "Edit translation" showed for unpublished entities** (`LanguageSelector.tsx:228`)
   - Fixed in demo by gating on `isPublished` — but the underlying `isPublished` was reading from wrong source (config slice always returned false)

5. **Canvas: Delete button showed for languages without translations** (`LanguageSelector.tsx:238`)
   - From a merged patch; fixed in demo by gating on `availableTranslations.includes(language.id)`

## Known Limitations

- **Outdated flag is overly broad** — `content_translation_outdated` fires on ANY new revision, even non-translatable changes (layout-only edits)
- **No language selector on content template editor** — must translate from dashboard
- **Page regions have no editor page** — must translate from dashboard
- **Language prefix URLs break Canvas editor** — redirected to default language
- **No continuous job support for config entities** — only `ContentEntitySource` implements `ContinuousSourceInterface`
- **One TMGMT translator must exist** — throws RuntimeException if none configured
- **Partial translations not supported** — all fields mandatory in TMGMT form (out of scope per FR)
- **Delete translation** — opens standard Drupal form, not Canvas-native UX

## Tester Feedback Status

See `demo_only/testing_notes.md` for @lauriii's feedback and responses. All actionable items addressed:
1. Source outdated nudge for untranslated → Fixed (stale discard)
2. Dashboard shows Translated when outdated → Fixed (outdated status + presave hook)
3. New component missing from form → Could not reproduce
4. No outdated indication after adding component → Fixed (presave hook)
5. All fields mandatory → Out of scope (not in FR)
6. Edit translation shows empty form → Fixed (hook_entity_prepare_form)
7. Navigation placement → Keeping current

## Potential Future Work

- Address the core bug (content_translation_outdated not set outside form layer)
- Partial translation support (source language fallback)
- Canvas-native translation UX (inline editing instead of TMGMT form)
- Outdated detection that's field-aware (only flag when translated properties changed)
- Pagination on dashboard for many entities
- Config entity outdated detection
