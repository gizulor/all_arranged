<?php

/**
 * all_arranged — Write tab layout via CSS grid areas.
 *
 * The article form is rebuilt on the server before it is sent:
 * - Hive's two columns and their wrappers are removed; items are re-homed into
 *   plugin regions (direct children of .txp-layout) placed by grid-template-areas.
 * - Fields can be moved into other groups, relabelled, and shown or hidden per
 *   section (CSS :has(), so it follows the Section select live).
 * - Output follows the markup proposed for txp5: .txp-layout.txp-write holding
 *   .txp-write-region[data-region] wrappers; fields tagged data-field (plus
 *   data-custom="N" for custom fields), groups data-group, and the form
 *   data-section (kept current by JS when the Section select changes).
 * - After an async save, core's custom-fields refresh is filtered so fields
 *   placed elsewhere are not re-inserted into the custom-fields group.
 *
 * Layout and custom CSS are edited on the plugin's Options page.
 * Append &arranged=0 to the Write tab URL to bypass everything.
 */

if (txpinterface === 'admin') {
    add_privs('plugin_prefs.all_arranged', '1');
    register_callback('all_arranged_options', 'plugin_prefs.all_arranged');
    register_callback('all_arranged_lifecycle', 'plugin_lifecycle.all_arranged');
    register_callback('all_arranged_buffer', 'article', '', 1);
    register_callback('all_arranged_head', 'admin_side', 'head_end');
    register_callback('all_arranged_partials', 'article_ui', 'partials_meta');
}

/* -------------------------------------------------------------------------
 * Config
 * ---------------------------------------------------------------------- */

function all_arranged_default_layout()
{
    return array(
        'areas'   => array('main main main main main main main main main side side side'),
        'regions' => array('main' => array(), 'side' => array()),
    );
}

/**
 * Normalised config. Keys:
 * areas    : grid-template-areas rows (12 tokens each, Hive's 12-track grid).
 * regions  : region => ordered keys. Any field or group can be listed.
 * origin   : 'main' | 'side' => region for unlisted top-level items.
 * groups   : group key => {prepend: [keys], append: [keys]} into its content.
 * labels   : key => text (field label or group heading).
 * sections : key => {only: [sections]} or {not: [sections]}.
 */

function all_arranged_config()
{
    static $config;

    if ($config !== null) {
        return $config;
    }

    $config = json_decode((string) get_pref('all_arranged_layout', ''), true);

    if (!is_array($config) || empty($config['regions']) || !is_array($config['regions'])) {
        $config = all_arranged_default_layout();
    }

    $config += array('areas' => array(), 'origin' => array(), 'groups' => array(), 'labels' => array(), 'sections' => array());
    $names = array_keys($config['regions']);
    $config['origin'] += array(
        'main' => $names[0],
        'side' => in_array('side', $names, true) ? 'side' : end($names),
    );

    return $config;
}

/**
 * Keys of fields that the config places outside their native container.
 */

function all_arranged_moved_keys($config)
{
    $keys = array();

    foreach ($config['regions'] as $list) {
        $keys = array_merge($keys, (array) $list);
    }

    foreach ($config['groups'] as $ops) {
        if (all_arranged_is_list($ops)) {
            $keys = array_merge($keys, $ops);
            continue;
        }

        foreach (array('prepend', 'append') as $op) {
            $keys = array_merge($keys, isset($ops[$op]) ? (array) $ops[$op] : array());
        }
    }

    return array_flip($keys);
}

/**
 * Group config as an ordered list of keys (editor format), rather than
 * {prepend, append} (hand-written format).
 */

function all_arranged_is_list($value)
{
    return is_array($value) && ($value === array() || array_keys($value) === range(0, count($value) - 1));
}

function all_arranged_enabled()
{
    return gps('arranged') !== '0';
}

/* -------------------------------------------------------------------------
 * Page transform
 * ---------------------------------------------------------------------- */

function all_arranged_buffer($event, $step)
{
    global $app_mode;

    if ($app_mode !== 'async' && all_arranged_enabled()) {
        ob_start('all_arranged_transform');
    }
}

/**
 * Buffer callback. Any failure returns the page untouched.
 */

function all_arranged_transform($html)
{
    try {
        if (!preg_match('/<form\b[^>]*\bid="article_form"[^>]*>/', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }

        $start = $m[0][1];
        $end = strpos($html, '</form>', $start);

        if ($end === false) {
            return $html;
        }

        $end += strlen('</form>');
        $out = all_arranged_rebuild(substr($html, $start, $end - $start), all_arranged_config());

        return $out === null ? $html : substr_replace($html, $out, $start, $end - $start);
    } catch (\Throwable $e) {
        return $html;
    }
}

function all_arranged_parse($fragment)
{
    if (class_exists('\Dom\HTMLDocument')) {
        return \Dom\HTMLDocument::createFromString(
            '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $fragment . '</body></html>',
            LIBXML_NOERROR,
            'UTF-8'
        );
    }

    $doc = new \DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><html><body>' . $fragment . '</body></html>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    return $doc;
}

function all_arranged_rebuild($fragment, $config)
{
    $doc = all_arranged_parse($fragment);
    $form = $doc->getElementById('article_form');
    $layout = $form ? all_arranged_child($form, 'txp-layout') : null;
    $colMain = $layout ? all_arranged_child($layout, 'txp-layout-4col-3span') : null;
    $colSide = $layout ? all_arranged_child($layout, 'txp-layout-4col-alt') : null;

    if (!$colMain || !$colSide) {
        return null;
    }

    // Top-level items in DOM order: array(node, origin).
    $items = array();

    foreach (all_arranged_elements($colMain) as $node) {
        if ($node->getAttribute('id') === 'main_content') {
            foreach (all_arranged_elements($node) as $sub) {
                if ($sub->getAttribute('id') === 'pane-text') {
                    foreach (all_arranged_elements($sub) as $field) {
                        $field->setAttribute('data-arranged-from', 'text');
                        $items[] = array($field, 'main');
                    }
                } else {
                    $items[] = array($sub, 'main');
                }
            }
        } else {
            $items[] = array($node, 'main');
        }
    }

    foreach (all_arranged_elements($colSide) as $node) {
        if ($node->getAttribute('id') === 'supporting_content') {
            foreach (all_arranged_elements($node) as $sub) {
                $items[] = array($sub, 'side');
            }
        } else {
            $items[] = array($node, 'side');
        }
    }

    // Index every item, field and group by its keys, and tag them.
    $index = array();

    foreach ($items as $item) {
        all_arranged_tag($item[0], $index);
    }

    all_arranged_tag_tree($form, $index);

    // Move fields into groups.
    foreach ($config['groups'] as $groupKey => $ops) {
        $content = isset($index[$groupKey]) ? all_arranged_group_content($index[$groupKey]) : null;

        if (!$content) {
            continue;
        }

        if (all_arranged_is_list($ops)) {
            all_arranged_order_group($content, $ops, $index);
            continue;
        }

        if (!empty($ops['prepend'])) {
            $ref = $content->firstChild;

            foreach ((array) $ops['prepend'] as $key) {
                if (isset($index[$key])) {
                    $content->insertBefore($index[$key], $ref);
                }
            }
        }

        if (!empty($ops['append'])) {
            foreach ((array) $ops['append'] as $key) {
                if (isset($index[$key])) {
                    $content->appendChild($index[$key]);
                }
            }
        }
    }

    // Labels.
    foreach ($config['labels'] as $key => $text) {
        if (isset($index[$key])) {
            all_arranged_relabel($index[$key], (string) $text);
        }
    }

    // Regions.
    $regions = array();

    foreach (array_keys($config['regions']) as $name) {
        $region = $doc->createElement('div');
        $region->setAttribute('class', 'txp-write-region');
        $region->setAttribute('data-region', $name);
        $regions[$name] = $region;
    }

    foreach ($config['regions'] as $name => $keys) {
        foreach ((array) $keys as $key) {
            if (isset($index[$key]) && !$index[$key]->hasAttribute('data-arranged-placed')) {
                $regions[$name]->appendChild($index[$key]);
                $index[$key]->setAttribute('data-arranged-placed', '1');
            }
        }
    }

    foreach ($items as $item) {
        list($node, $origin) = $item;

        if ($node->hasAttribute('data-arranged-placed')) {
            $node->removeAttribute('data-arranged-placed');
        } elseif ($node->parentNode && all_arranged_in_column($node, $colMain, $colSide)) {
            // Still where core put it (not moved into a group): use its origin region.
            $name = isset($regions[$config['origin'][$origin]]) ? $config['origin'][$origin] : key($regions);
            $regions[$name]->appendChild($node);
        }
    }

    foreach ($regions as $region) {
        foreach (all_arranged_elements($region) as $node) {
            $node->removeAttribute('data-arranged-placed');
        }

        $layout->insertBefore($region, $colMain);
    }

    $layout->removeChild($colMain);
    $layout->removeChild($colSide);
    $layout->setAttribute('class', $layout->getAttribute('class') . ' txp-write');

    // Current section on the form, for plain-CSS visibility rules.
    $form->setAttribute('data-section', all_arranged_selected($doc->getElementById('section')));

    return $doc->saveHTML($form);
}

/**
 * Order a group's content from a full key list (editor format).
 *
 * Keys are looked up among the group's own children first (so a shared key
 * such as "txp-container" means this group's container), then anywhere in
 * the form. Children not listed keep their relative order, after the list.
 */

function all_arranged_order_group($content, $keys, &$index)
{
    $own = array();

    foreach (all_arranged_elements($content) as $child) {
        foreach (all_arranged_child_keys($child) as $key) {
            if (!isset($own[$key])) {
                $own[$key] = $child;
            }
        }
    }

    $listed = array();

    foreach ($keys as $key) {
        $node = isset($own[$key]) ? $own[$key] : (isset($index[$key]) ? $index[$key] : null);

        if ($node && !$node->hasAttribute('data-arranged-listed')) {
            $node->setAttribute('data-arranged-listed', '1');
            $listed[] = $node;
        }
    }

    $unlisted = array();

    foreach (all_arranged_elements($content) as $child) {
        if (!$child->hasAttribute('data-arranged-listed')) {
            $unlisted[] = $child;
        }
    }

    foreach (array_merge($listed, $unlisted) as $node) {
        $node->removeAttribute('data-arranged-listed');
        $content->appendChild($node);
    }
}

/**
 * Keys a group child answers to: its data-field / data-group, then the
 * computed keys (ID or first class for untagged blocks).
 */

function all_arranged_child_keys($node)
{
    $keys = array();

    foreach (array('data-field', 'data-group') as $attr) {
        if ($value = $node->getAttribute($attr)) {
            $keys[] = $value;
        }
    }

    return array_values(array_unique(array_merge($keys, all_arranged_keys($node))));
}

function all_arranged_in_column($node, $colMain, $colSide)
{
    for ($p = $node->parentNode; $p; $p = $p->parentNode) {
        if ($p === $colMain || $p === $colSide) {
            return true;
        }
    }

    return false;
}

/* -------------------------------------------------------------------------
 * Keys and tagging
 * ---------------------------------------------------------------------- */

function all_arranged_classes($node)
{
    return preg_split('/\s+/', trim((string) $node->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY);
}

/**
 * All keys for a node, canonical first.
 * Custom fields: name (from cf-*), then custom-N. Other fields: name class.
 * Groups and others: id, then first class.
 */

function all_arranged_keys($node)
{
    $classes = all_arranged_classes($node);
    $keys = array();

    if (in_array('txp-form-field', $classes, true)) {
        $custom = null;

        foreach ($classes as $c) {
            if (preg_match('/^cf-(.+)$/', $c, $m)) {
                $keys[] = $m[1];
            } elseif (preg_match('/^custom-\d+$/', $c)) {
                $custom = $c;
            }
        }

        if ($custom) {
            $keys[] = $custom;
        }

        if (!$keys) {
            foreach (array_reverse($classes) as $c) {
                if (!preg_match('/^(txp-|glz-)/', $c)) {
                    $keys[] = $c;
                    break;
                }
            }
        }
    }

    if ($id = $node->getAttribute('id')) {
        $keys[] = $id;
    }

    if (!$keys && $classes) {
        $keys[] = $classes[0];
    }

    return array_values(array_unique($keys));
}

/**
 * Tag a node and index it under all its keys.
 *
 * Groups: data-group="short name" (txp-image-group → image).
 * Everything else: data-field="key", plus data-custom="N" for custom fields.
 */

function all_arranged_tag($node, &$index)
{
    static $fieldAliases = array(
        'txp-article-actions' => 'actions',
        'txp-actions'         => 'pane-actions',
    );

    $keys = all_arranged_keys($node);

    if (!$keys) {
        return;
    }

    if (all_arranged_is_group($node)) {
        $id = (string) $node->getAttribute('id');
        $short = preg_replace(array('/^txp-(write-)?/', '/-group$/'), '', $id);
        $group = $short !== '' ? $short : $keys[0];
        $node->setAttribute('data-group', $group);
        $keys[] = $group;
    } else {
        $field = isset($fieldAliases[$keys[0]]) ? $fieldAliases[$keys[0]] : $keys[0];
        $node->setAttribute('data-field', $field);
        $keys[] = $field;

        foreach ($keys as $key) {
            if (preg_match('/^custom-(\d+)$/', $key, $m)) {
                $node->setAttribute('data-custom', $m[1]);
            }
        }
    }

    foreach (array_unique($keys) as $key) {
        if (!isset($index[$key])) {
            $index[$key] = $node;
        }
    }
}

/**
 * Groups: .txp-details sections, plus any <section> with an ID
 * (core outputs #txp-extended-group without .txp-details).
 */

function all_arranged_is_group($node)
{
    return in_array('txp-details', all_arranged_classes($node), true)
        || (strtolower($node->nodeName) === 'section' && (string) $node->getAttribute('id') !== '');
}

/**
 * Value of a select's selected option (first option if none is marked).
 */

function all_arranged_selected($select)
{
    if (!$select) {
        return '';
    }

    $first = null;

    foreach (all_arranged_elements($select) as $option) {
        if (strtolower($option->nodeName) !== 'option') {
            continue;
        }

        if ($first === null) {
            $first = $option;
        }

        if ($option->hasAttribute('selected')) {
            return (string) $option->getAttribute('value');
        }
    }

    return $first ? (string) $first->getAttribute('value') : '';
}

/**
 * Tag every field and group below $root.
 */

function all_arranged_tag_tree($root, &$index)
{
    foreach (all_arranged_elements($root) as $node) {
        $classes = all_arranged_classes($node);

        if (in_array('txp-form-field', $classes, true) || all_arranged_is_group($node)) {
            all_arranged_tag($node, $index);
        }

        all_arranged_tag_tree($node, $index);
    }
}

/* -------------------------------------------------------------------------
 * Groups and labels
 * ---------------------------------------------------------------------- */

/**
 * A group's content element: its first child that isn't the heading.
 */

function all_arranged_group_content($group)
{
    foreach (all_arranged_elements($group) as $child) {
        if (strtolower($child->nodeName) !== 'h3') {
            return $child;
        }
    }

    return null;
}

/**
 * Replace the first text in a field label or group heading, keeping any
 * help link or twisty markup around it.
 */

function all_arranged_relabel($node, $text)
{
    $target = null;

    foreach (all_arranged_elements($node) as $child) {
        $name = strtolower($child->nodeName);

        if ($name === 'h3') {
            $target = $child;
            break;
        }

        if (in_array('txp-form-field-label', all_arranged_classes($child), true)) {
            $target = all_arranged_first($child, 'label') ?: $child;
            break;
        }
    }

    if (!$target) {
        return;
    }

    $textNode = all_arranged_first_text($target);

    if ($textNode) {
        $textNode->nodeValue = $text;
    }
}

function all_arranged_first($node, $tag)
{
    foreach (all_arranged_elements($node) as $child) {
        if (strtolower($child->nodeName) === $tag) {
            return $child;
        }

        if ($found = all_arranged_first($child, $tag)) {
            return $found;
        }
    }

    return null;
}

function all_arranged_first_text($node)
{
    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_TEXT_NODE && trim($child->nodeValue) !== '') {
            return $child;
        }

        if ($child->nodeType === XML_ELEMENT_NODE && ($found = all_arranged_first_text($child))) {
            return $found;
        }
    }

    return null;
}

/* -------------------------------------------------------------------------
 * DOM helpers
 * ---------------------------------------------------------------------- */

function all_arranged_elements($node)
{
    $out = array();

    foreach ($node->childNodes as $child) {
        if ($child->nodeType === XML_ELEMENT_NODE) {
            $out[] = $child;
        }
    }

    return $out;
}

function all_arranged_child($node, $class)
{
    foreach (all_arranged_elements($node) as $child) {
        if (in_array($class, all_arranged_classes($child), true)) {
            return $child;
        }
    }

    return null;
}

/* -------------------------------------------------------------------------
 * Async save: keep the custom-fields refresh in step with the layout
 * ---------------------------------------------------------------------- */

function all_arranged_partials($event, $step, &$rs, &$partials)
{
    global $app_mode, $all_arranged_cf_cb;

    // Only filter refreshes; on page render the buffer transform needs every field.
    if ($app_mode !== 'async' || !all_arranged_enabled() || empty($partials['custom_fields']['cb'])) {
        return;
    }

    $all_arranged_cf_cb = $partials['custom_fields']['cb'];
    $partials['custom_fields']['cb'] = 'all_arranged_partial_custom_fields';
}

function all_arranged_partial_custom_fields($rs)
{
    global $all_arranged_cf_cb;

    $html = call_user_func($all_arranged_cf_cb, $rs);

    try {
        $config = all_arranged_config();
        $moved = all_arranged_moved_keys($config);
        $doc = all_arranged_parse('<div id="all-arranged-partial">' . $html . '</div>');
        $root = $doc->getElementById('all-arranged-partial');
        $index = array();

        all_arranged_tag_tree($root, $index);

        foreach ($index as $key => $node) {
            if (isset($moved[$key]) && $node->parentNode) {
                $node->parentNode->removeChild($node);
            }
        }

        foreach ($config['labels'] as $key => $text) {
            if (isset($index[$key]) && $index[$key]->parentNode) {
                all_arranged_relabel($index[$key], (string) $text);
            }
        }

        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }

        return $out;
    } catch (\Throwable $e) {
        return $html;
    }
}

/* -------------------------------------------------------------------------
 * Styles
 * ---------------------------------------------------------------------- */

function all_arranged_css_string($s)
{
    return '"' . addcslashes((string) $s, "\"\\\n\r") . '"';
}

/**
 * CSS selector for a config key: field, group (short name or full ID) or custom-N.
 */

function all_arranged_css_target($key)
{
    $q = all_arranged_css_string($key);
    $selectors = array("[data-field={$q}]", "[data-group={$q}]", "[id={$q}]:not(input, select, textarea)");

    if (preg_match('/^custom-(\d+)$/', $key, $m)) {
        $selectors[] = '[data-custom="' . $m[1] . '"]';
    }

    return ':is(' . implode(', ', $selectors) . ')';
}

function all_arranged_head()
{
    global $event;

    if ($event !== 'article' || !all_arranged_enabled()) {
        return;
    }

    $config = all_arranged_config();
    $areas = implode(' ', array_map('all_arranged_css_string', $config['areas']));
    $place = '';

    foreach (array_keys($config['regions']) as $name) {
        $place .= '        #article_form .txp-write-region[data-region=' . all_arranged_css_string($name) . '] { grid-area: ' . preg_replace('/[^\w-]/', '', $name) . "; }\n";
    }

    // Section visibility, from the form's data-section.
    $sections = '';

    foreach ($config['sections'] as $key => $rule) {
        $target = all_arranged_css_target($key);

        foreach (array('only' => ':not(:is(%s))', 'not' => ':is(%s)') as $type => $wrap) {
            if (empty($rule[$type])) {
                continue;
            }

            $matches = array();

            foreach ((array) $rule[$type] as $section) {
                $matches[] = '[data-section=' . all_arranged_css_string($section) . ']';
            }

            $sections .= '    #article_form' . sprintf($wrap, implode(', ', $matches)) . ' ' . $target . " { display: none; }\n";
        }
    }

    $custom = (string) get_pref('all_arranged_css', '');
    $custom = str_ireplace('</style', '', $custom);

    echo <<<EOT
<style id="all_arranged">
    /* Narrow: regions stack full width, as Hive's columns do. */
    #article_form .txp-write > .txp-write-region { grid-column: 1 / -1; min-width: 0; }

    @media (width > 61.25em) {
        #article_form .txp-write { grid-template-areas: {$areas}; }
{$place}    }

    /* Hive compat: replaces #main_content .text rules lost with the wrappers. */
    #article_form [data-arranged-from="text"] input { font-size: 1.2307692rem; }
    #article_form [data-arranged-from="text"] textarea { font-size: 1.2307692rem; line-height: 1.5; }
    #article_form [data-arranged-from="text"].title input { width: 100%; font-size: 1.5384615rem; font-weight: 700; }
    #article_form .txp-write .author small { display: block; margin-top: -1em; color: var(--clr-text-demoted); }

    @media (max-width: 47em) {
        #article_form [data-arranged-from="text"] :is(input, textarea),
        #article_form [data-arranged-from="text"].title input { font-size: 1rem; }
    }

    /* Section visibility. */
{$sections}
    /* Custom CSS (Options). */
{$custom}
</style>
<script>
    $(function () {
        // Collapse/Expand all was delegated from #supporting_content, which no longer exists.
        $('#article_form .txp-write')
            .on('click', '.txp-collapse-all', {direction: 'collapse'}, txp_expand_collapse_all)
            .on('click', '.txp-expand-all', {direction: 'expand'}, txp_expand_collapse_all);

        // Keep the form's data-section in step with the Section select.
        $(document).on('change', '#article_form #section', function () {
            this.form.setAttribute('data-section', this.value);
        });
    });
</script>

EOT;

    if (has_privs('plugin_prefs.all_arranged')) {
        all_arranged_editor_assets($config);
    }
}

/* -------------------------------------------------------------------------
 * Layout editor (Publishers only)
 * ---------------------------------------------------------------------- */

function all_arranged_editor_assets($config)
{
    $settings = json_encode(array(
        'areas'   => array_values((array) $config['areas']),
        'regions' => array_keys($config['regions']),
    ), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);

    echo '<script>var all_arranged_editor = ' . $settings . ';</script>' . n;

    echo <<<'EOT'
<style id="all_arranged_editor">
    #pane-header:has(.all-arrange-toggle) { display: flex; align-items: center; justify-content: space-between; gap: 1em; }
    .all-arranging-body { padding-bottom: 6em; }

    #article_form.all-arranging :is(input, select, textarea, button, a, label, .txp-summary) { pointer-events: none; }
    #article_form.all-arranging section[data-group] > :not(h3) { display: block !important; min-height: 2.5em; outline: 1px dashed rgb(0 0 0 / .25); outline-offset: 2px; }
    #article_form.all-arranging [data-arrange-hidden] { display: block !important; opacity: .45; }
    #article_form.all-arranging .txp-write-region { min-height: 4em; outline: 2px dashed rgb(0 0 0 / .2); outline-offset: 6px; }

    #article_form.all-arranging :is(.txp-write-region, section[data-group] > :not(h3), .txp-container) > [data-field],
    #article_form.all-arranging .txp-write-region > [data-group] { position: relative; cursor: grab; }

    #article_form.all-arranging :is(.txp-write-region, section[data-group] > :not(h3), .txp-container) > [data-field]:hover,
    #article_form.all-arranging .txp-write-region > [data-group]:hover { outline: 2px solid #f5c400; }

    #article_form.all-arranging :is(.txp-write-region, section[data-group] > :not(h3), .txp-container) > [data-field]::after,
    #article_form.all-arranging .txp-write-region > [data-group]::after {
        content: attr(data-field) attr(data-group);
        position: absolute; top: 0; right: 0; z-index: 2; pointer-events: none;
        padding: 0 .4em; font: 11px/1.5 ui-monospace, monospace; background: #fff3b0; color: #333;
    }

    .all-arrange-placeholder { visibility: visible !important; outline: 2px dashed #f5c400; background: rgb(245 196 0 / .12); }

    .all-arrange-bar {
        position: fixed; left: 1em; right: 1em; bottom: 1em; z-index: 1000;
        display: flex; flex-wrap: wrap; align-items: center; gap: .5em 1em;
        padding: .6em 1em; background: var(--clr-bkgd-box, #fff); color: var(--clr-text, #333);
        border: 1px solid rgb(0 0 0 / .2); border-radius: 4px; box-shadow: 0 4px 16px rgb(0 0 0 / .2);
    }
    .all-arrange-bar .all-arrange-msg { flex: 1 1 20em; font-size: .9em; opacity: .8; }
</style>
<script>
(function ($) {
    'use strict';

    var cfg = window.all_arranged_editor || {areas: [], regions: []};

    // Two-region presets: [label, main span, sidebar first?]
    var presets = {
        '9-3': ['Main 9 / Sidebar 3', 9, false],
        '8-4': ['Main 8 / Sidebar 4', 8, false],
        '6-6': ['Main 6 / Sidebar 6', 6, false],
        '3-9': ['Sidebar 3 / Main 9', 9, true]
    };

    function presetAreas(id) {
        var p = presets[id], tokens = [], i;

        for (i = 0; i < 12; i++) {
            tokens.push(p[2] ? (i < 12 - p[1] ? 'side' : 'main') : (i < p[1] ? 'main' : 'side'));
        }

        return tokens.join(' ');
    }

    function keyOf(el) {
        return el.getAttribute('data-field') || el.getAttribute('data-group') || el.id || el.classList[0] || '';
    }

    $(function () {
        var $form = $('#article_form'),
            $layout = $form.find('.txp-write'),
            $bar = null,
            chosenAreas = null;

        if (!$layout.length || !$.fn.sortable) {
            return;
        }

        $('<button type="button" class="all-arrange-toggle txp-reduced-ui-button">Arrange layout</button>')
            .appendTo('#pane-header')
            .on('click', start);

        function groupContents() {
            return $form.find('section[data-group]').map(function () {
                return $(this).children(':not(h3)').get(0) || null;
            });
        }

        function start() {
            if ($form.hasClass('all-arranging')) {
                return;
            }

            // Show items hidden by section rules (dimmed) so they can be placed too.
            $form.find('[data-field], [data-group]').each(function () {
                if (getComputedStyle(this).display === 'none') {
                    this.setAttribute('data-arrange-hidden', '');
                }
            });

            $form.addClass('all-arranging');
            $('body').addClass('all-arranging-body');

            var $regions = $layout.children('.txp-write-region'),
                $contents = groupContents(),
                $containers = $contents.children('.txp-container'),
                $targets = $regions.add($contents),
                common = {tolerance: 'pointer', placeholder: 'all-arrange-placeholder', forcePlaceholderSize: true, cursor: 'grabbing'};

            $regions.sortable($.extend({}, common, {
                connectWith: $targets,
                items: '> [data-field], > [data-group]'
            }));

            // Group contents take fields only, not other groups.
            $contents.sortable($.extend({}, common, {
                connectWith: $targets,
                items: '> [data-field]',
                receive: function (e, ui) {
                    if (ui.item.is('[data-group]')) {
                        $(ui.sender).sortable('cancel');
                    }
                }
            }));

            // Containers core refreshes after a save (e.g. Custom fields):
            // fields can be dragged out, but nothing can be dropped in.
            $containers.sortable($.extend({}, common, {
                connectWith: $targets,
                items: '> [data-field]'
            }));

            showBar($regions);
        }

        function showBar($regions) {
            var names = $regions.map(function () { return this.getAttribute('data-region'); }).get(),
                twoRegions = names.length === 2 && names.indexOf('main') > -1 && names.indexOf('side') > -1,
                current = (cfg.areas[0] || '').replace(/\s+/g, ' ').trim(),
                $cols = $('<select class="all-arrange-cols"></select>'),
                matched = false;

            $.each(presets, function (id, p) {
                var areas = presetAreas(id), selected = areas === current;
                matched = matched || selected;
                $cols.append($('<option>').val(id).text(p[0]).prop('selected', selected));
            });

            if (!matched) {
                $cols.prepend($('<option value="">Current (custom)</option>').prop('selected', true));
            }

            $cols.on('change', function () {
                chosenAreas = this.value ? presetAreas(this.value) : null;
                $layout.css('grid-template-areas', chosenAreas ? '"' + chosenAreas + '"' : '');
            });

            $bar = $('<div class="all-arrange-bar" role="toolbar" aria-label="Arrange layout"></div>')
                .append('<strong>Arrange layout</strong>')
                .append(twoRegions ? $('<label>Columns </label>').append($cols) : '')
                .append($('<button type="button" class="publish">Save layout</button>').on('click', save))
                .append($('<button type="button">Cancel</button>').on('click', function () { location.reload(); }))
                .append($('<button type="button">Reset to default</button>').on('click', reset))
                .append('<span class="all-arrange-msg">Drag fields and groups between columns and groups. Fields in a refreshed list (e.g. Custom fields) can be dragged out, not reordered. Labels, section rules and custom CSS are kept.</span>')
                .appendTo('body');
        }

        function serialise() {
            var out = {regions: {}, groups: {}};

            $layout.children('.txp-write-region').each(function () {
                out.regions[this.getAttribute('data-region')] = $(this).children('[data-field], [data-group]').map(function () {
                    return keyOf(this);
                }).get();
            });

            $form.find('section[data-group]').each(function () {
                var content = $(this).children(':not(h3)').get(0);

                if (content) {
                    out.groups[this.getAttribute('data-group')] = $(content).children().map(function () {
                        return keyOf(this);
                    }).get().filter(Boolean);
                }
            });

            if (chosenAreas) {
                out.areas = [chosenAreas];
            }

            return out;
        }

        function message(text) {
            $bar && $bar.find('.all-arrange-msg').text(text);
        }

        function send(data) {
            message('Saving…');

            $.ajax({
                url: 'index.php',
                method: 'POST',
                dataType: 'json',
                data: $.extend({
                    event: 'plugin_prefs.all_arranged',
                    step: 'arrange_save',
                    app_mode: 'async',
                    _txp_token: textpattern._txp_token
                }, data)
            }).done(function (r) {
                if (r && r.ok) {
                    location.reload();
                } else {
                    message('Not saved: ' + (r && r.error ? r.error : 'unexpected response.'));
                }
            }).fail(function () {
                message('Not saved: the request failed.');
            });
        }

        function save() {
            send({layout: JSON.stringify(serialise())});
        }

        function reset() {
            var $button = $(this);

            if (!$button.data('confirm')) {
                $button.data('confirm', true).text('Click again to reset');
                return;
            }

            send({reset: 1});
        }
    });
})(jQuery);
</script>

EOT;
}

/**
 * Async save from the editor: replaces regions, groups and (if sent) areas.
 * Labels, sections, origin and custom CSS are kept.
 */

function all_arranged_arrange_save()
{
    if (!bouncer('arrange_save', array('arrange_save' => true))) {
        all_arranged_json(array('ok' => false, 'error' => 'invalid or expired token; reload the page.'));
    }

    $config = json_decode((string) get_pref('all_arranged_layout', ''), true);

    if (!is_array($config)) {
        $config = all_arranged_default_layout();
    }

    $clean = function ($list) {
        return array_values(array_filter(array_map('strval', (array) $list), 'strlen'));
    };

    if (ps('reset')) {
        $default = all_arranged_default_layout();
        $config['regions'] = $default['regions'];
        $config['areas'] = $default['areas'];
        unset($config['groups']);
    } else {
        $in = json_decode((string) ps('layout'), true);

        if (!is_array($in) || empty($in['regions']) || !is_array($in['regions'])) {
            all_arranged_json(array('ok' => false, 'error' => 'no layout received.'));
        }

        $regions = array();

        foreach ($in['regions'] as $name => $list) {
            $name = preg_replace('/[^\w-]/', '', (string) $name);

            if ($name !== '') {
                $regions[$name] = $clean($list);
            }
        }

        $groups = array();

        foreach (isset($in['groups']) ? (array) $in['groups'] : array() as $group => $list) {
            $groups[(string) $group] = $clean($list);
        }

        $config['regions'] = $regions;
        $config['groups'] = $groups;

        if (!empty($in['areas'])) {
            $areas = array_filter($clean($in['areas']), function ($row) {
                return (bool) preg_match('/^[\w\s.-]+$/', $row);
            });

            if ($areas) {
                $config['areas'] = array_values($areas);
            }
        }
    }

    set_pref('all_arranged_layout', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'all_arranged', PREF_HIDDEN, 'text_input', 0);
    all_arranged_json(array('ok' => true));
}

function all_arranged_json($out)
{
    while (ob_get_level()) {
        ob_end_clean();
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($out);
    exit;
}

/* -------------------------------------------------------------------------
 * Options page and lifecycle
 * ---------------------------------------------------------------------- */

function all_arranged_options($event, $step)
{
    if ($step === 'arrange_save') {
        all_arranged_arrange_save();
    }

    $message = '';
    $layout = (string) get_pref('all_arranged_layout', '');
    $css = (string) get_pref('all_arranged_css', '');

    if ($step === 'save' && bouncer($step, array('save' => true))) {
        $layout = (string) ps('all_arranged_layout');
        $css = (string) ps('all_arranged_css');
        $decoded = json_decode($layout, true);

        if (trim($layout) !== '' && (!is_array($decoded) || empty($decoded['regions']) || !is_array($decoded['regions']))) {
            $message = array('Layout not saved: ' . (json_last_error() ? json_last_error_msg() : 'a "regions" object is required') . '.', E_ERROR);
        } else {
            if (is_array($decoded)) {
                $layout = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }

            set_pref('all_arranged_layout', $layout, 'all_arranged', PREF_HIDDEN, 'text_input', 0);
            set_pref('all_arranged_css', $css, 'all_arranged', PREF_HIDDEN, 'text_input', 0);
            $message = 'Saved.';
        }
    }

    if ($layout === '') {
        $layout = json_encode(all_arranged_default_layout(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    pagetop('all_arranged', $message);

    echo hed('all_arranged', 1, array('class' => 'txp-heading')),
        form(
            inputLabel('all_arranged_layout', '<textarea id="all_arranged_layout" name="all_arranged_layout" class="code" rows="28" spellcheck="false">' . txpspecialchars($layout) . '</textarea>', 'Layout (JSON)', '', array('class' => 'txp-form-field')) .
            inputLabel('all_arranged_css', '<textarea id="all_arranged_css" name="all_arranged_css" class="code" rows="12" spellcheck="false">' . txpspecialchars($css) . '</textarea>', 'Custom CSS (Write tab only)', '', array('class' => 'txp-form-field')) .
            eInput($event) .
            sInput('save') .
            graf(fInput('submit', '', gTxt('save'), 'publish')),
            '', '', 'post', 'txp-edit'
        );
}

function all_arranged_lifecycle($event, $step)
{
    if ($step === 'deleted') {
        safe_delete('txp_prefs', "name IN ('all_arranged_layout', 'all_arranged_css')");
    }
}
