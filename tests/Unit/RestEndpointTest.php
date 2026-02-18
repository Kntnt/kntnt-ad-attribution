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
    });

    it('returns 429 when rate limit exceeded', function () {
        [$endpoint, $cm, $con] = make_rest_endpoint();

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        Functions\expect('get_transient')->once()->andReturn(10);

        $request = new WP_REST_Request('POST', '/set-cookie');
        $request->set_param('hashes', [TestFactory::hash('rate-limit')]);

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

        // Hashes will fail validation — return early.
        $cm->shouldReceive('validate_hash')->andReturn(false);

        $request = new WP_REST_Request('POST', '/set-cookie');
        $request->set_param('hashes', [$hash]);

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => [],
        );

        $response = $endpoint->set_cookie($request);

        expect($response->get_data()['success'])->toBeFalse();
    });

    it('filters out invalid hash formats', function () {
        [$endpoint, $cm, $con] = make_rest_endpoint();

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        Functions\expect('get_transient')->once()->andReturn(0);
        Functions\expect('set_transient')->once();

        // Only the valid hash passes validation.
        $valid_hash = TestFactory::hash('valid');
        $cm->shouldReceive('validate_hash')->with($valid_hash)->andReturn(true);
        $cm->shouldReceive('validate_hash')->with('INVALID')->andReturn(false);

        // get_valid_hashes called with only the validated hash.
        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        $con->shouldReceive('check')->andReturn(true);
        $cm->shouldReceive('parse')->andReturn([]);
        $cm->shouldReceive('add')->andReturn([$valid_hash => 1700000000]);
        $cm->shouldReceive('set_clicks_cookie')->once();

        $request = new WP_REST_Request('POST', '/set-cookie');
        $request->set_param('hashes', [$valid_hash, 'INVALID']);

        $response = $endpoint->set_cookie($request);

        expect($response->get_data()['success'])->toBeTrue();
    });

    it('returns failure when all hashes filtered by get_valid_hashes', function () {
        [$endpoint, $cm, $con] = make_rest_endpoint();
        $hash = TestFactory::hash('unknown');

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        Functions\expect('get_transient')->once()->andReturn(0);
        Functions\expect('set_transient')->once();

        $cm->shouldReceive('validate_hash')->andReturn(true);

        // All hashes rejected by database lookup.
        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => [],
        );

        $request = new WP_REST_Request('POST', '/set-cookie');
        $request->set_param('hashes', [$hash]);

        $response = $endpoint->set_cookie($request);

        expect($response->get_data()['success'])->toBeFalse();
    });

    it('returns failure when consent is not true', function () {
        [$endpoint, $cm, $con] = make_rest_endpoint();
        $hash = TestFactory::hash('no-consent');

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        Functions\expect('get_transient')->once()->andReturn(0);
        Functions\expect('set_transient')->once();

        $cm->shouldReceive('validate_hash')->andReturn(true);

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
        $request->set_param('hashes', [$hash]);

        $response = $endpoint->set_cookie($request);

        expect($response->get_data()['success'])->toBeFalse();
    });

    it('sets cookie on success', function () {
        [$endpoint, $cm, $con] = make_rest_endpoint();
        $hash = TestFactory::hash('success');

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        Functions\expect('get_transient')->once()->andReturn(0);
        Functions\expect('set_transient')->once();

        $cm->shouldReceive('validate_hash')->andReturn(true);

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        $con->shouldReceive('check')->once()->andReturn(true);

        // Cookie merge flow.
        $cm->shouldReceive('parse')->once()->andReturn([]);
        $cm->shouldReceive('add')->once()->with([], $hash)->andReturn([$hash => 1700000000]);
        $cm->shouldReceive('set_clicks_cookie')->once()->with([$hash => 1700000000]);

        $request = new WP_REST_Request('POST', '/set-cookie');
        $request->set_param('hashes', [$hash]);

        $response = $endpoint->set_cookie($request);

        expect($response->get_status())->toBe(200);
        expect($response->get_data()['success'])->toBeTrue();
    });

    it('merges new hashes with existing cookie entries', function () {
        [$endpoint, $cm, $con] = make_rest_endpoint();
        $existing_hash = TestFactory::hash('existing');
        $new_hash      = TestFactory::hash('new');

        $_SERVER['REMOTE_ADDR'] = '10.0.0.1';

        Functions\expect('get_transient')->once()->andReturn(0);
        Functions\expect('set_transient')->once();

        $cm->shouldReceive('validate_hash')->andReturn(true);

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Post_Type::get_valid_hashes',
            fn (array $hashes) => $hashes,
        );

        $con->shouldReceive('check')->once()->andReturn(true);

        // Existing cookie has one entry.
        $cm->shouldReceive('parse')->once()->andReturn([$existing_hash => 1699999000]);

        // add() is called once for each hash.
        $cm->shouldReceive('add')
            ->once()
            ->with([$existing_hash => 1699999000], $new_hash)
            ->andReturn([$existing_hash => 1699999000, $new_hash => 1700000000]);

        $cm->shouldReceive('set_clicks_cookie')
            ->once()
            ->with([$existing_hash => 1699999000, $new_hash => 1700000000]);

        $request = new WP_REST_Request('POST', '/set-cookie');
        $request->set_param('hashes', [$new_hash]);

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
