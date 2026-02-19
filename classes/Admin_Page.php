<?php
/**
 * Admin page for tracking URL management.
 *
 * Registers a page under Tools, renders a single merged view with
 * Campaign_List_Table, handles form submission for creating and
 * managing tracking URLs, and enqueues page-specific assets.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Orchestrates the admin interface: menu, forms, and assets.
 *
 * Form submissions are processed in the `load-` hook (before any output)
 * so that redirects can be sent safely.
 *
 * @since 1.0.0
 */
final class Admin_Page {

	/**
	 * Queue for displaying status information in the admin UI.
	 *
	 * @var Queue
	 * @since 1.2.0
	 */
	private readonly Queue $queue;

	/**
	 * The hook suffix returned by add_management_page().
	 *
	 * Used for targeting admin_enqueue_scripts and load-{$hook_suffix}.
	 *
	 * @var string
	 * @since 1.0.0
	 */
	private string $hook_suffix = '';

	/**
	 * Initializes the admin page with its dependencies.
	 *
	 * @param Queue $queue Async job queue for status display.
	 *
	 * @since 1.2.0
	 */
	public function __construct( Queue $queue ) {
		$this->queue = $queue;
	}

	/**
	 * Registers WordPress hooks for the admin page.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_menu_page' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
		add_filter( 'set-screen-option', [ $this, 'save_screen_option' ], 10, 3 );
	}

	/**
	 * Adds the plugin page under the Tools menu.
	 *
	 * Hooks form handling and Screen Options registration onto the page load event
	 * so they execute before any output is sent.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_menu_page(): void {
		$this->hook_suffix = add_management_page(
			__( 'Ad Attribution', 'kntnt-ad-attr' ),
			__( 'Ad Attribution', 'kntnt-ad-attr' ),
			'kntnt_ad_attr',
			Plugin::get_slug(),
			[ $this, 'render_page' ],
		);

		if ( $this->hook_suffix ) {
			add_action( "load-{$this->hook_suffix}", [ $this, 'add_screen_options' ] );
		}
	}

	/**
	 * Handles form submissions and registers Screen Options.
	 *
	 * Called on the `load-` hook, before headers are sent. This allows
	 * save_url() and trash_url() to redirect after processing.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function add_screen_options(): void {

		// Process form submissions before any output.
		$this->handle_form_submission();

		// Register per-page Screen Option.
		$action = sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) );

		// Only show screen options on the list view (not the add form).
		if ( $action !== 'add' ) {
			add_screen_option( 'per_page', [
				'label'   => __( 'Items per page', 'kntnt-ad-attr' ),
				'default' => 20,
				'option'  => Campaign_List_Table::PER_PAGE_OPTION,
			] );
		}
	}

	/**
	 * Saves the per-page Screen Option value.
	 *
	 * WordPress passes Screen Option saves through this filter. We only
	 * intervene for our own option — returning the sanitized value instead
	 * of the default false (which discards the setting).
	 *
	 * @param mixed  $status The current filter value (false by default).
	 * @param string $option The option name being saved.
	 * @param mixed  $value  The submitted value.
	 *
	 * @return mixed The sanitized value for our option, or pass-through.
	 * @since 1.0.0
	 */
	public function save_screen_option( mixed $status, string $option, mixed $value ): mixed {
		if ( $option === Campaign_List_Table::PER_PAGE_OPTION ) {
			return (int) $value;
		}
		return $status;
	}

	/**
	 * Adds Subresource Integrity (SRI) attributes to CDN-loaded Select2 tags.
	 *
	 * Ensures that the browser verifies the integrity of CDN-hosted assets
	 * before executing them, protecting against CDN compromise.
	 *
	 * @param string $tag    The generated script or link tag.
	 * @param string $handle The registered asset handle.
	 *
	 * @return string Modified tag with integrity and crossorigin attributes.
	 * @since 1.0.0
	 */
	public function add_sri_attributes( string $tag, string $handle ): string {
		static $sri_hashes = [
			'select2'     => 'sha384-JnbsSLBmv2/R0fUmF2XYIcAEMPHEAO51Gitn9IjL4l89uFTIgtLF1+jqIqqd9FSk',
			'select2-css' => 'sha384-KZO2FRYNmIHerhfYMjCIUaJeGBRXP7CN24SiNSG+wdDzgwvxWbl16wMVtWiJTcMt',
		];

		// Map style handle 'select2' to its SRI key.
		$sri_key = str_contains( $tag, '<link' ) ? $handle . '-css' : $handle;

		if ( ! isset( $sri_hashes[ $sri_key ] ) ) {
			return $tag;
		}

		$integrity = $sri_hashes[ $sri_key ];

		return str_replace(
			' src=',
			" integrity=\"{$integrity}\" crossorigin=\"anonymous\" src=",
			str_replace(
				' href=',
				" integrity=\"{$integrity}\" crossorigin=\"anonymous\" href=",
				$tag,
			),
		);
	}

	/**
	 * Enqueues CSS and JavaScript assets on the plugin's admin page.
	 *
	 * Always loads admin.css. On the add view, additionally loads select2
	 * from cdnjs and the plugin's admin.js with localized REST configuration.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		// Admin stylesheet — always loaded on the plugin page.
		wp_enqueue_style(
			'kntnt-ad-attr-admin',
			Plugin::get_plugin_url() . 'css/admin.css',
			[],
			Plugin::get_version(),
		);

		// Select2 is only needed on the add view. Determine deps upfront
		// so the admin script can be registered once with the correct deps.
		$action  = sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) );
		$is_form = $action === 'add';

		if ( $is_form ) {
			wp_enqueue_style(
				'select2',
				'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css',
				[],
				'4.0.13',
			);

			wp_enqueue_script(
				'select2',
				'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js',
				[ 'jquery' ],
				'4.0.13',
				true,
			);

			// Add SRI integrity attributes to CDN-loaded Select2 assets.
			add_filter( 'script_loader_tag', [ $this, 'add_sri_attributes' ], 10, 2 );
			add_filter( 'style_loader_tag', [ $this, 'add_sri_attributes' ], 10, 2 );
		}

		// Admin JS — click-to-copy on list view, select2 init on form.
		wp_enqueue_script(
			'kntnt-ad-attr-admin',
			Plugin::get_plugin_url() . 'js/admin.js',
			$is_form ? [ 'jquery', 'select2' ] : [],
			Plugin::get_version(),
			true,
		);

		if ( $is_form ) {
			wp_localize_script( 'kntnt-ad-attr-admin', 'kntntAdAttrAdmin', [
				'searchUrl'  => rest_url( 'kntnt-ad-attribution/v1/search-posts' ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'utmSources' => Utm_Options::get_options()['sources'],
			] );
		}
	}

	/**
	 * Renders the main admin page.
	 *
	 * Single merged view: page title, optional form, or Campaign_List_Table
	 * with bulk actions, filters, and CSV export.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_page(): void {
		$action = sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Ad Attribution', 'kntnt-ad-attr' ) . '</h1>';

		$this->render_admin_notices();

		if ( $action === 'add' ) {
			$this->render_form();
		} else {
			$this->render_main_view();
		}

		// Custom tab support for add-on plugins (v1.3.0 compatibility).
		$tab = sanitize_text_field( wp_unslash( $_GET['tab'] ?? '' ) );
		if ( $tab !== '' && $tab !== 'urls' && $tab !== 'campaigns' ) {
			do_action( "kntnt_ad_attr_admin_tab_{$tab}" );
		}

		// Show queue status only when reporters are registered.
		$reporters = apply_filters( 'kntnt_ad_attr_conversion_reporters', [] );
		if ( ! empty( $reporters ) ) {
			$this->render_queue_status();
		}

		echo '</div>';
	}

	/**
	 * Renders the main list view with Campaign_List_Table.
	 *
	 * Includes "Create Tracking URL" button, views, search, filters,
	 * bulk action form, and CSV export button.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	private function render_main_view(): void {
		$table = new Campaign_List_Table();
		$table->prepare_items();

		$is_trash = sanitize_text_field( wp_unslash( $_GET['post_status'] ?? '' ) ) === 'trash';

		// "Create Tracking URL" button — only when not in trash.
		if ( ! $is_trash ) {
			$add_url = admin_url( sprintf(
				'tools.php?page=%s&action=add',
				Plugin::get_slug(),
			) );

			echo '<a href="' . esc_url( $add_url ) . '" class="page-title-action">'
				. esc_html__( 'Create Tracking URL', 'kntnt-ad-attr' ) . '</a>';
		}

		echo '<hr class="wp-header-end">';

		$table->views();

		// Filter and list form — uses GET so filters are reflected in the URL.
		echo '<form method="get" class="kntnt-ad-attr-campaigns">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Plugin::get_slug() ) . '">';

		// Preserve post_status through search and pagination.
		if ( $is_trash ) {
			echo '<input type="hidden" name="post_status" value="trash">';
		}

		$table->search_box( __( 'Search', 'kntnt-ad-attr' ), 'kntnt-ad-attr-search' );
		$table->display();
		echo '</form>';

		// CSV export button — only on publish view with reporters registered.
		if ( ! $is_trash ) {
			$reporters = apply_filters( 'kntnt_ad_attr_conversion_reporters', [] );
			if ( ! empty( $reporters ) ) {
				$params = $table->get_filter_params();

				echo '<form method="post" class="kntnt-ad-attr-export">';
				wp_nonce_field( 'kntnt_ad_attr_export', 'kntnt_ad_attr_export_nonce' );
				echo '<input type="hidden" name="kntnt_ad_attr_action" value="export_csv">';

				// Pass current filter values so the export matches the displayed data.
				foreach ( $params as $key => $value ) {
					echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
				}

				submit_button( __( 'Export CSV', 'kntnt-ad-attr' ), 'secondary', 'export_csv', false );
				echo '</form>';
			}
		}
	}

	/**
	 * Renders the queue status section in the admin page.
	 *
	 * Displays the number of pending and failed jobs, and the last error
	 * message if any. Only called when reporters are registered.
	 *
	 * @return void
	 * @since 1.2.0
	 */
	private function render_queue_status(): void {
		$status = $this->queue->get_status();

		echo '<div class="kntnt-ad-attr-queue-status">';
		echo '<h3>' . esc_html__( 'Report Queue', 'kntnt-ad-attr' ) . '</h3>';

		echo '<table class="widefat striped" style="max-width:500px">';
		echo '<tbody>';

		echo '<tr>';
		echo '<td>' . esc_html__( 'Pending jobs', 'kntnt-ad-attr' ) . '</td>';
		echo '<td>' . esc_html( (string) $status['pending'] ) . '</td>';
		echo '</tr>';

		echo '<tr>';
		echo '<td>' . esc_html__( 'Failed jobs', 'kntnt-ad-attr' ) . '</td>';
		echo '<td>' . esc_html( (string) $status['failed'] ) . '</td>';
		echo '</tr>';

		if ( $status['last_error'] !== null ) {
			echo '<tr>';
			echo '<td>' . esc_html__( 'Last error', 'kntnt-ad-attr' ) . '</td>';
			echo '<td>' . esc_html( $status['last_error'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody>';
		echo '</table>';
		echo '</div>';
	}

	/**
	 * Renders the add form for a tracking URL.
	 *
	 * UTM source and medium are rendered as Select2 tag dropdowns with
	 * predefined options. Campaign remains a text input.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_form(): void {
		$options = Utm_Options::get_options();

		echo '<h2>' . esc_html__( 'Add New Tracking URL', 'kntnt-ad-attr' ) . '</h2>';

		echo '<form method="post" class="kntnt-ad-attr-form">';

		wp_nonce_field( 'kntnt_ad_attr_save_url', 'kntnt_ad_attr_nonce' );
		echo '<input type="hidden" name="kntnt_ad_attr_action" value="save_url">';

		echo '<table class="form-table">';

		// Target post — select2-driven.
		echo '<tr>';
		echo '<th scope="row"><label for="kntnt-ad-attr-target-post">'
			. esc_html__( 'Target Page', 'kntnt-ad-attr' ) . ' <span class="required">*</span></label></th>';
		echo '<td>';
		echo '<select id="kntnt-ad-attr-target-post" name="kntnt_ad_attr_target_post_id" style="min-width:400px">';
		echo '</select>';
		echo '</td></tr>';

		// Source — select with predefined options (required).
		echo '<tr>';
		echo '<th scope="row"><label for="kntnt-ad-attr-utm_source">'
			. esc_html__( 'Source', 'kntnt-ad-attr' ) . ' <span class="required">*</span></label></th>';
		echo '<td>';
		echo '<select id="kntnt-ad-attr-utm_source" name="kntnt_ad_attr_utm_source" class="kntnt-ad-attr-select2-tags" required>';
		echo '<option value="">' . esc_html__( '— Select or type —', 'kntnt-ad-attr' ) . '</option>';
		foreach ( array_keys( $options['sources'] ) as $source ) {
			echo '<option value="' . esc_attr( $source ) . '">' . esc_html( $source ) . '</option>';
		}
		echo '</select>';
		echo '</td></tr>';

		// Medium — select with predefined options (required).
		echo '<tr>';
		echo '<th scope="row"><label for="kntnt-ad-attr-utm_medium">'
			. esc_html__( 'Medium', 'kntnt-ad-attr' ) . ' <span class="required">*</span></label></th>';
		echo '<td>';
		echo '<select id="kntnt-ad-attr-utm_medium" name="kntnt_ad_attr_utm_medium" class="kntnt-ad-attr-select2-tags" required>';
		echo '<option value="">' . esc_html__( '— Select or type —', 'kntnt-ad-attr' ) . '</option>';
		foreach ( $options['mediums'] as $medium ) {
			echo '<option value="' . esc_attr( $medium ) . '">' . esc_html( $medium ) . '</option>';
		}
		echo '</select>';
		echo '</td></tr>';

		// Campaign — plain text input (required).
		echo '<tr>';
		echo '<th scope="row"><label for="kntnt-ad-attr-utm_campaign">'
			. esc_html__( 'Campaign', 'kntnt-ad-attr' ) . ' <span class="required">*</span></label></th>';
		echo '<td><input type="text" id="kntnt-ad-attr-utm_campaign"'
			. ' name="kntnt_ad_attr_utm_campaign"'
			. ' value=""'
			. ' class="regular-text" required></td>';
		echo '</tr>';

		echo '</table>';

		submit_button( __( 'Create Tracking URL', 'kntnt-ad-attr' ) );

		echo '</form>';
	}

	/**
	 * Displays admin notices based on the message query parameter.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_admin_notices(): void {
		$message = sanitize_text_field( wp_unslash( $_GET['message'] ?? '' ) );

		$notices = [
			'created'  => [ 'success', __( 'Tracking URL created.', 'kntnt-ad-attr' ) ],
			'trashed'  => [ 'success', __( 'Tracking URL moved to Trash.', 'kntnt-ad-attr' ) ],
			'restored' => [ 'success', __( 'Tracking URL restored.', 'kntnt-ad-attr' ) ],
			'deleted'  => [ 'success', __( 'Tracking URL permanently deleted.', 'kntnt-ad-attr' ) ],
		];

		// Bulk action notices with plural support.
		$bulk_notices = [
			'bulk-trashed'  => __( '%d tracking URL(s) moved to Trash.', 'kntnt-ad-attr' ),
			'bulk-restored' => __( '%d tracking URL(s) restored.', 'kntnt-ad-attr' ),
			'bulk-deleted'  => __( '%d tracking URL(s) permanently deleted.', 'kntnt-ad-attr' ),
		];

		if ( isset( $bulk_notices[ $message ] ) ) {
			$count = (int) ( $_GET['count'] ?? 0 );
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( sprintf( $bulk_notices[ $message ], $count ) ),
			);
			return;
		}

		if ( ! isset( $notices[ $message ] ) ) {
			return;
		}

		[ $type, $text ] = $notices[ $message ];
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $type ),
			esc_html( $text ),
		);
	}

	/**
	 * Routes incoming form submissions and actions to handlers.
	 *
	 * Called from the `load-` hook before output. POST with
	 * `kntnt_ad_attr_action=save_url` triggers save_url(). GET with
	 * `action=trash|restore|delete` triggers the corresponding handler.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function handle_form_submission(): void {
		// POST: save form.
		if ( isset( $_POST['kntnt_ad_attr_action'] ) ) {
			$action = sanitize_text_field( wp_unslash( $_POST['kntnt_ad_attr_action'] ) );

			if ( $action === 'save_url' ) {
				$this->save_url();
				return;
			}

			if ( $action === 'export_csv' ) {
				$this->export_csv();
				return;
			}
		}

		// GET: trash, restore, and delete actions.
		$action = sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) );
		if ( $action === '-1' ) {
			$action = sanitize_text_field( wp_unslash( $_GET['action2'] ?? '' ) );
		}

		// Bulk action: post[] is an array.
		if ( in_array( $action, [ 'trash', 'restore', 'delete' ], true ) && is_array( $_GET['post'] ?? null ) ) {
			$this->handle_bulk_action( $action );
			return;
		}

		// Single action.
		match ( $action ) {
			'trash'   => $this->trash_url(),
			'restore' => $this->restore_url(),
			'delete'  => $this->delete_url(),
			default   => null,
		};
	}

	/**
	 * Processes the save form submission (create only).
	 *
	 * Generates a unique hash via do-while loop, builds the tracking URL
	 * from home_url(), creates the CPT post with meta, and redirects.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function save_url(): void {

		// Verify nonce and capability.
		check_admin_referer( 'kntnt_ad_attr_save_url', 'kntnt_ad_attr_nonce' );
		Plugin::authorize();

		// Sanitize and truncate input.
		$target_post_id = (int) ( $_POST['kntnt_ad_attr_target_post_id'] ?? 0 );
		$utm_source     = mb_substr( sanitize_text_field( wp_unslash( $_POST['kntnt_ad_attr_utm_source'] ?? '' ) ), 0, 255 );
		$utm_medium     = mb_substr( sanitize_text_field( wp_unslash( $_POST['kntnt_ad_attr_utm_medium'] ?? '' ) ), 0, 255 );
		$utm_campaign   = mb_substr( sanitize_text_field( wp_unslash( $_POST['kntnt_ad_attr_utm_campaign'] ?? '' ) ), 0, 255 );

		// Validate required fields: target page, source, medium, and campaign.
		if ( $target_post_id <= 0 || $utm_source === '' || $utm_medium === '' || $utm_campaign === '' ) {
			wp_die( esc_html__( 'Please fill in all required fields.', 'kntnt-ad-attr' ) );
		}

		// Validate that the target post exists and is published.
		$target_post = get_post( $target_post_id );
		if ( ! $target_post || $target_post->post_status !== 'publish' ) {
			wp_die( esc_html__( 'The selected target page does not exist or is not published.', 'kntnt-ad-attr' ) );
		}

		// Generate a unique hash.
		do {
			$hash = hash( 'sha256', random_bytes( 32 ) );
		} while ( $this->hash_exists( $hash ) );

		// Build the tracking URL.
		$tracking_url = home_url( Plugin::get_url_prefix() . '/' . $hash );

		// Create the CPT post.
		$post_id = wp_insert_post( [
			'post_type'   => Post_Type::SLUG,
			'post_title'  => $tracking_url,
			'post_status' => 'publish',
		] );

		if ( is_wp_error( $post_id ) ) {
			wp_die( esc_html__( 'Failed to create tracking URL. Please try again.', 'kntnt-ad-attr' ) );
		}

		// Store meta fields (always-required fields first).
		add_post_meta( $post_id, '_hash', $hash, true );
		add_post_meta( $post_id, '_target_post_id', (string) $target_post_id, true );

		// Store Source/Medium/Campaign unconditionally (all required).
		add_post_meta( $post_id, '_utm_source', $utm_source, true );
		add_post_meta( $post_id, '_utm_medium', $utm_medium, true );
		add_post_meta( $post_id, '_utm_campaign', $utm_campaign, true );

		// Redirect to the list view with a success message.
		wp_safe_redirect( admin_url( sprintf(
			'tools.php?page=%s&message=created',
			Plugin::get_slug(),
		) ) );
		exit;
	}

	/**
	 * Processes the trash action for a tracking URL.
	 *
	 * Verifies the nonce, trashes the post, and redirects with a success message.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function trash_url(): void {
		$post_id = (int) ( $_GET['post'] ?? 0 );

		check_admin_referer( 'trash_kntnt_ad_attr_url_' . $post_id );
		Plugin::authorize();

		wp_trash_post( $post_id );

		wp_safe_redirect( admin_url( sprintf(
			'tools.php?page=%s&message=trashed',
			Plugin::get_slug(),
		) ) );
		exit;
	}

	/**
	 * Restores a trashed tracking URL back to published status.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function restore_url(): void {
		$post_id = (int) ( $_GET['post'] ?? 0 );

		check_admin_referer( 'restore_kntnt_ad_attr_url_' . $post_id );
		Plugin::authorize();

		wp_untrash_post( $post_id );

		wp_safe_redirect( admin_url( sprintf(
			'tools.php?page=%s&message=restored',
			Plugin::get_slug(),
		) ) );
		exit;
	}

	/**
	 * Permanently deletes a trashed tracking URL.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function delete_url(): void {
		$post_id = (int) ( $_GET['post'] ?? 0 );

		check_admin_referer( 'delete_kntnt_ad_attr_url_' . $post_id );
		Plugin::authorize();

		$this->permanently_delete_url( $post_id );

		wp_safe_redirect( admin_url( sprintf(
			'tools.php?page=%s&post_status=trash&message=deleted',
			Plugin::get_slug(),
		) ) );
		exit;
	}

	/**
	 * Deletes a tracking URL's click/conversion data and then the post itself.
	 *
	 * @param int $post_id The post ID to delete.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function permanently_delete_url( int $post_id ): void {
		global $wpdb;

		$hash = get_post_meta( $post_id, '_hash', true );
		if ( $hash ) {
			$clicks_table = $wpdb->prefix . 'kntnt_ad_attr_clicks';
			$conv_table   = $wpdb->prefix . 'kntnt_ad_attr_conversions';

			// Delete conversions linked to this hash's clicks first.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare(
				"DELETE cv FROM {$conv_table} cv
				 INNER JOIN {$clicks_table} c ON c.id = cv.click_id
				 WHERE c.hash = %s",
				$hash,
			) );

			// Delete the click records.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $clicks_table, [ 'hash' => $hash ], [ '%s' ] );
		}

		wp_delete_post( $post_id, true );
	}

	/**
	 * Processes a bulk action on multiple tracking URLs.
	 *
	 * @param string $action The bulk action: 'trash', 'restore', or 'delete'.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function handle_bulk_action( string $action ): void {
		check_admin_referer( 'bulk-tracking-urls' );
		Plugin::authorize();

		$post_ids = array_map( 'intval', (array) $_GET['post'] );
		$count    = 0;

		foreach ( $post_ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || $post->post_type !== Post_Type::SLUG ) {
				continue;
			}

			match ( $action ) {
				'trash'   => wp_trash_post( $post_id ),
				'restore' => wp_untrash_post( $post_id ),
				'delete'  => $this->permanently_delete_url( $post_id ),
			};

			$count++;
		}

		$message_key = match ( $action ) {
			'trash'   => 'bulk-trashed',
			'restore' => 'bulk-restored',
			'delete'  => 'bulk-deleted',
		};

		$redirect_args = [
			'page'    => Plugin::get_slug(),
			'message' => $message_key,
			'count'   => $count,
		];

		// Stay in trash view for restore/delete actions.
		if ( $action === 'restore' || $action === 'delete' ) {
			$redirect_args['post_status'] = 'trash';
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'tools.php' ) ) );
		exit;
	}

	/**
	 * Handles the CSV export action.
	 *
	 * Validates nonce and capability, reconstructs the filter state from
	 * POST data, fetches all matching items, and delegates to Csv_Exporter.
	 *
	 * @return never
	 * @since 1.0.0
	 */
	private function export_csv(): never {
		check_admin_referer( 'kntnt_ad_attr_export', 'kntnt_ad_attr_export_nonce' );
		Plugin::authorize();

		// Reconstruct filter params from POST into GET so Campaign_List_Table
		// can read them via its standard get_filter_params() method.
		$filter_keys = [ 'date_start', 'date_end', 'utm_source', 'utm_medium', 'utm_campaign', 's' ];
		foreach ( $filter_keys as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$_GET[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}

		// Map 'search' POST field to 's' GET field used by get_filter_params().
		if ( isset( $_POST['search'] ) ) {
			$_GET['s'] = sanitize_text_field( wp_unslash( $_POST['search'] ) );
		}

		$table  = new Campaign_List_Table();
		$params = $table->get_filter_params();
		$items  = $table->fetch_all_items();

		$exporter = new Csv_Exporter();
		$exporter->export( $items, $params['date_start'], $params['date_end'] );
	}

	/**
	 * Checks if a hash already exists in the database.
	 *
	 * Used during hash generation to ensure uniqueness.
	 *
	 * @param string $hash The SHA-256 hash to check.
	 *
	 * @return bool True if the hash already exists.
	 * @since 1.0.0
	 */
	private function hash_exists( string $hash ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_hash' AND meta_value = %s",
			$hash,
		) );

		return $count > 0;
	}

}
