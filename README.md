# all_arranged

A Textpattern plugin that lays out the Write tab with CSS grid areas, configured with a small JSON object or by dragging items on the Write tab itself.

- **Layout editor:** Publishers can drag fields and groups into place on the Write tab, pick a column split and save.
- **Regions:** Hive's two fixed columns are replaced by named regions placed with `grid-template-areas`. Any field or group can go in any region, in any order.
- **Groups:** fields can be moved into other groups (e.g. media custom fields into the Article image group).
- **Labels:** field labels and group headings can be renamed, on the Write tab only.
- **Section visibility:** fields or whole groups can be shown or hidden per section. The rules follow the Section select live.
- **Custom CSS:** a stylesheet box on the Options page, applied to the Write tab only.

## How it works

Nothing is moved in the browser. The plugin rebuilds the article form on the server before it is sent:

- there is no flash of the original layout, and core JavaScript binds to the final structure;
- elements that core refreshes after an async save keep their selectors, so the updates still land in the right place;
- the custom-fields refresh is filtered (via `article_ui > partials_meta`), so fields placed elsewhere are not re-inserted into the Custom fields group after a save.

If anything fails, the page is sent unchanged. Add `&arranged=0` to the Write tab URL to bypass the plugin for comparison.

### Markup

The rebuilt form follows the markup proposed for Textpattern 5 on the forum, so CSS written against it should carry over:

```html
<form id="article_form" … data-section="products">
  <div class="txp-layout txp-write">
    <div class="txp-write-region" data-region="main">
      <div class="txp-form-field title" data-field="title">…</div>
      <div class="txp-form-field custom-field custom-5 …" data-field="product-dimensions" data-custom="5">…</div>
      …
    </div>
    <div class="txp-write-region" data-region="side">
      <section id="txp-image-group" class="txp-details" data-group="image">…</section>
      …
    </div>
  </div>
</form>
```

- `data-field` on every field (and on the save zone, `actions`, and the Expand all / Collapse all links, `pane-actions`)
- `data-custom="N"` on custom fields
- `data-group` on every group, using a short name (`txp-image-group` → `image`)
- `data-section` on the form, updated when the Section select changes

## Requirements

- Textpattern 4.9 (tested with 4.9.2)
- PHP 8.4+ uses the HTML5 DOM parser; older versions fall back to libxml's
- Hive or a Hive-based admin theme (Classic is untested)
- A current browser (Chrome, Safari, Firefox or Edge)

Tested alongside glz_custom_fields 2.0.6. Disable bot_write_tab_customize (or any other Write tab rearranging script) before using it.

## Installation

1. Zip a folder named `all_arranged` containing `all_arranged.php` and `manifest.json`, and upload it on **Admin → Plugins**. (GitHub's "Download ZIP" names the folder `all_arranged-main`, so rename it first.)
2. Activate the plugin.
3. Open the Write tab and click **Arrange layout**, or enter a layout on the plugin's **Options** page.

## Layout editor

Click **Arrange layout** in the Write heading (Publishers only). In arrange mode:

- inputs are disabled, every group is opened, and each draggable item shows its key;
- items hidden by section rules are shown dimmed, so they can still be placed;
- fields can be dragged within or between columns, and into or out of groups;
- groups can be dragged between columns, but not into other groups;
- fields in a list that core refreshes after a save (such as Custom fields) can be dragged out, but not reordered or dropped back in.

The bar at the bottom of the screen has a **Columns** preset (9/3, 8/4, 6/6, or sidebar on the left at 3/9), **Save layout**, **Cancel**, and **Reset to default** (click twice to confirm).

Saving writes only `regions`, `groups` and `areas`. Labels, section rules and custom CSS are kept, so they can still be edited on the Options page.

## Configuration

Keys refer to fields and groups on the Write tab:

| Item | Key |
|---|---|
| Custom field | its name without the `cf-` prefix (e.g. `product-dimensions`), or `custom-N` |
| Core field | its class name (e.g. `title`, `body`, `excerpt`, `category-1`, `url-title`) |
| Group | its short name (`sort`, `dates`, `categories`, `meta`, `comments`, `image`, `custom-field`, `extended`) or full ID (e.g. `txp-image-group`) |
| Other items | their ID or alias (e.g. `pane-header`, `actions` or `txp-article-actions`) |

| Setting | Purpose |
|---|---|
| `areas` | `grid-template-areas` rows, 12 tokens each (Hive's 12-track grid). Applied above Hive's 61.25em breakpoint; below it, regions stack. |
| `regions` | Region name → ordered list of keys. Unlisted items stay in the region matching their original column (`main` or `side`). |
| `groups` | Group key → ordered list of the keys in that group (what the editor saves), or `{ "prepend": [...], "append": [...] }` lists of fields to move in. |
| `labels` | Key → new label text. |
| `sections` | Key → `{ "only": [sections] }` or `{ "not": [sections] }`. With `only`, the item is hidden in sections you add later; with `not`, it's shown. |

### Example

```json
{
    "areas": ["main main main main main main main main main side side side"],
    "regions": {
        "main": ["pane-header", "title", "author", "excerpt", "body", "custom-field"],
        "side": ["actions", "categories", "pane-actions", "sort", "dates", "meta", "image", "extended"]
    },
    "groups": {
        "sort": ["landing-page", "txp-container-status", "section", "override-form"],
        "image": { "append": ["thumbnail-image", "files", "video"] }
    },
    "labels": {
        "category-1": "Brand",
        "image": "Article media"
    },
    "sections": {
        "landing-page": { "only": ["home"] },
        "custom-field": { "only": ["products", "journal", "projects"] }
    }
}
```

Group lists can include untagged blocks inside the group, by ID or first class (`txp-container-status`, `txp-container`). Anything in a group that isn't listed stays after the listed items.

## Notes

- Elements that core refreshes after a save (author line, article actions, status) are replaced with fresh markup. They stay in place, but they lose any attributes the plugin added, so don't place fields inside them or style them via `data-field`.
- Hive's `#main_content .text` rules (the larger title and body text) are recreated by the plugin, because the wrappers they relied on are removed.
- Collapse all / Expand all are re-attached to the new layout.

## Planned

- Reordering fields within the Custom fields list.
- A free-form column editor, beyond the presets.
- Visibility conditions based on field values as well as sections.
- "Dissolving" a group so its fields become independent grid items.
- Possibly other panels (Images, Files, Links).

## Changes

- **0.2 (24/11/2026):** layout editor; markup aligned with the Textpattern 5 proposal (`data-field`, `data-group`, `data-custom`, `data-region`, `data-section`); section rules use `data-section` instead of `:has()`; group short names; ordered group lists.
- **0.1 (22/11/2026):** first release.

Feedback is welcome in the [TXP5 Custom Fields Management](https://forum.textpattern.com/viewtopic.php?id=52669) forum thread or as GitHub issues.
