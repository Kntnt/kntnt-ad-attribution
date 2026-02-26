<?php
/**
 * WordPress settings page for core Ad Attribution configuration.
 *
 * Registers an options page under Settings > Ad Attribution with three
 * sections: Cookies, Logging, and Queue (retry). Uses the WordPress
 * Settings API for registration, rendering, and sanitization.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.8.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Admin settings page for cookie, logging, and retry configuration.
 *
 * @since 1.8.0
 */
final class Settings_Page {

	/**
	 * Settings page slug.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	private const PAGE_SLUG = 'kntnt-ad-attr';

	/**
	 * Settings group name for the Settings API.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	private const SETTINGS_GROUP = 'kntnt_ad_attr_group';

	/**
	 * Section ID for cookie fields.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	private const SECTION_COOKIES = 'kntnt_ad_attr_section_cookies';

	/**
	 * Section ID for logging fields.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	private const SECTION_LOGGING = 'kntnt_ad_attr_section_logging';

	/**
	 * Section ID for queue retry fields.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	private const SECTION_QUEUE = 'kntnt_ad_attr_section_queue';

	/**
	 * Admin post action for downloading the log file.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	private const ACTION_DOWNLOAD_LOG = 'kntnt_ad_attr_download_log';

	/**
	 * Admin post action for clearing the log file.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	private const ACTION_CLEAR_LOG = 'kntnt_ad_attr_clear_log';

	/**
	 * Settings instance for reading/writing settings.
	 *
	 * @var Settings
	 * @since 1.8.0
	 */
	private readonly Settings $settings;

	/**
	 * Logger instance for log file management.
	 *
	 * @var Logger
	 * @since 1.8.0
	 */
	private readonly Logger $logger;

	/**
	 * Constructs the settings page with its dependencies.
	 *
	 * @param Settings $settings Settings instance for data access.
	 * @param Logger   $logger   Logger instance for log management.
	 *
	 * @since 1.8.0
	 */
	public function __construct( Settings $settings, Logger $logger ) {
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Registers WordPress hooks for the settings page.
	 *
	 * Called from Plugin::register_hooks() so hooks are only added once.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function register(): void {
		if ( is_admin() ) {
			add_action( 'admin_menu', [ $this, 'add_page' ] );
			add_action( 'admin_init', [ $this, 'register_settings' ] );
			add_action( 'admin_post_' . self::ACTION_DOWNLOAD_LOG, [ $this, 'handle_download_log' ] );
			add_action( 'admin_post_' . self::ACTION_CLEAR_LOG, [ $this, 'handle_clear_log' ] );
		}
	}

	/**
	 * Adds the settings page under the WordPress Settings menu.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function add_page(): void {
		add_options_page(
			__( 'Ad Attribution', 'kntnt-ad-attr' ),
			__( 'Ad Attribution', 'kntnt-ad-attr' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ],
		);
	}

	/**
	 * Registers settings, sections, and fields with the Settings API.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function register_settings(): void {

		// Register the single option with sanitization callback.
		register_setting(
			self::SETTINGS_GROUP,
			Settings::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
			],
		);

		// Cookies section.
		add_settings_section(
			self::SECTION_COOKIES,
			__( 'Cookies', 'kntnt-ad-attr' ),
			fn() => printf(
				'<p>%s</p>',
				esc_html__( 'Configure cookie lifetime and conversion deduplication.', 'kntnt-ad-attr' ),
			),
			self::PAGE_SLUG,
		);

		// Logging section.
		add_settings_section(
			self::SECTION_LOGGING,
			__( 'Logging', 'kntnt-ad-attr' ),
			fn() => printf(
				'<p>%s</p>',
				esc_html__( 'Enable diagnostic logging for troubleshooting.', 'kntnt-ad-attr' ),
			),
			self::PAGE_SLUG,
		);

		// Queue (retry) section.
		add_settings_section(
			self::SECTION_QUEUE,
			__( 'Queue (Retry)', 'kntnt-ad-attr' ),
			fn() => printf(
				'<p>%s</p>',
				esc_html__( 'Configure retry behavior for queued conversion reports.', 'kntnt-ad-attr' ),
			),
			self::PAGE_SLUG,
		);

		// Register individual fields.
		$this->add_cookie_fields();
		$this->add_logging_fields();
		$this->add_queue_fields();
	}

	/**
	 * Sanitizes settings before they are saved.
	 *
	 * Trims string values, validates numeric fields, and removes empty
	 * strings so that filter defaults apply for unsaved settings.
	 *
	 * @param mixed $input Raw form input.
	 *
	 * @return array<string, mixed> Sanitized settings.
	 * @since 1.8.0
	 */
	public function sanitize_settings( mixed $input ): array {
		$input = is_array( $input ) ? $input : [];
		$clean = [];

		// Known keys.
		$known = [
			'cookie_lifetime',
			'dedup_seconds',
			'enable_logging',
			'log_file_size_max_KB',
			'log_file_size_min_KB',
			'attempts_per_round',
			'retry_delay',
			'max_rounds',
			'round_delay',
		];

		// Numeric keys that must be non-negative integers.
		$numeric_keys = [
			'cookie_lifetime',
			'dedup_seconds',
			'log_file_size_max_KB',
			'log_file_size_min_KB',
			'attempts_per_round',
			'retry_delay',
			'max_rounds',
			'round_delay',
		];

		foreach ( $input as $key => $value ) {

			// Discard unknown keys.
			if ( ! in_array( $key, $known, true ) ) {
				continue;
			}

			$value = is_string( $value ) ? trim( $value ) : (string) $value;

			// Handle checkbox: present means enabled, absent means disabled.
			if ( $key === 'enable_logging' ) {
				$clean[ $key ] = $value !== '' ? '1' : '';
				continue;
			}

			// Validate numeric fields.
			if ( in_array( $key, $numeric_keys, true ) ) {
				if ( $value === '' ) {
					continue; // Omit empty values so filter default applies.
				}
				$int_value = (int) $value;
				if ( $int_value >= 0 ) {
					$clean[ $key ] = $int_value;
				}
				continue;
			}

			// Other string values — omit if empty.
			if ( $value !== '' ) {
				$clean[ $key ] = $value;
			}
		}

		// Handle enable_logging when checkbox is unchecked (not submitted).
		if ( ! isset( $input['enable_logging'] ) ) {
			$clean['enable_logging'] = '';
		}

		return $clean;
	}

	/**
	 * Renders the settings page HTML.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function render_page(): void {
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				$this->render_section( self::SECTION_COOKIES );
				$this->render_section( self::SECTION_LOGGING );
				$this->render_section( self::SECTION_QUEUE );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handles the admin post request to download the log file.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function handle_download_log(): void {
		check_admin_referer( self::ACTION_DOWNLOAD_LOG );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'kntnt-ad-attr' ) );
		}

		if ( ! $this->logger->exists() ) {
			wp_die( esc_html__( 'Log file does not exist.', 'kntnt-ad-attr' ) );
		}

		$path = $this->logger->get_path();

		// Send the file as a download.
		nocache_headers();
		header( 'Content-Type: text/plain' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		readfile( $path );
		exit;
	}

	/**
	 * Handles the admin post request to clear the log file.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function handle_clear_log(): void {
		check_admin_referer( self::ACTION_CLEAR_LOG );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'kntnt-ad-attr' ) );
		}

		$this->logger->clear();

		// Redirect back to the settings page.
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Registers cookie-related fields.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function add_cookie_fields(): void {

		add_settings_field(
			'cookie_lifetime',
			__( 'Cookie Lifetime (days)', 'kntnt-ad-attr' ),
			fn() => $this->render_number_field( 'cookie_lifetime' ),
			self::PAGE_SLUG,
			self::SECTION_COOKIES,
			[ 'label_for' => 'cookie_lifetime' ],
		);

		add_settings_field(
			'dedup_seconds',
			__( 'Deduplication Window (seconds)', 'kntnt-ad-attr' ),
			fn() => $this->render_number_field( 'dedup_seconds' ),
			self::PAGE_SLUG,
			self::SECTION_COOKIES,
			[ 'label_for' => 'dedup_seconds' ],
		);
	}

	/**
	 * Registers logging-related fields.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function add_logging_fields(): void {

		// Enable logging checkbox.
		add_settings_field(
			'enable_logging',
			__( 'Enable Logging', 'kntnt-ad-attr' ),
			[ $this, 'render_logging_checkbox' ],
			self::PAGE_SLUG,
			self::SECTION_LOGGING,
			[ 'label_for' => 'enable_logging' ],
		);

		add_settings_field(
			'log_file_size_max_KB',
			__( 'Max Log File Size (KB)', 'kntnt-ad-attr' ),
			fn() => $this->render_number_field( 'log_file_size_max_KB' ),
			self::PAGE_SLUG,
			self::SECTION_LOGGING,
			[ 'label_for' => 'log_file_size_max_KB' ],
		);

		add_settings_field(
			'log_file_size_min_KB',
			__( 'Min Log File Size (KB)', 'kntnt-ad-attr' ),
			fn() => $this->render_number_field( 'log_file_size_min_KB' ),
			self::PAGE_SLUG,
			self::SECTION_LOGGING,
			[ 'label_for' => 'log_file_size_min_KB' ],
		);

		// Log file actions (download and clear).
		add_settings_field(
			'log_actions',
			__( 'Log File', 'kntnt-ad-attr' ),
			[ $this, 'render_log_actions' ],
			self::PAGE_SLUG,
			self::SECTION_LOGGING,
		);
	}

	/**
	 * Registers queue retry fields.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function add_queue_fields(): void {

		add_settings_field(
			'attempts_per_round',
			__( 'Attempts Per Round', 'kntnt-ad-attr' ),
			fn() => $this->render_number_field( 'attempts_per_round' ),
			self::PAGE_SLUG,
			self::SECTION_QUEUE,
			[ 'label_for' => 'attempts_per_round' ],
		);

		add_settings_field(
			'retry_delay',
			__( 'Seconds Between Attempts', 'kntnt-ad-attr' ),
			fn() => $this->render_number_field( 'retry_delay' ),
			self::PAGE_SLUG,
			self::SECTION_QUEUE,
			[ 'label_for' => 'retry_delay' ],
		);

		add_settings_field(
			'max_rounds',
			__( 'Number of Rounds', 'kntnt-ad-attr' ),
			fn() => $this->render_number_field( 'max_rounds' ),
			self::PAGE_SLUG,
			self::SECTION_QUEUE,
			[ 'label_for' => 'max_rounds' ],
		);

		add_settings_field(
			'round_delay',
			__( 'Seconds Between Rounds', 'kntnt-ad-attr' ),
			fn() => $this->render_number_field( 'round_delay' ),
			self::PAGE_SLUG,
			self::SECTION_QUEUE,
			[ 'label_for' => 'round_delay' ],
		);
	}

	/**
	 * Renders the enable logging checkbox.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function render_logging_checkbox(): void {
		$enabled = (bool) $this->settings->get( 'enable_logging' );
		printf(
			'<label><input type="checkbox" id="enable_logging" name="%s[enable_logging]" value="1"%s> %s</label>',
			esc_attr( Settings::OPTION_KEY ),
			checked( $enabled, true, false ),
			sprintf(
				/* translators: %s: Relative path to the log file */
				esc_html__( 'Write diagnostic log to %s', 'kntnt-ad-attr' ),
				'<code>' . esc_html( $this->logger->get_relative_path() ) . '</code>',
			),
		);
	}

	/**
	 * Renders the log download and clear action buttons.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function render_log_actions(): void {
		$exists = $this->logger->exists();
		$path   = $this->logger->get_path();

		// Show file size when the log exists.
		if ( $exists ) {
			printf(
				'<p class="description">%s</p>',
				sprintf(
					/* translators: %s: Human-readable file size */
					esc_html__( 'Current size: %s', 'kntnt-ad-attr' ),
					esc_html( size_format( (int) filesize( $path ) ) ),
				),
			);
		}

		// Download button.
		printf(
			'<a href="%s" class="button button-secondary"%s>%s</a> ',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_DOWNLOAD_LOG ), self::ACTION_DOWNLOAD_LOG ) ),
			$exists ? '' : ' disabled aria-disabled="true" style="pointer-events:none;opacity:.5"',
			esc_html__( 'Download Log', 'kntnt-ad-attr' ),
		);

		// Clear button.
		printf(
			'<a href="%s" class="button button-secondary"%s>%s</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_CLEAR_LOG ), self::ACTION_CLEAR_LOG ) ),
			$exists ? '' : ' disabled aria-disabled="true" style="pointer-events:none;opacity:.5"',
			esc_html__( 'Clear Log', 'kntnt-ad-attr' ),
		);
	}

	/**
	 * Renders a number input field with the filter default as placeholder.
	 *
	 * @param string $key Setting key.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function render_number_field( string $key ): void {
		$saved       = $this->settings->get_saved();
		$value       = $saved[ $key ] ?? '';
		$placeholder = $this->settings->get_filter_default( $key );

		printf(
			'<input type="number" id="%s" name="%s[%s]" value="%s" placeholder="%s" min="0" step="1" class="small-text" style="width:7em">',
			esc_attr( $key ),
			esc_attr( Settings::OPTION_KEY ),
			esc_attr( $key ),
			esc_attr( (string) $value ),
			esc_attr( (string) $placeholder ),
		);
	}

	/**
	 * Renders a single settings section with its heading and fields.
	 *
	 * Replicates the output of `do_settings_sections()` but for a single
	 * section, allowing custom layout between sections.
	 *
	 * @param string $section_id Section ID to render.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function render_section( string $section_id ): void {
		global $wp_settings_sections, $wp_settings_fields;

		if ( ! isset( $wp_settings_sections[ self::PAGE_SLUG ][ $section_id ] ) ) {
			return;
		}

		$section = $wp_settings_sections[ self::PAGE_SLUG ][ $section_id ];

		// Section heading.
		if ( $section['title'] ) {
			echo '<h2>' . esc_html( $section['title'] ) . '</h2>';
		}

		// Section callback (description text).
		if ( $section['callback'] ) {
			call_user_func( $section['callback'], $section );
		}

		// Fields table.
		if ( ! empty( $wp_settings_fields[ self::PAGE_SLUG ][ $section_id ] ) ) {
			echo '<table class="form-table" role="presentation">';
			do_settings_fields( self::PAGE_SLUG, $section_id );
			echo '</table>';
		}
	}

}
