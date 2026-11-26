Replaces the standard Drupal edit screen for nodes with an enhanced drag-and-drop editor.

## Content block editing

Enable Content Block types in the Mercury Editor settings
to create and edit them using Mercury Editor. Content blocks are previewed
in a standalone themed page. Preview options let editors select a theme region
and control the block title without changing any placement. Existing
placements of the edited block are omitted from the preview page to avoid
duplicate editing targets; their visibility elsewhere is unchanged. Preview
access requires permission to create or update the block, as appropriate.

## If upgrading from 2.0.x:

- make note of applicable settings under "admin > Settings > Content Authoring > Mercury Editor settings"
- disable all Mercury Editor submodules (see drush command below)
- upgrade to 2.1.x with composer
- clear cache and manually restore applicable settings

`drush pmu mercury_editor_components mercury_editor_layouts mercury_editor_content_moderation mercury_editor_scaffold mercury_editor_dialog mercury_editor_skip_form mercury_editor_edit_tray mercury_editor_style_options mercury_editor_iframe mercury_editor_tabs mercury_editor_inline_editor`
