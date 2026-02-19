<?php
/**
 * Unit tests for Utm_Options.
 *
 * @package Tests\Unit
 * @since   1.0.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Utm_Options;
use Brain\Monkey\Functions;

describe('Utm_Options::get_options()', function () {

    it('returns expected default structure with sources and mediums keys', function () {
        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_utm_options', \Mockery::type('array'))
            ->andReturnUsing(fn ($name, $value) => $value);

        $options = Utm_Options::get_options();

        expect($options)->toHaveKeys(['sources', 'mediums']);
        expect($options['sources'])->toBeArray();
        expect($options['mediums'])->toBeArray();
    });

    it('maps google to cpc', function () {
        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_utm_options', \Mockery::type('array'))
            ->andReturnUsing(fn ($name, $value) => $value);

        $options = Utm_Options::get_options();

        expect($options['sources']['google'])->toBe('cpc');
    });

    it('includes all expected default sources', function () {
        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_utm_options', \Mockery::type('array'))
            ->andReturnUsing(fn ($name, $value) => $value);

        $options = Utm_Options::get_options();

        expect($options['sources'])->toHaveKeys([
            'bing',
            'event',
            'google',
            'linkedin',
            'meta',
            'newsletter',
            'pinterest',
            'qr-code',
            'snapchat',
            'tiktok',
            'x',
            'youtube',
        ]);
    });

    it('includes all expected default mediums', function () {
        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_utm_options', \Mockery::type('array'))
            ->andReturnUsing(fn ($name, $value) => $value);

        $options = Utm_Options::get_options();

        expect($options['mediums'])->toContain(
            'affiliate',
            'cpc',
            'display',
            'email',
            'offline',
            'organic',
            'paid-social',
            'print',
            'sms',
            'social',
            'video',
        );
    });

    it('applies kntnt_ad_attr_utm_options filter', function () {
        $custom_options = [
            'sources' => [
                'google'   => 'cpc',
                'snapchat' => 'paid-social',
            ],
            'mediums' => ['cpc', 'paid-social'],
        ];

        Functions\expect('apply_filters')
            ->once()
            ->with('kntnt_ad_attr_utm_options', \Mockery::type('array'))
            ->andReturn($custom_options);

        $options = Utm_Options::get_options();

        expect($options['sources'])->toHaveKey('snapchat');
        expect($options['sources']['snapchat'])->toBe('paid-social');
    });

});
