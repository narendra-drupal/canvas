Feedback from @lauriii Canvas product manager


IMPORTANT: The tester was just using the dashboard not the language selector inside canvas. tested at commit 32a6b650038a7562386bee942954a4d94f6d7267
## Edit Source Language
  * Edit a Canvas Page that had already been published: Modify page title
    * Untranslated scenario
      * Content appears untranslated on the overview (expected). The translation form displays a form to nudge updating the source language. This doesn't seem required in this scenario since the content has not been translated.
    * Translated scenario
      * Content appears translated on the overview (unexpected since translation is outdated). When I go to the form, it says the translation is outdated. I can resolve the outdated Source but I cannot update the translation when doing this which is confusing.
  * Edit a Canvas Page that had already been published: Add a new component
    * Untranslated scenario
      * Content appears untranslated on the overview (expected). The new component does not appear on the translation form (unexpected).
    * Translated scenario
      * Content appears translated on the overview (unexpected since translation is outdated). There's no indication that the translation is out of date when I enter the form. The new component appears on the form when I edit the translation.
## Create Translation
  * All fields are currently mandatory to be filled. Users should be able to not translate some of the content in which case the system should fall back to the original language.
## Edit Translation
  * After clicking "Edit translation", the existing translation isn't loaded on the form; I need to start creating the edited translation from scratch.

```

* **Navigation**
  * Need to discuss where in the admin navigation the translation overview should be placed.
