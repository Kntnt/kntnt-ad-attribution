<?php
declare( strict_types = 1 );
return static function ( \wpdb $wpdb ): void {
	// No schema changes in 1.4.0 — new meta keys (_utm_id, _utm_source_platform)
	// are stored in wp_postmeta and require no DDL.
};
