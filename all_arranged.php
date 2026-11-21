<?php
/**
 * all_arranged — Write tab layout via CSS grid areas.
 *
 * The article form is rebuilt on the server before it is sent:
 * - Hive's two columns and their wrappers are removed; items are re-homed into
 *   plugin regions (direct children of .txp-layout) placed by grid-template-areas.
 * - Fields can be moved into other groups, relabelled, and shown or hidden per
 *   section (CSS :has(), so it follows the Section select live).
 * - Every field and group is tagged data-arranged="key aliases…" for CSS.
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
        foreach (array('prepend', 'append') as $op) {
            $keys = array_merge($keys, isset($ops[$op]) ? (array) $ops[$op] : array());
        }
    }

    return array_flip($keys);
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
        $region->setAttribute('class', 'all-region');
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
    $layout->setAttribute('class', $layout->getAttribute('class') . ' all-arranged');

    return $doc->saveHTML($form);
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

function all_arranged_tag($node, &$index)
{
    $keys = all_arranged_keys($node);

    if (!$keys) {
        return;
    }

    $node->setAttribute('data-arranged', implode(' ', $keys));

    foreach ($keys as $key) {
        if (!isset($index[$key])) {
            $index[$key] = $node;
        }
    }
}

/**
 * Tag every field and group below $root.
 */

function all_arranged_tag_tree($root, &$index)
{
    foreach (all_arranged_elements($root) as $node) {
        $classes = all_arranged_classes($node);

        if (in_array('txp-form-field', $classes, true) || in_array('txp-details', $classes, true)) {
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
        $place .= '        #article_form .all-region[data-region=' . all_arranged_css_string($name) . '] { grid-area: ' . preg_replace('/[^\w-]/', '', $name) . "; }\n";
    }

    // Section visibility.
    $sections = '';

    foreach ($config['sections'] as $key => $rule) {
        $target = '[data-arranged~=' . all_arranged_css_string($key) . ']';

        foreach (array('only' => ':not(:has(%s))', 'not' => ':has(%s)') as $type => $wrap) {
            if (empty($rule[$type])) {
                continue;
            }

            $options = array();

            foreach ((array) $rule[$type] as $section) {
                $options[] = '[value=' . all_arranged_css_string($section) . ']';
            }

            $has = '#section option:is(' . implode(', ', $options) . '):checked';
            $sections .= '    #article_form' . sprintf($wrap, $has) . ' ' . $target . " { display: none; }\n";
        }
    }

    $custom = (string) get_pref('all_arranged_css', '');
    $custom = str_ireplace('</style', '', $custom);

    echo <<<EOT
<style id="all_arranged">
    /* Narrow: regions stack full width, as Hive's columns do. */
    #article_form .all-arranged > .all-region { grid-column: 1 / -1; min-width: 0; }

    @media (width > 61.25em) {
        #article_form .all-arranged { grid-template-areas: {$areas}; }
{$place}    }

    /* Hive compat: replaces #main_content .text rules lost with the wrappers. */
    #article_form [data-arranged-from="text"] input { font-size: 1.2307692rem; }
    #article_form [data-arranged-from="text"] textarea { font-size: 1.2307692rem; line-height: 1.5; }
    #article_form [data-arranged-from="text"].title input { width: 100%; font-size: 1.5384615rem; font-weight: 700; }
    #article_form .all-arranged .author small { display: block; margin-top: -1em; color: var(--clr-text-demoted); }

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
    // Collapse/Expand all was delegated from #supporting_content, which no longer exists.
    $(function () {
        $('#article_form .all-arranged')
            .on('click', '.txp-collapse-all', {direction: 'collapse'}, txp_expand_collapse_all)
            .on('click', '.txp-expand-all', {direction: 'expand'}, txp_expand_collapse_all);
    });
</script>

EOT;
}

/* -------------------------------------------------------------------------
 * Options page and lifecycle
 * ---------------------------------------------------------------------- */

function all_arranged_options($event, $step)
{
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
