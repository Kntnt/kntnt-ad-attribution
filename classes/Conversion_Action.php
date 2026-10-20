<?php
/**
 * Actions on recorded conversions in the admin conversion list.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.10.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * A row or bulk action of the conversion list.
 *
 * The value is the `action` query argument. The slugs differ from the
 * tracking URLs' `trash`, `restore` and `delete`, which Admin_Page routes to
 * the tracking-URL handlers.
 *
 * @since 1.10.0
 */
enum Conversion_Action: string {

	/**
	 * Deletes the conversion's rows and keeps its clicks.
	 *
	 * @since 1.10.0
	 */
	case Delete = 'conversion_delete';

	/**
	 * Flags the conversion's attributed clicks as test clicks.
	 *
	 * @since 1.10.0
	 */
	case Mark_As_Test = 'conversion_mark_test';

	/**
	 * Clears the test flag of the conversion's attributed clicks.
	 *
	 * @since 1.10.0
	 */
	case Unmark_As_Test = 'conversion_unmark_test';

	/**
	 * Returns the action's label in the list's row and bulk actions.
	 *
	 * @return string Translated label.
	 * @since 1.10.0
	 */
	public function label(): string {
		return match ( $this ) {
			self::Delete         => __( 'Delete', 'kntnt-ad-attr' ),
			self::Mark_As_Test   => __( 'Mark as test', 'kntnt-ad-attr' ),
			self::Unmark_As_Test => __( 'Unmark as test', 'kntnt-ad-attr' ),
		};
	}

	/**
	 * Returns the `message` query argument that reports the finished action.
	 *
	 * @return string Notice key, e.g. `conversions-deleted`.
	 * @since 1.10.0
	 */
	public function message(): string {
		return match ( $this ) {
			self::Delete         => 'conversions-deleted',
			self::Mark_As_Test   => 'conversions-marked',
			self::Unmark_As_Test => 'conversions-unmarked',
		};
	}

}
