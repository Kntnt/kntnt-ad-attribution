<?php
/**
 * The plugin's custom database tables.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.10.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Names of the plugin's custom tables, without the WordPress table prefix.
 *
 * The one place that spells out a table name for the classes. Migrations
 * and uninstall.php run without these classes and keep their own literals.
 *
 * @since 1.10.0
 */
enum Table: string {

	/**
	 * One row per recorded click.
	 *
	 * @since 1.10.0
	 */
	case Clicks = 'kntnt_ad_attr_clicks';

	/**
	 * One row per attributed click of a conversion.
	 *
	 * @since 1.10.0
	 */
	case Conversions = 'kntnt_ad_attr_conversions';

	/**
	 * One row per platform click ID captured on a click.
	 *
	 * @since 1.10.0
	 */
	case Click_Ids = 'kntnt_ad_attr_click_ids';

	/**
	 * One row per report job.
	 *
	 * @since 1.10.0
	 */
	case Queue = 'kntnt_ad_attr_queue';

	/**
	 * Returns the table's full name, with the site's WordPress table prefix.
	 *
	 * @return string The prefixed table name, e.g. `wp_kntnt_ad_attr_clicks`.
	 * @since 1.10.0
	 */
	public function prefixed(): string {
		global $wpdb;
		return $wpdb->prefix . $this->value;
	}

}
