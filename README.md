# Entry Porter

Copy entries between Craft CMS environments, including their Matrix, Neo and Super Table
blocks. Related elements are matched by UID on the receiving environment, and every import
lands as a draft for review, so nothing is published or overwritten.

A typical use is building content on staging and moving it to production without
re-entering it by hand.

## Requirements

- Craft CMS 5.9 or later
- PHP 8.2 or later

## Installation

Install the plugin on every environment you want to copy between:

```bash
composer require chaseburklund/craft-entry-porter
php craft plugin/install entry-porter
```

The environments should share the same project config (sections, entry types and fields).
Entry Porter checks this on import and refuses payloads that reference a section, entry type
or field the receiving environment does not have.

## Usage

### Copying an entry

On the source environment, open an entry and click **Porter: Copy JSON**. The entry is
exported as JSON and copied to your clipboard.

The export is always the entry's published version. When you are viewing a draft or have
unsaved changes, the button says so.

### Importing an entry

On the receiving environment, go to **Utilities → Entry Porter**, paste the JSON and click
**Import as draft**. The result shows a link to the draft, along with any warnings and any
references that could not be resolved.

An import never publishes and never changes a published entry:

- If the entry does not exist yet, it is created as an unpublished draft.
- If it exists (matched by UID), a new draft of it is created with the imported content.
- If an earlier import of the same entry is still an unapplied draft, that draft is updated
  instead of creating another one.

Review the draft and publish it when it is ready.

### Console commands

```bash
php craft entry-porter/porter/export-entry <entryId> [siteHandle] > entry.json
php craft entry-porter/porter/import-file entry.json
```

Console imports run as the first admin user.

## How related elements are matched

Each related element (entries, assets, categories, tags, users and Formie forms) is exported
as a reference carrying its UID and identifying details such as its section and slug, or its
volume, folder and filename. On import, Entry Porter looks for the element:

1. by UID;
2. if that fails, by those identifying details;
3. for assets, if both fail, by downloading the file from the source environment and
   creating it (see [Configuration](#configuration)).

References that still cannot be matched are dropped and listed in the import report.

## Supported field types

| Field types | Handling |
| --- | --- |
| Plain Text, Lightswitch, Dropdown, Radio Buttons, Checkboxes, Multi-select, Number, Email, URL, Color, Date, Time, Money, Table, Country, Button Group, Icon | Copied as is |
| Entries, Assets, Categories, Tags, Users, Formie Forms | Related elements remapped |
| Matrix, Super Table, Neo | Blocks recreated; their fields handled by type |
| CKEditor, Redactor | Reference tags in the content remapped |
| Link (Craft), Hyper, Typed Link Field | Element links remapped; other links copied as is |
| SEOmatic SEO Settings | Copied as is, with a reminder to review it |
| Image Optimize Optimized Images | Not copied; the receiving environment regenerates it |

Any other field type is copied as is, with a warning in the report. If such a field stores
element IDs, those IDs are not remapped, so check the field on the draft.

## Permissions

Entry Porter adds two user permissions:

- **Export entries as Porter JSON**: shows the Copy JSON button.
- **Import entries from Porter JSON**: allows importing in the utility.

Users also need access to the Entry Porter utility, and the usual permissions to view and
save entries in the section involved.

## Configuration

Create `config/entry-porter.php` to change the defaults:

```php
<?php

return [
    // Download and create assets that the receiving environment does not have.
    'createMissingAssets' => true,
];
```

Assets are only downloaded from public `http` or `https` addresses, up to 100 MB each, and
only for users allowed to save assets in the volume (and to create folders there, if the
folder is missing).

## Limitations

- Each export copies one site's version of an entry.
- CKEditor nested entries are removed from rich text content, with a warning.
- Element references inside custom fields of a Hyper link are not remapped.
- Updating an existing entry does not change its slug, post date or position in a structure;
  the report notes any differences.
- An import cannot create a new entry in a Single section.

## Support

Report issues at <https://github.com/chaseburklund/craft-entry-porter/issues>.

## License

Entry Porter is commercial software. See [LICENSE.md](LICENSE.md).
