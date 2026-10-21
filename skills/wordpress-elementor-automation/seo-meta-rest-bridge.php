<?php
/*
 * Plugin Name: SEO Meta REST Bridge
 * Description: Registers Yoast SEO post meta for REST read/write, adds a REST route for
 *              taxonomy-term Yoast meta (with the indexable sync Yoast 14+ needs), and a
 *              cache-purge route. Writes are gated by the same capabilities the wp-admin
 *              Yoast box already requires, and values are sanitized before storage.
 * Version:     1.0
 *
 * Use only where WP-CLI is not available. With WP-CLI, `wp post meta update` is the
 * native route and needs no code on the site.
 *
 * Install: copy to wp-content/mu-plugins/seo-meta-rest-bridge.php (mu-plugins load without
 *          activation). Before installing, make sure no active regular plugin already
 *          defines these routes: check the namespace list at /wp-json/ for site-ops/v1.
 * Remove:  delete the file. No database changes, no leftover state.
 *
 * Post meta exposed on post, page and (if WooCommerce is active) product:
 *   _yoast_wpseo_title                 SEO title override
 *   _yoast_wpseo_metadesc              meta description
 *   _yoast_wpseo_focuskw               focus keyphrase
 *   _yoast_wpseo_canonical             canonical URL override
 *   _yoast_wpseo_meta-robots-noindex   '1' noindex, '2' index, '' site default
 *
 * Then: POST /wp-json/wp/v2/posts/<id> {"meta": {"_yoast_wpseo_title": "..."}}
 *
 * Routes:
 *   GET  /wp-json/site-ops/v1/term-yoast?taxonomy=<taxonomy>&term_id=<term_id>
 *   POST /wp-json/site-ops/v1/term-yoast   {taxonomy, term_id, title?, description?, focuskw?, canonical?}
 *   POST /wp-json/site-ops/v1/cache-purge
 */

if (!defined('ABSPATH')) {
    exit;
}

// A second copy of this file (or a regular plugin built from it) must not fatal the site.
// The return alone is not enough: PHP binds top-level functions before it runs, so the
// functions below are declared inside if-blocks.
if (function_exists('site_ops_purge_all')) {
    return;
}

if (!function_exists('site_ops_clean_seo_value')) {
    /**
     * Clean a value before storing it. Tags are stripped with wp_strip_all_tags() rather
     * than sanitize_text_field(), which removes %xx octets and would break Yoast variables
     * such as %%category%% or %%date%%.
     */
    function site_ops_clean_seo_value(string $kind, $value): string {
        $value = is_scalar($value) ? (string) $value : '';
        if ($kind === 'url') {
            return esc_url_raw(trim($value));
        }
        if ($kind === 'robots') {
            return in_array($value, ['0', '1', '2'], true) ? $value : '';
        }
        return trim(wp_strip_all_tags($value));
    }
}

add_action('init', function () {
    $keys = [
        '_yoast_wpseo_title'               => 'text',
        '_yoast_wpseo_metadesc'            => 'text',
        '_yoast_wpseo_focuskw'             => 'text',
        '_yoast_wpseo_canonical'           => 'url',
        '_yoast_wpseo_meta-robots-noindex' => 'robots',
    ];
    $types = ['post' => 'edit_posts', 'page' => 'edit_pages'];
    if (post_type_exists('product')) {
        $types['product'] = 'edit_posts';
    }
    foreach ($types as $type => $cap) {
        foreach ($keys as $key => $kind) {
            register_post_meta($type, $key, [
                'show_in_rest'      => true,
                'single'            => true,
                'type'              => 'string',
                'sanitize_callback' => function ($value) use ($kind) {
                    return site_ops_clean_seo_value($kind, $value);
                },
                // Core also requires edit_post on the object before this runs.
                'auth_callback'     => function () use ($cap) {
                    return current_user_can($cap);
                },
            ]);
        }
    }
});

if (!function_exists('site_ops_purge_all')) {
    /**
     * Clear every page cache layer this plugin knows how to reach from PHP.
     * Returns the list of actions taken so the caller can audit it.
     */
    function site_ops_purge_all(): array {
        $done = [];
        wp_cache_flush();
        $done[] = 'wp_cache_flush';
        if (function_exists('breeze_clear_all_cache')) {
            breeze_clear_all_cache();
            $done[] = 'breeze_clear_all_cache_fn';
        }
        if (has_action('breeze_clear_all_cache')) {
            do_action('breeze_clear_all_cache');
            $done[] = 'breeze_clear_all_cache_action';
        }
        if (has_action('breeze_clear_varnish')) {
            do_action('breeze_clear_varnish');
            $done[] = 'breeze_clear_varnish';
        }
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
            $done[] = 'rocket_clean_domain';
        }
        if (function_exists('rocket_clean_minify')) {
            // Without this, layout edits keep loading old combined CSS from cache/min/.
            rocket_clean_minify();
            $done[] = 'rocket_clean_minify';
        }
        return $done;
    }
}

add_action('rest_api_init', function () {
    $fields = [
        'title'       => 'wpseo_title',
        'description' => 'wpseo_desc',
        'focuskw'     => 'wpseo_focuskw',
        'canonical'   => 'wpseo_canonical',
    ];

    // The same check wp-admin applies to the term edit screen, per term.
    $can_edit_term = function (WP_REST_Request $req) {
        $tid = (int) $req->get_param('term_id');
        return $tid > 0 && current_user_can('edit_term', $tid);
    };

    $validate = function ($tax, $tid) {
        if (!$tax || !$tid) {
            return new WP_Error('bad_request', 'taxonomy and term_id required', ['status' => 400]);
        }
        if (!taxonomy_exists($tax)) {
            return new WP_Error('bad_taxonomy', 'unknown taxonomy', ['status' => 404]);
        }
        $term = get_term($tid, $tax);
        if (!$term || is_wp_error($term)) {
            return new WP_Error('bad_term', 'unknown term', ['status' => 404]);
        }
        return true;
    };

    register_rest_route('site-ops/v1', '/term-yoast', [
        [
            'methods'             => 'GET',
            'permission_callback' => $can_edit_term,
            'callback'            => function (WP_REST_Request $req) use ($fields, $validate) {
                $tax = sanitize_key($req->get_param('taxonomy'));
                $tid = (int) $req->get_param('term_id');
                $ok  = $validate($tax, $tid);
                if (is_wp_error($ok)) {
                    return $ok;
                }
                // Yoast keeps term SEO data in one option, keyed by taxonomy and term id.
                $opt   = get_option('wpseo_taxonomy_meta', []);
                $entry = $opt[$tax][$tid] ?? [];
                $out   = ['taxonomy' => $tax, 'term_id' => $tid];
                foreach ($fields as $in => $stored) {
                    $out[$in] = $entry[$stored] ?? '';
                }
                return $out;
            },
        ],
        [
            'methods'             => 'POST',
            'permission_callback' => $can_edit_term,
            'callback'            => function (WP_REST_Request $req) use ($fields, $validate) {
                $p   = $req->get_json_params() ?: $req->get_params();
                $tax = isset($p['taxonomy']) ? sanitize_key($p['taxonomy']) : '';
                $tid = isset($p['term_id']) ? (int) $p['term_id'] : 0;
                $ok  = $validate($tax, $tid);
                if (is_wp_error($ok)) {
                    return $ok;
                }

                $opt = get_option('wpseo_taxonomy_meta', []);
                $opt[$tax]       = $opt[$tax] ?? [];
                $opt[$tax][$tid] = $opt[$tax][$tid] ?? [];
                $written = [];
                foreach ($fields as $in => $stored) {
                    if (array_key_exists($in, $p) && is_string($p[$in])) {
                        $kind = ($in === 'canonical') ? 'url' : 'text';
                        $opt[$tax][$tid][$stored] = site_ops_clean_seo_value($kind, $p[$in]);
                        $written[] = $stored;
                    }
                }
                update_option('wpseo_taxonomy_meta', $opt);

                // Yoast 14+ renders the head from its indexable table. Updating the option
                // alone leaves the rendered title and description stale, so sync the row.
                $sync  = 'none';
                $entry = $opt[$tax][$tid];
                if (function_exists('YoastSEO')) {
                    try {
                        $repo = YoastSEO()->classes->get(\Yoast\WP\SEO\Repositories\Indexable_Repository::class);
                        $idx  = $repo->find_by_id_and_type($tid, 'term', false);
                        if ($idx) {
                            if (isset($entry['wpseo_title']))     { $idx->title = $entry['wpseo_title']; }
                            if (isset($entry['wpseo_desc']))      { $idx->description = $entry['wpseo_desc']; }
                            if (isset($entry['wpseo_focuskw']))   { $idx->primary_focus_keyword = $entry['wpseo_focuskw']; }
                            if (isset($entry['wpseo_canonical'])) { $idx->canonical = $entry['wpseo_canonical']; }
                            $idx->save();
                            $sync = 'updated';
                        }
                    } catch (\Throwable $e) {
                        $sync = 'repository_failed';
                    }
                }
                if ($sync !== 'updated') {
                    // Fallback: drop the row; Yoast rebuilds it from the option on the next render.
                    global $wpdb;
                    $table = $wpdb->prefix . 'yoast_indexable';
                    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)))) {
                        $deleted = $wpdb->delete($table, ['object_id' => $tid, 'object_type' => 'term']);
                        $sync = ($deleted !== false) ? 'row_deleted' : 'failed';
                    }
                }

                clean_term_cache($tid, $tax);
                return [
                    'status'         => 'ok',
                    'taxonomy'       => $tax,
                    'term_id'        => $tid,
                    'written'        => $written,
                    'entry'          => $entry,
                    'indexable_sync' => $sync,
                    'purged'         => site_ops_purge_all(),
                ];
            },
        ],
    ]);

    register_rest_route('site-ops/v1', '/cache-purge', [
        'methods'             => 'POST',
        'permission_callback' => function () { return current_user_can('manage_options'); },
        'callback'            => function () {
            return ['status' => 'ok', 'actions' => site_ops_purge_all()];
        },
    ]);
});
