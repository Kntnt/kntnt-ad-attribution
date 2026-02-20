<?php
/**
 * Plugin Name: Test Helpers (Integration Tests Only)
 * Description: REST endpoints for test setup, fixtures, and inspection.
 */

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
    register_rest_route('kntnt-ad-attribution/v1', '/test-trigger-conversion', [
        'methods'             => 'POST',
        'callback'            => function (WP_REST_Request $request) {
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
        },
        'permission_callback' => fn () => current_user_can('manage_options'),
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
});
