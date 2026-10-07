<?php

defined('ABSPATH') || exit;

if (!function_exists('toloka_ui_header')) {
    function toloka_ui_enqueue() {
        wp_enqueue_style('toloka-ui', plugins_url('toloka-ui.css', __FILE__), [], filemtime(__DIR__ . '/toloka-ui.css'));
    }

    function toloka_ui_header($name, $version, $badge, $links) {
        echo '<div class="toloka-ui-header"><span class="toloka-ui-mark">t</span>';
        printf('<div class="toloka-ui-title"><strong>%s</strong><span>tolokacode · v%s</span></div>', esc_html($name), esc_html($version));
        printf('<span class="toloka-ui-badge">%s</span><div class="toloka-ui-links">', esc_html($badge));
        foreach ($links as $label => $url) {
            printf('<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url($url), esc_html($label));
        }
        echo '</div></div>';
    }
}
