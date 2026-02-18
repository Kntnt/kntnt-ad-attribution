<?php
/**
 * Unit tests for Updater.
 *
 * @package Tests\Unit
 * @since   1.0.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Updater;
use Kntnt\Ad_Attribution\Plugin;
use Brain\Monkey\Functions;

// ─── check_for_updates() ───

describe('Updater::check_for_updates()', function () {

    it('returns unchanged transient when checked is empty', function () {
        $updater   = new Updater();
        $transient = (object) ['checked' => []];

        $result = $updater->check_for_updates($transient);

        expect($result)->toBe($transient);
        expect(isset($result->response))->toBeFalse();
    });

    it('returns unchanged transient when no PluginURI', function () {
        $updater   = new Updater();
        $transient = (object) ['checked' => ['plugin/plugin.php' => '1.0.0']];

        // Plugin data without a GitHub URI.
        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Plugin::get_plugin_data',
            fn () => ['Version' => '1.0.0', 'PluginURI' => ''],
        );

        $result = $updater->check_for_updates($transient);

        expect($result)->toBe($transient);
        expect(isset($result->response))->toBeFalse();
    });

    it('returns unchanged transient when version is current', function () {
        $updater   = new Updater();
        $transient = (object) ['checked' => ['plugin/plugin.php' => '1.5.0']];

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Plugin::get_plugin_data',
            fn () => [
                'Version'   => '1.5.0',
                'PluginURI' => 'https://github.com/Kntnt/kntnt-ad-attribution',
            ],
        );

        // GitHub API returns same version.
        Functions\expect('wp_remote_get')->once()->andReturn(['body' => '{}']);
        Functions\expect('is_wp_error')->once()->andReturn(false);
        Functions\expect('wp_remote_retrieve_response_code')->once()->andReturn(200);
        Functions\expect('wp_remote_retrieve_body')->once()->andReturn(json_encode((object) [
            'tag_name'    => 'v1.5.0',
            'zipball_url' => 'https://github.com/zip',
            'html_url'    => 'https://github.com/release',
            'assets'      => [],
        ]));

        $result = $updater->check_for_updates($transient);

        expect(isset($result->response))->toBeFalse();
    });

    it('adds update info for newer version with zip asset', function () {
        $updater   = new Updater();
        $transient = (object) [
            'checked'  => ['kntnt-ad-attribution/kntnt-ad-attribution.php' => '1.4.0'],
            'response' => [],
        ];

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Plugin::get_plugin_data',
            fn () => [
                'Version'            => '1.4.0',
                'PluginURI'          => 'https://github.com/Kntnt/kntnt-ad-attribution',
                'Requires at least'  => '6.4',
            ],
        );

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Plugin::get_plugin_file',
            fn () => '/path/to/kntnt-ad-attribution/kntnt-ad-attribution.php',
        );

        Functions\expect('plugin_basename')
            ->once()
            ->andReturn('kntnt-ad-attribution/kntnt-ad-attribution.php');

        Functions\expect('wp_remote_get')->once()->andReturn(['body' => '{}']);
        Functions\expect('is_wp_error')->once()->andReturn(false);
        Functions\expect('wp_remote_retrieve_response_code')->once()->andReturn(200);
        Functions\expect('wp_remote_retrieve_body')->once()->andReturn(json_encode((object) [
            'tag_name'    => 'v1.5.0',
            'zipball_url' => 'https://github.com/zip',
            'html_url'    => 'https://github.com/release/v1.5.0',
            'assets'      => [
                (object) [
                    'content_type'         => 'application/zip',
                    'browser_download_url' => 'https://github.com/download/v1.5.0.zip',
                ],
            ],
        ]));

        $result = $updater->check_for_updates($transient);

        $key = 'kntnt-ad-attribution/kntnt-ad-attribution.php';
        expect(isset($result->response[$key]))->toBeTrue();
        expect($result->response[$key]->new_version)->toBe('1.5.0');
        expect($result->response[$key]->package)->toBe('https://github.com/download/v1.5.0.zip');
    });

    it('skips update when no zip asset found', function () {
        $updater   = new Updater();
        $transient = (object) [
            'checked'  => ['plugin/plugin.php' => '1.4.0'],
            'response' => [],
        ];

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Plugin::get_plugin_data',
            fn () => [
                'Version'   => '1.4.0',
                'PluginURI' => 'https://github.com/Kntnt/kntnt-ad-attribution',
            ],
        );

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Plugin::get_plugin_file',
            fn () => '/path/to/plugin/plugin.php',
        );

        Functions\expect('plugin_basename')
            ->once()
            ->andReturn('plugin/plugin.php');

        Functions\expect('wp_remote_get')->once()->andReturn(['body' => '{}']);
        Functions\expect('is_wp_error')->once()->andReturn(false);
        Functions\expect('wp_remote_retrieve_response_code')->once()->andReturn(200);
        Functions\expect('wp_remote_retrieve_body')->once()->andReturn(json_encode((object) [
            'tag_name'    => 'v1.5.0',
            'zipball_url' => 'https://github.com/zip',
            'html_url'    => 'https://github.com/release',
            'assets'      => [
                (object) [
                    'content_type'         => 'application/gzip',
                    'browser_download_url' => 'https://github.com/download/v1.5.0.tar.gz',
                ],
            ],
        ]));

        $result = $updater->check_for_updates($transient);

        expect(isset($result->response['plugin/plugin.php']))->toBeFalse();
    });

    it('returns unchanged transient when GitHub API fails', function () {
        $updater   = new Updater();
        $transient = (object) ['checked' => ['plugin/plugin.php' => '1.4.0']];

        \Patchwork\redefine(
            'Kntnt\Ad_Attribution\Plugin::get_plugin_data',
            fn () => [
                'Version'   => '1.4.0',
                'PluginURI' => 'https://github.com/Kntnt/kntnt-ad-attribution',
            ],
        );

        Functions\expect('wp_remote_get')->once()->andReturn(['body' => '']);
        Functions\expect('is_wp_error')->once()->andReturn(true);

        $result = $updater->check_for_updates($transient);

        expect(isset($result->response))->toBeFalse();
    });

});
