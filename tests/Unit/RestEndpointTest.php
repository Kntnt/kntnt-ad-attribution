<?php
/**
 * Unit tests for Rest_Endpoint.
 *
 * @package Tests\Unit
 * @since   1.0.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Rest_Endpoint;
use Kntnt\Ad_Attribution\Cookie_Manager;
use Kntnt\Ad_Attribution\Consent;
use Brain\Monkey\Functions;
use Brain\Monkey\Actions;
use Tests\Helpers\TestFactory;

/**
 * Creates a Rest_Endpoint with fresh mocked dependencies.
 *
 * @return array{0: Rest_Endpoint, 1: Mockery\MockInterface, 2: Mockery\MockInterface}
 */
function make_rest_endpoint(): array {
    $cm  = Mockery::mock(Cookie_Manager::class);
    $con = Mockery::mock(Consent::class);
    return [new Rest_Endpoint($cm, $con), $cm, $con];
}

/**
 * Installs a fake `$wpdb` whose clicks table holds the given rows.
 *
 * The fake answers any `get_results()` query with the rows whose hash and
 * `clicked_at` both appear among the prepared arguments, so the tests state
 * which click rows exist rather than how the endpoint queries them.
 *
 * @param array<int, array{hash: string, clicked_at: string}> $rows Click rows.
 *
 * @return Mockery\MockInterface The installed wpdb mock.
 */
function fake_clicks_table(array $rows): Mockery\MockInterface {
    $wpdb = TestFactory::wpdb();
    $GLOBALS['wpdb'] = $wpdb;

    $wpdb->shouldReceive('prepare')->andReturnUsing(
        fn (string $sql, ...$args) => ['sql' => $sql, 'args' => array_map('strval', $args)],
    );

    $wpdb->shouldReceive('get_results')->andReturnUsing(
        function (array $prepared) use ($rows): array {
            $hits = array_filter(
                $rows,
                fn (array $row) => in_array($row['hash'], $prepared['args'], true)
                    && in_array($row['clicked_at'], $prepared['args'], true),
            );
            return array_map(fn (array $row) => (object) $row, array_values($hits));
        },
    );

    return $wpdb;
}

// ─── register() ───

describe('Rest_Endpoint::register()', function () {

    it('registers rest_api_init action', function () {
        [$endpoint] = make_rest_endpoint();

        Actions\expectAdded('rest_api_init')->once();

        $endpoint->register();

        expect(true)->toBeTrue();
    });

});

// ─── register_routes() ───

describe('Rest_Endpoint::register_routes()', function () {

    it('registers search-posts and set-cookie routes', function () {
        [$endpoint] = make_rest_endpoint();

        Functions\expect('register_rest_route')->twice();

        $endpoint->register_routes();

        expect(true)->toBeTrue();
    });

});

// ─── check_permission() ───

describe('Rest_Endpoint::check_permission()', function () {

    it('returns true when user has kntnt_ad_attr capability', function () {
        [$endpoint] = make_rest_endpoint();

        Functions\expect('current_user_can')
            ->once()
            ->with('kntnt_ad_attr')
            ->andReturn(true);

        expect($endpoint->check_permission())->toBeTrue();
    });

    it('returns false when user lacks capability', function () {
        [$endpoint] = make_rest_endpoint();

        Functions\expect('current_user_can')
            ->once()
            ->with('kntnt_ad_attr')
            ->andReturn(false);

        expect($endpoint->check_permission())->toBeFalse();
    });

});

// ─── set_cookie() ───

describe('Rest_Endpoint::set_cookie()', function () {

    afterEach(function () {
        unset($_SERVER['REMOTE_ADDR']);
        unset($GLOBALS['wpdb']);
    });

    it('returns 429 when rate limit exceeded', function () {
        [$endpoint, $cm, $con] = make_rest_endpoint();

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        Functions\expect('get_transient')->once()->andReturn(10);

        $request = new WP_REST_Request('POST', '/set-cookie');
        $request->set_param('hashes', [TestFactory::hash('rate-limit') . ':1700000000']);

        $response = $endpoint->set_cookie($request);

        expect($response->get_status())->toBe(429);
        expect($response->get_data()['success'])->toBeFalse();
    });

    it('increments rate limit counter', function () {
        [$endpoint, $cm, $con] = make_rest_endpoint();
        $hash = TestFactory::hash('counter');

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        Functions\expect('get_transient')->once()->andReturn(3);

        // set_transient should be called with incremented count.
        Functions\expect('set_transient')
            ->once()
            ->withArgs(function ($key, $value, $expiry) {
                expect($value)->toBe(4);
                expect($expiry)->toBe(MINUTE_IN_SECONDS);
                return true;
            });

        // A bare hash is malformed — return early.
        $request = new WP_REST_Request('POST', '/set-cookie');
        $request->set_param('hashes', [$hash]);

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => [],
        );

        $response = $endpoint->set_cookie($request);

        expect($response->get_data()['success'])->toBeFalse();
    });

    it('ignores malformed items and writes the well-formed pair', function () {
        [$endpoint, $cm, $con] = make_rest_endpoint();
        $hash = TestFactory::hash('valid');

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        Functions\expect('get_transient')->once()->andReturn(0);
        Functions\expect('set_transient')->once();

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        $con->shouldReceive('check')->andReturn(true);

        fake_clicks_table([
            ['hash' => $hash, 'clicked_at' => '2023-11-14 22:13:20'],
        ]);

        $cm->shouldReceive('parse')->andReturn([]);
        $cm->shouldReceive('add')->once()->with([], $hash, 1700000000)->andReturn([$hash => 1700000000]);
        $cm->shouldReceive('set_clicks_cookie')->once()->with([$hash => 1700000000]);

        $request = new WP_REST_Request('POST', '/set-cookie');
        $request->set_param('hashes', [
            "{$hash}:1700000000",
            'INVALID',
            strtoupper($hash) . ':1700000000',
            "{$hash}:",
            "{$hash}:17000000001",
            "{$hash}:1700000000x",
            42,
        ]);

        $response = $endpoint->set_cookie($request);

        expect($response->get_data()['success'])->toBeTrue();
    });

    it('returns failure when all hashes filtered by get_valid_hashes', function () {
        [$endpoint, $cm, $con] = make_rest_endpoint();
        $hash = TestFactory::hash('unknown');

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        Functions\expect('get_transient')->once()->andReturn(0);
        Functions\expect('set_transient')->once();

        // No published tracking URL has this hash.
        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => [],
        );

        $cm->shouldNotReceive('set_clicks_cookie');

        $request = new WP_REST_Request('POST', '/set-cookie');
        $request->set_param('hashes', ["{$hash}:1700000000"]);

        $response = $endpoint->set_cookie($request);

        expect($response->get_data()['success'])->toBeFalse();
    });

    it('returns failure when consent is not true', function () {
        [$endpoint, $cm, $con] = make_rest_endpoint();
        $hash = TestFactory::hash('no-consent');

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        Functions\expect('get_transient')->once()->andReturn(0);
        Functions\expect('set_transient')->once();

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        // Consent denied.
        $con->shouldReceive('check')->once()->andReturn(false);

        // Cookie methods should NOT be called.
        $cm->shouldNotReceive('parse');
        $cm->shouldNotReceive('set_clicks_cookie');

        $request = new WP_REST_Request('POST', '/set-cookie');
        $request->set_param('hashes', ["{$hash}:1700000000"]);

        $response = $endpoint->set_cookie($request);

        expect($response->get_data()['success'])->toBeFalse();
    });

    it('returns failure when consent is null (undetermined)', function () {
        [$endpoint, $cm, $con] = make_rest_endpoint();
        $hash = TestFactory::hash('null-consent');

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        Functions\expect('get_transient')->once()->andReturn(0);
        Functions\expect('set_transient')->once();

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        // Consent undetermined -- check returns null.
        $con->shouldReceive('check')->once()->andReturn(null);

        // Cookie methods should NOT be called.
        $cm->shouldNotReceive('parse');
        $cm->shouldNotReceive('set_clicks_cookie');

        $request = new WP_REST_Request('POST', '/set-cookie');
        $request->set_param('hashes', ["{$hash}:1700000000"]);

        $response = $endpoint->set_cookie($request);

        expect($response->get_data()['success'])->toBeFalse();
    });

    it('writes the posted pair\'s timestamp, not that of a later click on the same hash', function () {
        [$endpoint, $cm, $con] = make_rest_endpoint();
        $hash = TestFactory::hash('success');

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        Functions\expect('get_transient')->once()->andReturn(0);
        Functions\expect('set_transient')->once();

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        $con->shouldReceive('check')->once()->andReturn(true);

        // Visitor A clicked at 1700000000 (2023-11-14 22:13:20 UTC), and
        // visitor B clicked the same tracking URL two seconds later.
        fake_clicks_table([
            ['hash' => $hash, 'clicked_at' => '2023-11-14 22:13:20'],
            ['hash' => $hash, 'clicked_at' => '2023-11-14 22:13:22'],
        ]);

        $cm->shouldReceive('parse')->once()->andReturn([]);
        $cm->shouldReceive('add')->once()->with([], $hash, 1700000000)->andReturn([$hash => 1700000000]);
        $cm->shouldReceive('set_clicks_cookie')->once()->with([$hash => 1700000000]);

        $request = new WP_REST_Request('POST', '/set-cookie');
        $request->set_param('hashes', ["{$hash}:1700000000"]);

        $response = $endpoint->set_cookie($request);

        expect($response->get_status())->toBe(200);
        expect($response->get_data()['success'])->toBeTrue();
    });

    it('writes nothing for a pair whose timestamp matches no click row', function () {
        [$endpoint, $cm, $con] = make_rest_endpoint();
        $hash = TestFactory::hash('no-row');

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        Functions\expect('get_transient')->once()->andReturn(0);
        Functions\expect('set_transient')->once();

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        $con->shouldReceive('check')->andReturn(true);

        // The hash has a click row, but not at the posted time.
        fake_clicks_table([
            ['hash' => $hash, 'clicked_at' => '2023-11-14 22:13:22'],
        ]);

        $cm->shouldNotReceive('add');
        $cm->shouldNotReceive('set_clicks_cookie');

        $request = new WP_REST_Request('POST', '/set-cookie');
        $request->set_param('hashes', ["{$hash}:1700000000"]);

        $response = $endpoint->set_cookie($request);

        expect($response->get_data()['success'])->toBeFalse();
    });

    it('writes nothing for a bare hash', function () {
        [$endpoint, $cm, $con] = make_rest_endpoint();
        $hash = TestFactory::hash('bare');

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        Functions\expect('get_transient')->once()->andReturn(0);
        Functions\expect('set_transient')->once();

        // The hash is a published tracking URL with a recorded click, as
        // when a browser still holds the previous script's sessionStorage.
        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        $con->shouldReceive('check')->andReturn(true);

        fake_clicks_table([
            ['hash' => $hash, 'clicked_at' => '2023-11-14 22:13:20'],
        ]);

        $cm->shouldNotReceive('add');
        $cm->shouldNotReceive('set_clicks_cookie');

        $request = new WP_REST_Request('POST', '/set-cookie');
        $request->set_param('hashes', [$hash]);

        $response = $endpoint->set_cookie($request);

        expect($response->get_data()['success'])->toBeFalse();
    });

    it('writes only the pairs that match a click row', function () {
        [$endpoint, $cm, $con] = make_rest_endpoint();
        $recorded   = TestFactory::hash('recorded');
        $unrecorded = TestFactory::hash('unrecorded');

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        Functions\expect('get_transient')->once()->andReturn(0);
        Functions\expect('set_transient')->once();

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        $con->shouldReceive('check')->andReturn(true);

        fake_clicks_table([
            ['hash' => $recorded, 'clicked_at' => '2023-11-14 22:13:20'],
        ]);

        $cm->shouldReceive('parse')->andReturn([]);
        $cm->shouldReceive('add')->once()->with([], $recorded, 1700000000)->andReturn([$recorded => 1700000000]);
        $cm->shouldReceive('set_clicks_cookie')->once()->with([$recorded => 1700000000]);

        $request = new WP_REST_Request('POST', '/set-cookie');
        $request->set_param('hashes', ["{$unrecorded}:1700000000", "{$recorded}:1700000000"]);

        $response = $endpoint->set_cookie($request);

        expect($response->get_data()['success'])->toBeTrue();
    });

    it('keeps the latest matching click when several pairs share a hash', function () {
        [$endpoint, $cm, $con] = make_rest_endpoint();
        $hash = TestFactory::hash('twice');

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        Functions\expect('get_transient')->once()->andReturn(0);
        Functions\expect('set_transient')->once();

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        $con->shouldReceive('check')->andReturn(true);

        // The visitor clicked the same tracking URL twice before consenting.
        fake_clicks_table([
            ['hash' => $hash, 'clicked_at' => '2023-11-14 22:13:20'],
            ['hash' => $hash, 'clicked_at' => '2023-11-14 22:23:20'],
        ]);

        $cm->shouldReceive('parse')->andReturn([]);
        $cm->shouldReceive('add')->once()->with([], $hash, 1700000600)->andReturn([$hash => 1700000600]);
        $cm->shouldReceive('set_clicks_cookie')->once()->with([$hash => 1700000600]);

        $request = new WP_REST_Request('POST', '/set-cookie');
        $request->set_param('hashes', ["{$hash}:1700000600", "{$hash}:1700000000"]);

        $response = $endpoint->set_cookie($request);

        expect($response->get_data()['success'])->toBeTrue();
    });

    it('merges new clicks with existing cookie entries', function () {
        [$endpoint, $cm, $con] = make_rest_endpoint();
        $existing_hash = TestFactory::hash('existing');
        $new_hash      = TestFactory::hash('new');

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        Functions\expect('get_transient')->once()->andReturn(0);
        Functions\expect('set_transient')->once();

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        $con->shouldReceive('check')->once()->andReturn(true);

        fake_clicks_table([
            ['hash' => $new_hash, 'clicked_at' => '2023-11-14 22:13:20'],
        ]);

        // Existing cookie has one entry.
        $cm->shouldReceive('parse')->once()->andReturn([$existing_hash => 1699999000]);

        $cm->shouldReceive('add')
            ->once()
            ->with([$existing_hash => 1699999000], $new_hash, 1700000000)
            ->andReturn([$existing_hash => 1699999000, $new_hash => 1700000000]);

        $cm->shouldReceive('set_clicks_cookie')
            ->once()
            ->with([$existing_hash => 1699999000, $new_hash => 1700000000]);

        $request = new WP_REST_Request('POST', '/set-cookie');
        $request->set_param('hashes', ["{$new_hash}:1700000000"]);

        $response = $endpoint->set_cookie($request);

        expect($response->get_data()['success'])->toBeTrue();
    });

});

// ─── search_posts() ───

describe('Rest_Endpoint::search_posts()', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb']);
    });

    it('finds post by exact ID', function () {
        [$endpoint] = make_rest_endpoint();

        Functions\expect('get_post_types')
            ->once()
            ->andReturn(['post' => 'post', 'page' => 'page']);

        $post = TestFactory::post(['ID' => 42, 'post_title' => 'Test', 'post_type' => 'post']);
        Functions\expect('get_post')->once()->with(42)->andReturn($post);

        // The method falls through to slug LIKE search even after ID match.
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->shouldReceive('esc_like')->andReturnArg(0);
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $request = new WP_REST_Request('GET', '/search-posts');
        $request->set_param('search', '42');

        $response = $endpoint->search_posts($request);

        expect($response->get_data())->toHaveCount(1);
        expect($response->get_data()[0]['id'])->toBe(42);
        expect($response->get_data()[0]['title'])->toBe('Test');
    });

    it('resolves URL to post', function () {
        [$endpoint] = make_rest_endpoint();

        Functions\expect('get_post_types')->once()->andReturn(['post' => 'post', 'page' => 'page']);
        Functions\expect('home_url')->andReturnUsing(fn ($path) => 'https://example.com/' . ltrim($path, '/'));
        Functions\expect('url_to_postid')->once()->andReturn(10);

        $post = TestFactory::post(['ID' => 10, 'post_title' => 'About Us', 'post_type' => 'page']);
        Functions\expect('get_post')->once()->with(10)->andReturn($post);

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->shouldReceive('esc_like')->andReturnArg(0);
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $request = new WP_REST_Request('GET', '/search-posts');
        $request->set_param('search', 'about-us/contact');

        $response = $endpoint->search_posts($request);

        $ids = array_column($response->get_data(), 'id');
        expect($ids)->toContain(10);
    });

    it('finds posts by slug LIKE search', function () {
        [$endpoint] = make_rest_endpoint();

        Functions\expect('get_post_types')->once()->andReturn(['post' => 'post', 'page' => 'page']);

        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;

        $wpdb->shouldReceive('esc_like')->andReturnArg(0);
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn([
                (object) ['ID' => 5, 'post_title' => 'My Page', 'post_type' => 'page'],
            ]);

        $request = new WP_REST_Request('GET', '/search-posts');
        $request->set_param('search', 'my-page');

        $response = $endpoint->search_posts($request);

        $ids = array_column($response->get_data(), 'id');
        expect($ids)->toContain(5);
    });

    it('excludes own CPT from results', function () {
        [$endpoint] = make_rest_endpoint();

        // Include the plugin CPT in post_types list — it should be excluded.
        Functions\expect('get_post_types')
            ->once()
            ->andReturn([
                'post' => 'post',
                'page' => 'page',
                'kntnt_ad_attr_url' => 'kntnt_ad_attr_url',
            ]);

        $post = TestFactory::post([
            'ID'        => 99,
            'post_type' => 'kntnt_ad_attr_url',
        ]);

        // Numeric search finds the post, but its type is excluded.
        Functions\expect('get_post')->once()->with(99)->andReturn($post);

        // Falls through to slug and title search.
        $wpdb = TestFactory::wpdb();
        $GLOBALS['wpdb'] = $wpdb;
        $wpdb->shouldReceive('esc_like')->andReturnArg(0);
        $wpdb->shouldReceive('prepare')->andReturn('SQL');
        $wpdb->shouldReceive('get_results')->andReturn([]);

        $request = new WP_REST_Request('GET', '/search-posts');
        $request->set_param('search', '99');

        $response = $endpoint->search_posts($request);

        // Post 99 should not be in results (wrong post type).
        $ids = array_column($response->get_data(), 'id');
        expect($ids)->not->toContain(99);
    });

});
