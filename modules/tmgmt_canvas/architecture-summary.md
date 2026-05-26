# Canvas TMGMT — Architecture Summary

## Goal

Abstract away TMGMT's internal concepts (jobs, job items, states) so Canvas content creators can translate pages, content templates, and page regions without understanding the underlying translation management system. One-click translate → fill form → done.

## Entry Point: Translation Dashboard

- **URL:** `/admin/canvas/translations`
- **Controller:** `src/Controller/TranslationDashboardController.php`
- **Filter form:** `src/Form/TranslationDashboardFilterForm.php`
- Single page with filter dropdowns: Entity Type (required, default: Node), Bundle (conditional — shown when entity type has multiple translatable bundles), Language (required, default: first non-default language alphabetically), Title search, Status
- Shows flat table with Title | Status | Action | View columns (one row per entity for the selected language)
- Status: "Not translated" / "Translated" / "Outdated"
- Only shows published content entities; all config entities shown
- Supported entity types: Node, Taxonomy Term, Media, Canvas Page (content); Canvas Content Template, Canvas Global Regions (config, when `canvas_dev_translation` is installed)
- Only shows entity types that are actually translation-enabled (`content_translation.manager`)

## Core Routing Logic

### TranslationJobController (`src/Controller/TranslationJobController.php`)

Single entry point: `/admin/canvas/translate/{entity_type}/{entity_id}/{target_language}`

After save, redirects to `/admin/canvas/translations?entity_type={type}&langcode={lang}`.

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

## Context-Aware Redirects

After saving the TMGMT form, users return to where they started:
- From dashboard → redirect back to `/admin/canvas/translations?entity_type={type}&langcode={lang}` (via `?destination=`)
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

## Required Modules

```
canvas tmgmt tmgmt_content tmgmt_local tmgmt_config content_translation
```

`canvas_dev_translation` additionally enables translation features for Canvas config entities (content templates, page regions).
