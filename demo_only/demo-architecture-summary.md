# Canvas + TMGMT Translation Workflow Demo — Architecture Summary

Branch: `tmgmt-workflow-demo` (50 commits ahead of `1.x`)

## Goal

Abstract away TMGMT's internal concepts (jobs, job items, states) so Canvas content creators can translate pages, content templates, and page regions without understanding the underlying translation management system. One-click translate → fill form → done.

## Entry Points (How Users Start Translating)

### 1. Translation Dashboard (Drupal admin)
- **URL:** `/admin/canvas/translations/canvas_page` (also `/content_template`, `/page_region`)
- **Controller:** `src/Controller/TranslationDashboardController.php`
- Shows per-entity sub-tables with Language | Status | Action columns
- Status: "Not translated" / "Translated" / "Outdated"
- Only shows published content entities
- Tabs via local tasks in `canvas.links.task.yml`

### 2. Canvas Editor Language Selector (frontend)
- **File:** `ui/src/components/languageSelector/LanguageSelector.tsx`
- Globe icon in toolbar → popover with per-language dots menu
- "Edit translation" → opens `/admin/canvas/translate/{type}/{id}/{lang}?origin=canvas` in new tab
- "Delete translation" → opens standard Drupal deletion form
- Only shows "Edit translation" for published entities (reads from `useGetPageLayoutQuery`)
- `?origin=canvas` tells backend to redirect back to Canvas editor after save

## Core Routing Logic

### TranslationJobController (`src/Controller/TranslationJobController.php`)

Single entry point: `/admin/canvas/translate/{entity_type}/{entity_id}/{target_language}`

Smart routing based on state:

```
┌─────────────────────────────────────────────────────────────┐
│ 1. Active/Review job item exists?                           │
│    ├─ Has user progress (translated data)? → Redirect to it│
│    └─ No progress (stale)? → DELETE it, continue below     │
├─────────────────────────────────────────────────────────────┤
│ 2. Translation exists on entity?                            │
│    ├─ Outdated? → Create fresh job item (fall to Case C)   │
│    │   (hook_entity_prepare_form pre-populates form)        │
│    ├─ Not outdated + accepted job item? → Show read-only   │
│    │   with "Click here to update" link                     │
│    └─ Not outdated + no accepted item? → "Update" page     │
├─────────────────────────────────────────────────────────────┤
│ 3. No translation exists → Create fresh job item           │
│    Set STATE_REVIEW, redirect to TMGMT form                 │
└─────────────────────────────────────────────────────────────┘
```

### Update route: `/admin/canvas/translate/{type}/{id}/{lang}/update`
- `TranslationJobController::updateTranslation()`
- Always creates fresh job item (for "Click here to update" flow)

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

## Context-Aware Redirects

After saving the TMGMT form, users return to where they started:
- From dashboard → redirect back to `/admin/canvas/translations/{entity_type}` (via `?destination=`)
- From Canvas editor → redirect back to `/canvas/editor/{entity_type}/{entity_id}` (via `?origin=canvas`)

## TMGMT Integration Details

### Job Management
- `findOrCreateJob()` — reuses existing active job for same source→target language pair, or creates new
- Jobs use the first available TMGMT translator (configured at `/admin/tmgmt/translators`)
- Job items are set to `STATE_REVIEW` after creation (not `STATE_ACTIVE`) so TMGMT form allows saving

### Entity Type Mapping
| Entity Type | TMGMT Plugin | item_type | item_id |
|---|---|---|---|
| `canvas_page` | `content` | `canvas_page` | entity ID |
| `content_template` | `config` | `content_template` | config prefix + ID |
| `page_region` | `config` | `page_region` | config prefix + ID |

### Stale Item Discard Logic
- `isStaleWithNoProgress()` — checks if ALL translatable items in job data have empty `#translation.#text`
- If stale, the job item is deleted and a fresh one is created
- Prevents confusing "source outdated" nudge for users who never started translating

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
| `canvas.routing.yml` | Translation routes (5 routes: 3 dashboard tabs + translate + update) |
| `canvas.links.menu.yml` | Menu link under Admin > Content |
| `canvas.links.task.yml` | Local task tabs for dashboard entity types |

## Required Modules

```
canvas canvas_sdc_test canvas_dev_translation tmgmt_content tmgmt_local tmgmt_config language content_translation
```

`canvas_dev_translation` enables translation features for Canvas entities.

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
