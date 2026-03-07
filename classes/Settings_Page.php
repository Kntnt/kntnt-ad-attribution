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
				esc_html__( 'Configure log file rotation limits. Enable/disable logging and manage log files under Tools > Ad Attribution.', 'kntnt-ad-attr' ),
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

		// Preserve enable_logging from the existing settings (managed on the Tools page).
		$saved = $this->settings->get_saved();
		if ( isset( $saved['enable_logging'] ) ) {
			$clean['enable_logging'] = $saved['enable_logging'];
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
