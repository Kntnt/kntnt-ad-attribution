<?php
/**
 * Unit tests for Table.
 *
 * @package Tests\Unit
 * @since   1.10.0
 */

declare(strict_types=1);

use Kntnt\Ad_Attribution\Table;
use Tests\Helpers\TestFactory;

describe('Table::prefixed()', function () {

    afterEach(function () {
        unset($GLOBALS['wpdb']);
    });

    it('prefixes the table name with the site\'s table prefix', function () {
        $GLOBALS['wpdb'] = TestFactory::wpdb(['prefix' => 'wp_2_']);

        expect(Table::Clicks->prefixed())->toBe('wp_2_kntnt_ad_attr_clicks');
        expect(Table::Conversions->prefixed())->toBe('wp_2_kntnt_ad_attr_conversions');
        expect(Table::Click_Ids->prefixed())->toBe('wp_2_kntnt_ad_attr_click_ids');
        expect(Table::Queue->prefixed())->toBe('wp_2_kntnt_ad_attr_queue');
    });

});
