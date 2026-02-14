<?php
/**
 * Admin page for tracking URL management.
 *
 * Registers a page under Tools, renders tab navigation (URLs / Campaigns),
 * handles form submission for creating, editing, and trashing tracking URLs,
 * and enqueues page-specific assets.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Orchestrates the admin interface: menu, tabs, forms, and assets.
 *
 * Form submissions are processed in the `load-` hook (before any output)
 * so that redirects can be sent safely.
 *
 * @since 1.0.0
 */
final class Admin_Page {

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

		// Register per-page Screen Option for the list view.
		add_screen_option( 'per_page', [
			'label'   => __( 'URLs per page', 'kntnt-ad-attr' ),
			'default' => 20,
			'option'  => Url_List_Table::PER_PAGE_OPTION,
		] );
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
		if ( $option === Url_List_Table::PER_PAGE_OPTION ) {
			return (int) $value;
		}
		return $status;
	}

	/**
	 * Enqueues CSS and JavaScript assets on the plugin's admin page.
	 *
	 * Always loads admin.css. On add/edit views, additionally loads select2
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

		// Admin JS — always loaded (click-to-copy on list view, select2 on form).
		wp_enqueue_script(
			'kntnt-ad-attr-admin',
			Plugin::get_plugin_url() . 'js/admin.js',
			[],
			Plugin::get_version(),
			true,
		);

		// Select2 — only needed on add/edit views.
		$action = sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) );
		if ( $action !== 'add' && $action !== 'edit' ) {
			return;
		}

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

		// Re-register admin JS with select2 dependency for the form view.
		wp_deregister_script( 'kntnt-ad-attr-admin' );
		wp_enqueue_script(
			'kntnt-ad-attr-admin',
			Plugin::get_plugin_url() . 'js/admin.js',
			[ 'jquery', 'select2' ],
			Plugin::get_version(),
			true,
		);

		wp_localize_script( 'kntnt-ad-attr-admin', 'kntntAdAttrAdmin', [
			'searchUrl' => rest_url( 'kntnt-ad-attribution/v1/search-posts' ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
		] );
	}

	/**
	 * Renders the main admin page.
	 *
	 * Verifies capability, outputs the page wrapper, tab navigation,
	 * admin notices, and dispatches to the active tab's renderer.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render_page(): void {
		Plugin::authorize();

		$tab    = sanitize_text_field( wp_unslash( $_GET['tab'] ?? 'urls' ) );
		$action = sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Ad Attribution', 'kntnt-ad-attr' ) . '</h1>';

		$this->render_tabs( $tab );
		$this->render_admin_notices();

		echo '<div class="kntnt-ad-attr-tab-content">';

		match ( $tab ) {
			'campaigns' => $this->render_campaigns_tab(),
			default     => $this->render_urls_tab( $action ),
		};

		echo '</div>';
		echo '</div>';
	}

	/**
	 * Renders the tab navigation bar.
	 *
	 * @param string $active_tab The currently active tab slug.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_tabs( string $active_tab ): void {
		$tabs = [
			'urls'      => __( 'URLs', 'kntnt-ad-attr' ),
			'campaigns' => __( 'Campaigns', 'kntnt-ad-attr' ),
		];

		$base_url = admin_url( 'tools.php?page=' . Plugin::get_slug() );

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url   = add_query_arg( 'tab', $slug, $base_url );
			$class = ( $active_tab === $slug ) ? 'nav-tab nav-tab-active' : 'nav-tab';
			printf(
				'<a href="%s" class="%s">%s</a>',
				esc_url( $url ),
				esc_attr( $class ),
				esc_html( $label ),
			);
		}
		echo '</nav>';
	}

	/**
	 * Routes the URLs tab to the appropriate view.
	 *
	 * @param string $action The current action: 'add', 'edit', or empty for list.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_urls_tab( string $action ): void {
		match ( $action ) {
			'add'   => $this->render_form(),
			'edit'  => $this->render_form( (int) ( $_GET['post'] ?? 0 ) ),
			default => $this->render_list_view(),
		};
	}

	/**
	 * Renders the list view with "Add New" button, search box, and table.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_list_view(): void {
		$table = new Url_List_Table();
		$table->prepare_items();

		$add_url = admin_url( sprintf(
			'tools.php?page=%s&tab=urls&action=add',
			Plugin::get_slug(),
		) );

		echo '<a href="' . esc_url( $add_url ) . '" class="page-title-action">'
			. esc_html__( 'Add New', 'kntnt-ad-attr' ) . '</a>';

		// Search box inside a form so it submits with GET.
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Plugin::get_slug() ) . '">';
		echo '<input type="hidden" name="tab" value="urls">';

		// Preserve active filters through search.
		foreach ( [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term' ] as $filter ) {
			$value = sanitize_text_field( wp_unslash( $_GET[ $filter ] ?? '' ) );
			if ( $value !== '' ) {
				echo '<input type="hidden" name="' . esc_attr( $filter ) . '" value="' . esc_attr( $value ) . '">';
			}
		}

		$table->search_box( __( 'Search URLs', 'kntnt-ad-attr' ), 'kntnt-ad-attr-search' );
		$table->display();
		echo '</form>';
	}

	/**
	 * Renders the Campaigns tab placeholder.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_campaigns_tab(): void {
		echo '<p>' . esc_html__( 'Campaign reporting will be available in a future update.', 'kntnt-ad-attr' ) . '</p>';
	}

	/**
	 * Renders the add/edit form for a tracking URL.
	 *
	 * In edit mode, existing values are loaded from post meta and the hash
	 * is displayed as read-only. The select2 target selector is pre-populated
	 * with the current target post.
	 *
	 * @param int $post_id Post ID for edit mode, 0 for add mode.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function render_form( int $post_id = 0 ): void {
		$is_edit = $post_id > 0;
		$meta    = [];

		if ( $is_edit ) {
			$post = get_post( $post_id );
			if ( ! $post || $post->post_type !== Post_Type::SLUG ) {
				wp_die( esc_html__( 'Invalid tracking URL.', 'kntnt-ad-attr' ) );
			}
			$meta = [
				'hash'           => get_post_meta( $post_id, '_hash', true ),
				'target_post_id' => get_post_meta( $post_id, '_target_post_id', true ),
				'utm_source'     => get_post_meta( $post_id, '_utm_source', true ),
				'utm_medium'     => get_post_meta( $post_id, '_utm_medium', true ),
				'utm_campaign'   => get_post_meta( $post_id, '_utm_campaign', true ),
				'utm_content'    => get_post_meta( $post_id, '_utm_content', true ),
				'utm_term'       => get_post_meta( $post_id, '_utm_term', true ),
			];
		}

		$heading = $is_edit
			? __( 'Edit Tracking URL', 'kntnt-ad-attr' )
			: __( 'Add New Tracking URL', 'kntnt-ad-attr' );

		echo '<h2>' . esc_html( $heading ) . '</h2>';

		// Show the hash read-only in edit mode.
		if ( $is_edit && ! empty( $meta['hash'] ) ) {
			echo '<p><strong>' . esc_html__( 'Hash:', 'kntnt-ad-attr' ) . '</strong> ';
			echo '<code>' . esc_html( $meta['hash'] ) . '</code></p>';
			echo '<p><strong>' . esc_html__( 'Tracking URL:', 'kntnt-ad-attr' ) . '</strong> ';
			echo '<code>' . esc_html( $post->post_title ) . '</code></p>';
		}

		echo '<form method="post" class="kntnt-ad-attr-form">';

		wp_nonce_field( 'kntnt_ad_attr_save_url', 'kntnt_ad_attr_nonce' );
		echo '<input type="hidden" name="kntnt_ad_attr_action" value="save_url">';
		echo '<input type="hidden" name="kntnt_ad_attr_post_id" value="' . esc_attr( (string) $post_id ) . '">';

		echo '<table class="form-table">';

		// Target post — select2-driven.
		echo '<tr>';
		echo '<th scope="row"><label for="kntnt-ad-attr-target-post">'
			. esc_html__( 'Target Page', 'kntnt-ad-attr' ) . ' <span class="required">*</span></label></th>';
		echo '<td>';
		echo '<select id="kntnt-ad-attr-target-post" name="kntnt_ad_attr_target_post_id" style="min-width:400px">';

		// Pre-populate select2 in edit mode.
		if ( $is_edit && ! empty( $meta['target_post_id'] ) ) {
			$target_post = get_post( (int) $meta['target_post_id'] );
			if ( $target_post ) {
				$option_text = $target_post->post_title . ' (' . $target_post->post_type . ' #' . $target_post->ID . ')';
				echo '<option value="' . esc_attr( (string) $target_post->ID ) . '" selected>'
					. esc_html( $option_text ) . '</option>';
			}
		}

		echo '</select>';
		echo '</td></tr>';

		// UTM fields.
		$utm_fields = [
			'utm_source'   => [ __( 'UTM Source', 'kntnt-ad-attr' ), true ],
			'utm_medium'   => [ __( 'UTM Medium', 'kntnt-ad-attr' ), true ],
			'utm_campaign' => [ __( 'UTM Campaign', 'kntnt-ad-attr' ), true ],
			'utm_content'  => [ __( 'UTM Content', 'kntnt-ad-attr' ), false ],
			'utm_term'     => [ __( 'UTM Term', 'kntnt-ad-attr' ), false ],
		];

		foreach ( $utm_fields as $field_name => [ $label, $required ] ) {
			$value    = esc_attr( $meta[ $field_name ] ?? '' );
			$req_mark = $required ? ' <span class="required">*</span>' : '';
			$req_attr = $required ? ' required' : '';

			echo '<tr>';
			echo '<th scope="row"><label for="kntnt-ad-attr-' . esc_attr( $field_name ) . '">'
				. esc_html( $label ) . $req_mark . '</label></th>';
			echo '<td><input type="text" id="kntnt-ad-attr-' . esc_attr( $field_name ) . '"'
				. ' name="kntnt_ad_attr_' . esc_attr( $field_name ) . '"'
				. ' value="' . $value . '"'
				. ' class="regular-text"' . $req_attr . '></td>';
			echo '</tr>';
		}

		echo '</table>';

		$button_text = $is_edit
			? __( 'Update Tracking URL', 'kntnt-ad-attr' )
			: __( 'Create Tracking URL', 'kntnt-ad-attr' );

		submit_button( $button_text );

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
			'created' => [ 'success', __( 'Tracking URL created.', 'kntnt-ad-attr' ) ],
			'updated' => [ 'success', __( 'Tracking URL updated.', 'kntnt-ad-attr' ) ],
			'trashed' => [ 'success', __( 'Tracking URL moved to Trash.', 'kntnt-ad-attr' ) ],
		];

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
	 * `action=trash` triggers trash_url().
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function handle_form_submission(): void {
		// POST: save form.
		if ( isset( $_POST['kntnt_ad_attr_action'] ) && $_POST['kntnt_ad_attr_action'] === 'save_url' ) {
			$this->save_url();
			return;
		}

		// GET: trash action.
		$action = sanitize_text_field( wp_unslash( $_GET['action'] ?? '' ) );
		if ( $action === 'trash' ) {
			$this->trash_url();
		}
	}

	/**
	 * Processes the save form submission (create or update).
	 *
	 * On create: generates a unique hash via do-while loop, builds the
	 * tracking URL from home_url(), creates the CPT post with meta.
	 * On edit: updates only the target and UTM meta fields (hash and
	 * tracking URL are immutable).
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function save_url(): void {

		// Verify nonce and capability.
		check_admin_referer( 'kntnt_ad_attr_save_url', 'kntnt_ad_attr_nonce' );
		Plugin::authorize();

		$post_id = (int) ( $_POST['kntnt_ad_attr_post_id'] ?? 0 );
		$is_edit = $post_id > 0;

		// Sanitize and truncate input.
		$target_post_id = (int) ( $_POST['kntnt_ad_attr_target_post_id'] ?? 0 );
		$utm_source     = mb_substr( sanitize_text_field( wp_unslash( $_POST['kntnt_ad_attr_utm_source'] ?? '' ) ), 0, 255 );
		$utm_medium     = mb_substr( sanitize_text_field( wp_unslash( $_POST['kntnt_ad_attr_utm_medium'] ?? '' ) ), 0, 255 );
		$utm_campaign   = mb_substr( sanitize_text_field( wp_unslash( $_POST['kntnt_ad_attr_utm_campaign'] ?? '' ) ), 0, 255 );
		$utm_content    = mb_substr( sanitize_text_field( wp_unslash( $_POST['kntnt_ad_attr_utm_content'] ?? '' ) ), 0, 255 );
		$utm_term       = mb_substr( sanitize_text_field( wp_unslash( $_POST['kntnt_ad_attr_utm_term'] ?? '' ) ), 0, 255 );

		// Validate required fields.
		if ( $target_post_id <= 0 || $utm_source === '' || $utm_medium === '' || $utm_campaign === '' ) {
			wp_die( esc_html__( 'Please fill in all required fields.', 'kntnt-ad-attr' ) );
		}

		// Validate that the target post exists and is published.
		$target_post = get_post( $target_post_id );
		if ( ! $target_post || $target_post->post_status !== 'publish' ) {
			wp_die( esc_html__( 'The selected target page does not exist or is not published.', 'kntnt-ad-attr' ) );
		}

		if ( $is_edit ) {

			// Update meta fields — hash and tracking URL remain unchanged.
			update_post_meta( $post_id, '_target_post_id', (string) $target_post_id );
			update_post_meta( $post_id, '_utm_source', $utm_source );
			update_post_meta( $post_id, '_utm_medium', $utm_medium );
			update_post_meta( $post_id, '_utm_campaign', $utm_campaign );
			update_post_meta( $post_id, '_utm_content', $utm_content );
			update_post_meta( $post_id, '_utm_term', $utm_term );

			$message = 'updated';

		} else {

			// Generate a unique hash.
			do {
				$hash = hash( 'sha256', random_bytes( 32 ) );
			} while ( $this->hash_exists( $hash ) );

			// Build the tracking URL.
			/** @var string $prefix The URL path prefix for tracking URLs. */
			$prefix       = apply_filters( 'kntnt_ad_attr_url_prefix', 'ad' );
			$tracking_url = home_url( $prefix . '/' . $hash );

			// Create the CPT post.
			$post_id = wp_insert_post( [
				'post_type'   => Post_Type::SLUG,
				'post_title'  => $tracking_url,
				'post_status' => 'publish',
			] );

			if ( is_wp_error( $post_id ) ) {
				wp_die( esc_html__( 'Failed to create tracking URL. Please try again.', 'kntnt-ad-attr' ) );
			}

			// Store meta fields.
			add_post_meta( $post_id, '_hash', $hash, true );
			add_post_meta( $post_id, '_target_post_id', (string) $target_post_id, true );
			add_post_meta( $post_id, '_utm_source', $utm_source, true );
			add_post_meta( $post_id, '_utm_medium', $utm_medium, true );
			add_post_meta( $post_id, '_utm_campaign', $utm_campaign, true );
			add_post_meta( $post_id, '_utm_content', $utm_content, true );
			add_post_meta( $post_id, '_utm_term', $utm_term, true );

			$message = 'created';
		}

		// Redirect to the list view with a success message.
		wp_safe_redirect( admin_url( sprintf(
			'tools.php?page=%s&tab=urls&message=%s',
			Plugin::get_slug(),
			$message,
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
			'tools.php?page=%s&tab=urls&message=trashed',
			Plugin::get_slug(),
		) ) );
		exit;
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
