<?php
/**
 * Plugin Name: Test Helpers (Integration Tests Only)
 * Description: REST endpoints for test setup, fixtures, and inspection.
 */

// No update checks. Every fresh Playground otherwise asks wordpress.org (and
// the plugin's Updater asks GitHub) for updates on its first admin page load.
// Playground caches the booted site, database included, for later boots, and
// a site that has received update offers can boot into WordPress maintenance
// mode (HTTP 503 on every request). Tests never update anything.
add_filter('pre_http_request', function ($response, $args, $url) {
    $host = wp_parse_url($url, PHP_URL_HOST);
    return in_array($host, ['api.wordpress.org', 'api.github.com'], true)
        ? new WP_Error('http_request_blocked', 'Blocked in integration tests.')
        : $response;
}, 10, 3);

// Dedup window toggle. The plugin's own override lives in the array option
// kntnt_ad_attr_settings, which test-set-option cannot write, so suites set
// the plain string option test_dedup_seconds instead. 0 or unset keeps the
// plugin default. The plugin's settings override runs at PHP_INT_MAX but only
// applies when a value is saved in settings, which tests never do.
add_filter('kntnt_ad_attr_dedup_seconds', function ($seconds) {
    $override = (int) get_option('test_dedup_seconds', 0);
    return $override > 0 ? $override : $seconds;
});

// Internal traffic. Suites trigger conversions as the logged-in admin
// (test-trigger-conversion), which the plugin treats as internal traffic and
// does not record. The default detection is therefore switched off for the
// whole test environment, as a site does with the documented one-liner
// add_filter('kntnt_ad_attr_is_internal', '__return_false', PHP_INT_MAX).
// Suites that test internal traffic set the string option
// test_internal_default to "on" (set_internal_default) and reset it at their
// end.
add_filter('kntnt_ad_attr_is_internal', function ($is_internal) {
    return get_option('test_internal_default', '') === 'on' ? $is_internal : false;
}, PHP_INT_MAX);

// Anonymous visitors. Playground's server does not pass a request's own
// Cookie header to PHP: it replaces it with every cookie that any earlier
// response has set (php-wasm's HttpCookieStore), so every request carries the
// admin's logged-in cookie from the setup login, whatever curl sends. A
// request with the header "X-Test-Visitor: anonymous" is stripped of the
// WordPress login cookies here, before WordPress determines the current user,
// so it arrives like a browser that has never logged in.
if (($_SERVER['HTTP_X_TEST_VISITOR'] ?? '') === 'anonymous') {
    foreach (array_keys($_COOKIE) as $name) {
        if (str_starts_with($name, 'wordpress_') && $name !== 'wordpress_test_cookie') {
            unset($_COOKIE[$name]);
        }
    }
}

// Plugin cookies per request. For the same reason, a request carries the
// _ad_clicks and _aah_pending of every earlier visitor, and a cookie curl
// sends with -b never arrives. A request with the header
// "X-Test-Plugin-Cookies" carries exactly the plugin cookies the header
// names, in Cookie-header syntax ("_ad_clicks=<hash>:<ts>,…; _aah_pending=…"),
// and none for the value "none". They are replaced here, before the plugin
// reads $_COOKIE. fixtures.sh: plugin_cookies.
if (isset($_SERVER['HTTP_X_TEST_PLUGIN_COOKIES'])) {
    $plugin_cookies = ['_ad_clicks', '_aah_pending', '_ad_last_conv'];
    foreach ($plugin_cookies as $name) {
        unset($_COOKIE[$name]);
    }
    foreach (explode(';', (string) $_SERVER['HTTP_X_TEST_PLUGIN_COOKIES']) as $pair) {
        [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
        if (in_array($name, $plugin_cookies, true)) {
            $_COOKIE[$name] = urldecode($value);
        }
    }
    unset($plugin_cookies, $pair, $name, $value);
}

// Capability withdrawal. Every request carries the admin login (see above),
// so a suite cannot act as a user without the plugin's capability. With the
// string option test_withhold_capability set to "on", kntnt_ad_attr is
// withheld from every user once the plugin's admin page has started loading
// (its load- hook), that is after WordPress has let the user open the page.
// The plugin's own check in the page's action handlers is then what refuses,
// while the login and its nonces stay valid. fixtures.sh:
// withhold_capability.
add_filter('user_has_cap', function (array $allcaps): array {
    if (get_option('test_withhold_capability', '') === 'on' && did_action('load-tools_page_kntnt-ad-attribution') > 0) {
        $allcaps['kntnt_ad_attr'] = false;
    }
    return $allcaps;
});

/*
 * Probe adapters: a click-ID capturer and a conversion reporter that suites
 * switch on through string options (test-set-option), so no add-on plugin
 * is needed. Both are off unless their option is set. The fixtures.sh
 * helpers set_probe_capturers, set_probe_reporter, get_probe_calls,
 * reset_probe_calls and reset_probes wrap the options.
 *
 *   test_probe_capturers  "platform=param[,platform=param…]", e.g.
 *                         "google_ads=gclid", adds those capturers.
 *   test_probe_reporter   "on" registers reporter "probe";
 *                         "supports_test" also sets 'supports_test' => true.
 *   test_probe_calls      List of everything probe's enqueue received, one
 *                         entry per call: attributions, click_ids,
 *                         campaigns, context.
 *   test_probe_recorded   "on" records every kntnt_ad_attr_conversion_recorded
 *                         call (attributions, context) in
 *                         test_probe_recorded_calls (set_probe_recorded,
 *                         get_recorded_calls).
 */

add_action('kntnt_ad_attr_conversion_recorded', function (array $attributions, array $context): void {
    if (get_option('test_probe_recorded', '') !== 'on') {
        return;
    }
    $calls = get_option('test_probe_recorded_calls', []);
    if (!is_array($calls)) {
        $calls = [];
    }
    $calls[] = ['attributions' => $attributions, 'context' => $context];
    update_option('test_probe_recorded_calls', $calls, false);
}, 10, 2);

add_filter('kntnt_ad_attr_click_id_capturers', function (array $capturers): array {
    $spec = (string) get_option('test_probe_capturers', '');
    foreach (array_filter(array_map('trim', explode(',', $spec))) as $pair) {
        [$platform, $parameter] = array_pad(explode('=', $pair, 2), 2, '');
        if ($platform !== '' && $parameter !== '') {
            $capturers[$platform] = $parameter;
        }
    }
    return $capturers;
});

add_filter('kntnt_ad_attr_conversion_reporters', function (array $reporters): array {
    $mode = (string) get_option('test_probe_reporter', '');
    if (!in_array($mode, ['on', 'supports_test'], true)) {
        return $reporters;
    }

    $probe = [
        'enqueue' => function (array $attributions, array $click_ids, array $campaigns, array $context): array {
            $calls = get_option('test_probe_calls', []);
            if (!is_array($calls)) {
                $calls = [];
            }
            $calls[] = [
                'attributions' => $attributions,
                'click_ids'    => $click_ids,
                'campaigns'    => $campaigns,
                'context'      => $context,
            ];
            update_option('test_probe_calls', $calls, false);

            // One job per attribution, deferred a day so WP-Cron leaves it
            // pending for the rest of the run.
            $items = [];
            foreach ($attributions as $hash => $value) {
                $items[] = [
                    'payload'    => ['hash' => $hash, 'value' => $value],
                    'label'      => 'Probe ' . substr((string) $hash, 0, 8),
                    'not_before' => time() + DAY_IN_SECONDS,
                ];
            }
            return $items;
        },
        'process' => fn ($payload): bool => true,
    ];

    if ($mode === 'supports_test') {
        $probe['supports_test'] = true;
    }

    $reporters['probe'] = $probe;
    return $reporters;
});

add_action('rest_api_init', function () {

    // Create tracking URL
    register_rest_route('kntnt-ad-attribution/v1', '/test-create-url', [
        'methods'             => 'POST',
        'callback'            => function (WP_REST_Request $request) {
            $hash      = $request->get_param('hash');
            $target_id = (int) $request->get_param('target_post_id');
            $source    = $request->get_param('source');
            $medium    = $request->get_param('medium');
            $campaign  = $request->get_param('campaign');

            $post_id = wp_insert_post([
                'post_type'   => 'kntnt_ad_attr_url',
                'post_title'  => home_url("/ad/{$hash}"),
                'post_status' => 'publish',
            ]);

            add_post_meta($post_id, '_hash', $hash, true);
            add_post_meta($post_id, '_target_post_id', (string) $target_id, true);
            add_post_meta($post_id, '_utm_source', $source, true);
            add_post_meta($post_id, '_utm_medium', $medium, true);
            add_post_meta($post_id, '_utm_campaign', $campaign, true);

            return new WP_REST_Response(['post_id' => $post_id, 'hash' => $hash], 200);
        },
        'permission_callback' => fn () => current_user_can('manage_options'),
    ]);

    // Set option (for consent state, etc.)
    register_rest_route('kntnt-ad-attribution/v1', '/test-set-option', [
        'methods'             => 'POST',
        'callback'            => function (WP_REST_Request $request) {
            update_option(
                sanitize_key($request->get_param('option')),
                sanitize_text_field($request->get_param('value')),
            );
            return new WP_REST_Response(['success' => true], 200);
        },
        'permission_callback' => fn () => current_user_can('manage_options'),
    ]);

    // Query database (read-only, for assertions)
    register_rest_route('kntnt-ad-attribution/v1', '/test-query', [
        'methods'             => 'GET',
        'callback'            => function (WP_REST_Request $request) {
            global $wpdb;
            $sql = $request->get_param('sql');

            // Safety: only allow SELECT
            if (!str_starts_with(strtoupper(trim($sql)), 'SELECT')) {
                return new WP_REST_Response(['error' => 'Only SELECT allowed'], 400);
            }

            $result = $wpdb->get_row($sql, ARRAY_A);
            return new WP_REST_Response($result ?: [], 200);
        },
        'permission_callback' => fn () => current_user_can('manage_options'),
    ]);

    // Query multiple rows
    register_rest_route('kntnt-ad-attribution/v1', '/test-query-rows', [
        'methods'             => 'GET',
        'callback'            => function (WP_REST_Request $request) {
            global $wpdb;
            $sql = $request->get_param('sql');
            if (!str_starts_with(strtoupper(trim($sql)), 'SELECT')) {
                return new WP_REST_Response(['error' => 'Only SELECT allowed'], 400);
            }
            $results = $wpdb->get_results($sql, ARRAY_A);
            return new WP_REST_Response($results ?: [], 200);
        },
        'permission_callback' => fn () => current_user_can('manage_options'),
    ]);

    // Execute a WordPress action
    register_rest_route('kntnt-ad-attribution/v1', '/test-do-action', [
        'methods'             => 'POST',
        'callback'            => function (WP_REST_Request $request) {
            $action = $request->get_param('action_name');
            do_action($action);
            return new WP_REST_Response(['success' => true], 200);
        },
        'permission_callback' => fn () => current_user_can('manage_options'),
    ]);

    // Get nonce for REST API calls (public — bootstraps cookie-based auth).
    register_rest_route('kntnt-ad-attribution/v1', '/test-nonce', [
        'methods'             => 'GET',
        'callback'            => function () {
            return new WP_REST_Response([
                'nonce'         => wp_create_nonce('wp_rest'),
                'authenticated' => current_user_can('manage_options'),
            ], 200);
        },
        'permission_callback' => '__return_true',
    ]);

    // Flush rewrite rules (needed after plugin activation in Playground)
    register_rest_route('kntnt-ad-attribution/v1', '/test-flush-rewrites', [
        'methods'             => 'POST',
        'callback'            => function () {
            flush_rewrite_rules();
            return new WP_REST_Response(['success' => true], 200);
        },
        'permission_callback' => fn () => current_user_can('manage_options'),
    ]);

    // Create a regular post (target page for tracking URLs)
    register_rest_route('kntnt-ad-attribution/v1', '/test-create-post', [
        'methods'             => 'POST',
        'callback'            => function (WP_REST_Request $request) {
            $post_id = wp_insert_post([
                'post_title'   => $request->get_param('title') ?: 'Test Page',
                'post_content' => 'Test page content.',
                'post_status'  => 'publish',
                'post_type'    => $request->get_param('type') ?: 'page',
            ]);
            return new WP_REST_Response([
                'post_id'   => $post_id,
                'permalink' => get_permalink($post_id),
            ], 200);
        },
        'permission_callback' => fn () => current_user_can('manage_options'),
    ]);

    // Execute arbitrary SQL (INSERT/UPDATE/DELETE for test setup)
    register_rest_route('kntnt-ad-attribution/v1', '/test-execute-sql', [
        'methods'             => 'POST',
        'callback'            => function (WP_REST_Request $request) {
            global $wpdb;
            $sql    = $request->get_param('sql');
            $result = $wpdb->query($sql);
            return new WP_REST_Response([
                'success'       => $result !== false,
                'affected_rows' => $result,
            ], 200);
        },
        'permission_callback' => fn () => current_user_can('manage_options'),
    ]);

    // Update a post's status
    register_rest_route('kntnt-ad-attribution/v1', '/test-update-post-status', [
        'methods'             => 'POST',
        'callback'            => function (WP_REST_Request $request) {
            $post_id = (int) $request->get_param('post_id');
            $status  = sanitize_key($request->get_param('status'));
            $result  = wp_update_post([
                'ID'          => $post_id,
                'post_status' => $status,
            ]);
            return new WP_REST_Response([
                'success' => !is_wp_error($result),
                'post_id' => $result,
            ], 200);
        },
        'permission_callback' => fn () => current_user_can('manage_options'),
    ]);

    // Permanently delete a post
    register_rest_route('kntnt-ad-attribution/v1', '/test-delete-post', [
        'methods'             => 'POST',
        'callback'            => function (WP_REST_Request $request) {
            $post_id = (int) $request->get_param('post_id');
            $result  = wp_delete_post($post_id, true);
            return new WP_REST_Response([
                'success' => $result !== false && $result !== null,
            ], 200);
        },
        'permission_callback' => fn () => current_user_can('manage_options'),
    ]);

    // Get an option value
    register_rest_route('kntnt-ad-attribution/v1', '/test-get-option', [
        'methods'             => 'GET',
        'callback'            => function (WP_REST_Request $request) {
            $option = sanitize_key($request->get_param('option'));
            $value  = get_option($option, null);
            return new WP_REST_Response([
                'option' => $option,
                'value'  => $value,
            ], 200);
        },
        'permission_callback' => fn () => current_user_can('manage_options'),
    ]);

    // Trigger conversion with custom cookies (sets $_COOKIE before firing action)
    $trigger_conversion = function (WP_REST_Request $request) {
        $ad_clicks = $request->get_param('ad_clicks');
        $last_conv = $request->get_param('last_conv');

        // Reset cookie superglobals to prevent WASM PHP state leakage
        // between requests (Playground reuses the PHP process).
        unset($_COOKIE['_ad_clicks'], $_COOKIE['_ad_last_conv']);

        // Inject cookies into $_COOKIE so conversion handler reads them.
        if ($ad_clicks !== null && $ad_clicks !== '') {
            $_COOKIE['_ad_clicks'] = $ad_clicks;
        }
        if ($last_conv !== null) {
            $_COOKIE['_ad_last_conv'] = $last_conv;
        }

        // Set a browser-like User-Agent so Bot_Detector doesn't reject
        // the conversion (curl's default UA matches the bot signature list).
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 IntegrationTest';

        // Capture any Set-Cookie headers from the conversion handler.
        $headers_before = headers_list();
        do_action('kntnt_ad_attr_conversion');
        $headers_after = headers_list();

        // Find new Set-Cookie headers.
        $new_cookies = array_diff($headers_after, $headers_before);
        $cookie_headers = array_values(array_filter($new_cookies, fn ($h) => str_starts_with($h, 'Set-Cookie:')));

        return new WP_REST_Response([
            'success'    => true,
            'set_cookies' => $cookie_headers,
        ], 200);
    };

    register_rest_route('kntnt-ad-attribution/v1', '/test-trigger-conversion', [
        'methods'             => 'POST',
        'callback'            => $trigger_conversion,
        'permission_callback' => fn () => current_user_can('manage_options'),
    ]);

    // The same trigger without a permission check, called without a nonce.
    // It reproduces a form plugin that submits through the REST API without
    // a nonce: WordPress then resets the current user to 0 even when the
    // request carries a logged-in cookie.
    register_rest_route('kntnt-ad-attribution/v1', '/test-trigger-conversion-public', [
        'methods'             => 'POST',
        'callback'            => $trigger_conversion,
        'permission_callback' => '__return_true',
    ]);

    // Clear plugin state from WASM PHP's persistent superglobals.
    // Playground reuses the PHP process, so $_COOKIE, $_GET, and $_POST
    // modifications from previous requests leak into subsequent ones.
    register_rest_route('kntnt-ad-attribution/v1', '/test-clear-cookies', [
        'methods'             => 'POST',
        'callback'            => function () {
            unset(
                $_COOKIE['_ad_clicks'],
                $_COOKIE['_ad_last_conv'],
                $_COOKIE['_aah_pending'],
            );

            // Clear filter-related GET/POST params that might pollute queries.
            $filter_keys = ['date_start', 'date_end', 'utm_source', 'utm_medium', 'utm_campaign', 's', 'search'];
            foreach ($filter_keys as $key) {
                unset($_GET[$key], $_POST[$key]);
            }

            return new WP_REST_Response(['success' => true], 200);
        },
        'permission_callback' => fn () => current_user_can('manage_options'),
    ]);

    // Delete a transient
    register_rest_route('kntnt-ad-attribution/v1', '/test-delete-transient', [
        'methods'             => 'POST',
        'callback'            => function (WP_REST_Request $request) {
            $name = sanitize_key($request->get_param('name'));
            delete_transient($name);
            return new WP_REST_Response(['success' => true], 200);
        },
        'permission_callback' => fn () => current_user_can('manage_options'),
    ]);

    // Switch the plugin's diagnostic logging on or off. enable_logging lives
    // in the array option kntnt_ad_attr_settings, which test-set-option
    // cannot write. Body: {"enabled": "on"|"off"}.
    register_rest_route('kntnt-ad-attribution/v1', '/test-set-logging', [
        'methods'             => 'POST',
        'callback'            => function (WP_REST_Request $request) {
            $settings = get_option('kntnt_ad_attr_settings', []);
            if (!is_array($settings)) {
                $settings = [];
            }
            $settings['enable_logging'] = $request->get_param('enabled') === 'on' ? '1' : '';
            update_option('kntnt_ad_attr_settings', $settings);
            return new WP_REST_Response(['success' => true], 200);
        },
        'permission_callback' => fn () => current_user_can('manage_options'),
    ]);

    // Read (GET) or delete (DELETE) the plugin's log file.
    register_rest_route('kntnt-ad-attribution/v1', '/test-log', [
        [
            'methods'             => 'GET',
            'callback'            => fn () => new WP_REST_Response([
                'contents' => \Kntnt\Ad_Attribution\Plugin::get_instance()->logger->get_contents(),
            ], 200),
            'permission_callback' => fn () => current_user_can('manage_options'),
        ],
        [
            'methods'             => 'DELETE',
            'callback'            => function () {
                \Kntnt\Ad_Attribution\Plugin::get_instance()->logger->clear();
                return new WP_REST_Response(['success' => true], 200);
            },
            'permission_callback' => fn () => current_user_can('manage_options'),
        ],
    ]);
});

// Pending-consent transport override. Set option `test_pending_transport`
// to 'fragment' or 'cookie' via test-set-option; '' keeps the plugin default.
add_filter('kntnt_ad_attr_pending_transport', function (string $transport): string {
    $override = (string) get_option('test_pending_transport', '');
    return $override !== '' ? $override : $transport;
});
