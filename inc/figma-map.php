<?php

if (!defined('ABSPATH')) {
    exit;
}

define('MATRIX_QC_FIGMA_MAP_OPTION', 'matrix_qc_figma_map');

/**
 * Candidate locations for the seed CSV (the existing manual QC tracker).
 *
 * @return string[]
 */
function matrix_qc_snag_seed_csv_candidates() {
    $candidates = array(
        get_stylesheet_directory() . '/old/qc-figma-map.csv',
        get_template_directory() . '/old/qc-figma-map.csv',
        get_stylesheet_directory() . '/old/St Pats ready for review - Sheet1.csv',
    );

    /**
     * Filter the candidate CSV paths used to seed the page -> Figma map.
     * Projects can point this at their own export. The map is optional;
     * per-element Figma links work without it.
     *
     * @param string[] $candidates
     */
    return apply_filters('matrix_qc_snag_seed_csv_candidates', $candidates);
}

/**
 * Normalize a URL or path to a comparable path key, e.g. "/about-us/".
 *
 * @param string $url_or_path
 * @return string
 */
function matrix_qc_snag_normalize_path($url_or_path) {
    $path = $url_or_path;
    if (strpos($url_or_path, 'http') === 0) {
        $parsed = wp_parse_url($url_or_path);
        $path   = isset($parsed['path']) ? $parsed['path'] : '/';
    }
    $path = '/' . trim((string) $path, '/');
    if ($path !== '/') {
        $path .= '/';
    }
    return strtolower($path);
}

/**
 * Build the page -> Figma map from the seed CSV and store it as an option.
 * Existing entries are preserved unless overwrite is requested.
 *
 * @param bool $overwrite
 * @return int number of rows imported
 */
function matrix_qc_snag_seed_figma_map($overwrite = false) {
    $existing = get_option(MATRIX_QC_FIGMA_MAP_OPTION, array());
    if (!is_array($existing)) {
        $existing = array();
    }
    if (!empty($existing) && !$overwrite) {
        return 0;
    }

    $csv_path = '';
    foreach (matrix_qc_snag_seed_csv_candidates() as $candidate) {
        if (file_exists($candidate)) {
            $csv_path = $candidate;
            break;
        }
    }
    if ($csv_path === '') {
        update_option(MATRIX_QC_FIGMA_MAP_OPTION, $existing);
        return 0;
    }

    $rows    = array();
    $handle  = fopen($csv_path, 'r');
    if ($handle === false) {
        return 0;
    }

    $header = fgetcsv($handle);
    $cols   = array();
    if (is_array($header)) {
        foreach ($header as $index => $name) {
            $cols[strtoupper(trim((string) $name))] = $index;
        }
    }
    $col_url     = isset($cols['PAGE URL']) ? $cols['PAGE URL'] : 1;
    $col_name    = isset($cols['NAME']) ? $cols['NAME'] : 2;
    $col_desktop = isset($cols['FIGMA DESKTOP']) ? $cols['FIGMA DESKTOP'] : 3;
    $col_mobile  = isset($cols['FIGMA MOBILE']) ? $cols['FIGMA MOBILE'] : 4;

    while (($data = fgetcsv($handle)) !== false) {
        $url = isset($data[$col_url]) ? trim($data[$col_url]) : '';
        if ($url === '') {
            continue;
        }
        $key = matrix_qc_snag_normalize_path($url);
        $rows[$key] = array(
            'name'    => isset($data[$col_name]) ? trim($data[$col_name]) : '',
            'desktop' => matrix_qc_snag_clean_figma_value(isset($data[$col_desktop]) ? $data[$col_desktop] : ''),
            'mobile'  => matrix_qc_snag_clean_figma_value(isset($data[$col_mobile]) ? $data[$col_mobile] : ''),
        );
    }
    fclose($handle);

    $merged = array_merge($existing, $rows);
    update_option(MATRIX_QC_FIGMA_MAP_OPTION, $merged);

    return count($rows);
}

/**
 * Treat placeholder values such as "No design" as empty.
 *
 * @param string $value
 * @return string
 */
function matrix_qc_snag_clean_figma_value($value) {
    $value = trim((string) $value);
    if ($value === '' || stripos($value, 'no design') === 0) {
        return '';
    }
    if (strpos($value, 'http') !== 0) {
        return '';
    }
    return esc_url_raw($value);
}

/**
 * Parse a Figma URL into its file key and node id.
 * Dev URLs use node-id=758-2594; the API/MCP expect 758:2594.
 *
 * @param string $url
 * @return array{url:string,file_key:string,node_id:string}
 */
function matrix_qc_snag_parse_figma_url($url) {
    $out = array('url' => '', 'file_key' => '', 'node_id' => '');
    $url = trim((string) $url);
    if ($url === '' || strpos($url, 'figma.com') === false) {
        return $out;
    }

    $out['url'] = esc_url_raw($url);
    $parsed     = wp_parse_url($url);

    if (!empty($parsed['path']) &&
        preg_match('#/(?:design|file|make|board|slides|proto)/([A-Za-z0-9]+)#', $parsed['path'], $m)) {
        $out['file_key'] = $m[1];
    }
    if (!empty($parsed['query'])) {
        parse_str($parsed['query'], $q);
        if (!empty($q['node-id'])) {
            $out['node_id'] = str_replace('-', ':', sanitize_text_field($q['node-id']));
        }
    }
    return $out;
}

/**
 * Produce a human "open in Figma" URL that focuses the node for all users.
 * Removes the m=dev flag (Dev Mode), which otherwise drops viewers without a
 * Dev seat onto the file/board instead of the linked node. Keeps node-id.
 *
 * @param string $url
 * @return string
 */
function matrix_qc_snag_figma_view_url($url) {
    $url = trim((string) $url);
    if ($url === '' || strpos($url, 'figma.com') === false) {
        return $url;
    }
    $parts = wp_parse_url($url);
    if (empty($parts['query'])) {
        return $url;
    }
    parse_str($parts['query'], $q);
    unset($q['m']);

    $scheme = isset($parts['scheme']) ? $parts['scheme'] . '://' : 'https://';
    $host   = isset($parts['host']) ? $parts['host'] : '';
    $path   = isset($parts['path']) ? $parts['path'] : '';
    $query  = http_build_query($q);
    $frag   = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

    return $scheme . $host . $path . ($query !== '' ? '?' . $query : '') . $frag;
}

/**
 * Best-effort template path for a flexi block slug.
 * Block slugs use hyphens (content-cta); template files use underscores (content_cta.php).
 *
 * @param string $component
 * @return string
 */
function matrix_qc_snag_template_hint($component) {
    $component = trim((string) $component);
    if ($component === '') {
        return '';
    }
    $hint = 'template-parts/flexi/' . str_replace('-', '_', $component) . '.php';

    /**
     * Filter the likely template path for a block slug so non-flexi projects
     * can map their own conventions.
     *
     * @param string $hint
     * @param string $component
     */
    return apply_filters('matrix_qc_snag_template_hint', $hint, $component);
}

/**
 * Build a ready-to-use coding-agent instruction for a snag, including the
 * stable block slug / likely template file and the element's Tailwind classes.
 *
 * @param array<string,mixed> $snag shaped by matrix_qc_snag_to_array()
 * @return string
 */
function matrix_qc_snag_agent_instruction($snag) {
    $figma = !empty($snag['figma_element']) ? $snag['figma_element'] : $snag['figma_node'];

    $lines = array();
    if (!empty($figma)) {
        $lines[] = 'Implement this design from Figma. ' . $figma;
        $lines[] = '';
    }
    $lines[] = 'Snag: ' . $snag['description'];
    $lines[] = 'Page: ' . $snag['page_url'];
    if (!empty($snag['component'])) {
        $lines[] = 'Block: ' . $snag['component'];
        $hint = matrix_qc_snag_template_hint($snag['component']);
        if ($hint !== '') {
            $lines[] = 'Likely template: ' . $hint;
        }
    }
    if (!empty($snag['classes'])) {
        $lines[] = 'Element classes (Tailwind): ' . $snag['classes'];
    }
    $lines[] = 'Runtime selector: ' . $snag['selector'];
    $lines[] = 'Viewport: ' . $snag['viewport'];

    return implode("\n", $lines);
}

/**
 * Look up the Figma reference for a page path and viewport.
 *
 * @param string $path
 * @param string $viewport desktop|mobile
 * @return string Figma URL or empty string
 */
function matrix_qc_snag_get_figma_node($path, $viewport = 'desktop') {
    $map = get_option(MATRIX_QC_FIGMA_MAP_OPTION, array());
    if (!is_array($map)) {
        return '';
    }
    $key = matrix_qc_snag_normalize_path($path);
    if (!isset($map[$key])) {
        return '';
    }
    $entry = $map[$key];
    if ($viewport === 'mobile' && !empty($entry['mobile'])) {
        return $entry['mobile'];
    }
    return isset($entry['desktop']) ? $entry['desktop'] : '';
}
