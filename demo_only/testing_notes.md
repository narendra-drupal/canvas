Feedback from @lauriii Canvas product manager


IMPORTANT: The tester was just using the dashboard not the language selector inside canvas. tested at commit 32a6b650038a7562386bee942954a4d94f6d7267
## Edit Source Language
  * Edit a Canvas Page that had already been published: Modify page title
    * Untranslated scenario
      * Content appears untranslated on the overview (expected). The translation form displays a form to nudge updating the source language. This doesn't seem required in this scenario since the content has not been translated.
      * **Response:** Fixed. Stale job items with no user progress are now discarded and recreated fresh, so the "source outdated" nudge no longer appears when the user never saved any translation progress. See `TranslationJobController::isStaleWithNoProgress()`.
    * Translated scenario
      * Content appears translated on the overview (unexpected since translation is outdated). When I go to the form, it says the translation is outdated. I can resolve the outdated Source but I cannot update the translation when doing this which is confusing.
      * **Response:** Fixed. Dashboard now shows "Outdated" status instead of "Translated" when `content_translation_outdated` flag is set. Also added `hook_entity_presave` to actually set the flag when Canvas publishes new revisions — core only sets it via the content_translation form which Canvas bypasses. This is likely a core bug (any entity saved outside that form never gets translations flagged outdated). TODO: The flag is overly broad — fires on ANY new revision, even non-translatable changes. Additionally, when translation is outdated, clicking "Edit" now skips the read-only accepted view and goes straight to an editable pre-populated form — no confusing extra "click here to update" step.
  * Edit a Canvas Page that had already been published: Add a new component
    * Untranslated scenario
      * Content appears untranslated on the overview (expected). The new component does not appear on the translation form (unexpected).
      * **Response:** Could not reproduce. Tested: 1) Published page with component A. 2) Added component B and published. 3) Clicked "Translate" — both A and B appeared on translation form. Also tested with existing translations (translate lang 1 after step 1, then translate lang 2 after step 2) — new component appeared in both cases.
    * Translated scenario
      * Content appears translated on the overview (unexpected since translation is outdated). There's no indication that the translation is out of date when I enter the form. The new component appears on the form when I edit the translation.
      * **Response:** The "outdated" part is addressed by Fix 1 (dashboard now shows "Outdated"). The new component appearing on the form is correct expected behavior.
## Create Translation
  * All fields are currently mandatory to be filled. Users should be able to not translate some of the content in which case the system should fall back to the original language.
  * **Response:** This was not spelled out in the original feature request document (Symmetric-Translations-FR-4773.md). Out of scope for current demo. Where is this documented as a requirement? Would need TMGMT form customization to allow partial translation submission.
## Edit Translation
  * After clicking "Edit translation", the existing translation isn't loaded on the form; I need to start creating the edited translation from scratch.
  * **Response:** Fixed. Added `hook_entity_prepare_form` implementation that pre-populates the TMGMT job item with existing translation data in memory before the form renders. Data only persists to DB when user submits.

* **Navigation**
  * Need to discuss where in the admin navigation the translation overview should be placed.
  * **Response:** Keeping current placement (`/admin/canvas/translations/canvas_page` under Admin > Content). Open to further discussion.
