# all_arranged

A Textpattern plugin that lays out the Write tab with CSS grid areas, configured with a small JSON object.

- **Regions:** Hive's two fixed columns are replaced by named regions placed with `grid-template-areas`. Any field or group can go in any region, in any order.
- **Groups:** fields can be moved into other groups (e.g. media custom fields into the Article image group).
- **Labels:** field labels and group headings can be renamed, on the Write tab only.
- **Section visibility:** fields or whole groups can be shown or hidden per section with CSS `:has()`, so they follow the Section select live.
- **Custom CSS:** a stylesheet box on the Options page, applied to the Write tab only.

## How it works

Nothing is moved in the browser. The plugin rebuilds the article form on the server before it is sent:

- there is no flash of the original layout, and core JavaScript binds to the final structure;
- elements that core refreshes after an async save keep their selectors, so the updates still land in the right place;
- the custom-fields refresh is filtered (via `article_ui > partials_meta`), so fields placed elsewhere are not re-inserted into the Custom fields group after a save.

If anything fails, the page is sent unchanged. Add `&arranged=0` to the Write tab URL to bypass the plugin for comparison.

## Requirements

- Textpattern 4.9 (tested with 4.9.2)
- PHP 8.4+ uses the HTML5 DOM parser; older versions fall back to libxml's
- Hive or a Hive-based admin theme (Classic is untested)
- A browser that supports `:has()` (current Chrome, Safari, Firefox and Edge)

Tested alongside glz_custom_fields 2.0.6. Disable bot_write_tab_customize (or any other Write tab rearranging script) before using it.

## Installation

1. Zip a folder named `all_arranged` containing `all_arranged.php` and `manifest.json`, and upload it on **Admin → Plugins**. (GitHub's "Download ZIP" names the folder `all_arranged-main`, so rename it first.)
2. Activate the plugin.
3. Open its **Options** page and enter a layout.

## Configuration

Keys refer to fields and groups on the Write tab:

| Item | Key |
|---|---|
| Custom field | its name without the `cf-` prefix (e.g. `product-dimensions`), or `custom-N` |
| Core field | its class name (e.g. `title`, `body`, `excerpt`, `category-1`, `url-title`) |
| Group | its ID (e.g. `txp-image-group`, `txp-write-sort-group`, `txp-custom-field-group`) |
| Other items | their ID (e.g. `txp-article-actions`, `pane-header`) |

Every field and group gets a `data-arranged="key aliases…"` attribute, so you can also target them from the custom CSS.

| Setting | Purpose |
|---|---|
| `areas` | `grid-template-areas` rows, 12 tokens each (Hive's 12-track grid). Applied above Hive's 61.25em breakpoint; below it, regions stack. |
| `regions` | Region name → ordered list of keys. Unlisted items stay in the region matching their original column (`main` or `side`). |
| `groups` | Group key → `prepend` / `append` lists of fields to move into that group. |
| `labels` | Key → new label text. |
| `sections` | Key → `{ "only": [sections] }` or `{ "not": [sections] }`. With `only`, the item is hidden in sections you add later; with `not`, it's shown. |

### Example

```json
{
    "areas": ["main main main main main main main main main side side side"],
    "regions": {
        "main": ["pane-header", "title", "author", "excerpt", "body", "txp-custom-field-group"],
        "side": ["txp-article-actions", "txp-categories-group", "txp-actions", "txp-write-sort-group",
                 "txp-dates-group", "txp-meta-group", "txp-image-group", "txp-extended-group"]
    },
    "groups": {
        "txp-write-sort-group": { "prepend": ["landing-page"] },
        "txp-image-group": { "append": ["thumbnail-image", "files", "video"] }
    },
    "labels": {
        "category-1": "Brand",
        "txp-image-group": "Article media"
    },
    "sections": {
        "landing-page": { "only": ["home"] },
        "txp-custom-field-group": { "only": ["products", "journal", "projects"] }
    }
}
```

## Notes

- Elements that core refreshes after a save (author line, article actions, status) are replaced with fresh markup. They stay in their region, but they lose any extra attributes, so don't place fields inside them or style them via `data-arranged`.
- Hive's `#main_content .text` rules (the larger title and body text) are recreated by the plugin, because the wrappers they relied on are removed.
- Collapse all / Expand all are re-attached to the new layout.

## Planned

- A drag-and-drop layout editor on the Write tab, writing to the same JSON.
- Visibility conditions based on field values as well as sections.
- "Dissolving" a group so its fields become independent grid items.
- Possibly other panels (Images, Files, Links).

Feedback is welcome in the [TXP5 Custom Fields Management](https://forum.textpattern.com/viewtopic.php?id=52669) forum thread or as GitHub issues.
