<?php
/**
 * Main plugin class implementing singleton pattern.
 *
 * Manages plugin initialization, configuration, and provides central access
 * to plugin metadata and options. Coordinates between different plugin components.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.0.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

use LogicException;

/**
 * Singleton entry point for the plugin.
 *
 * Bootstraps all components, registers WordPress hooks, and exposes
 * helper methods for plugin metadata and options.
 *
 * @since 1.0.0
 */
final class Plugin {

	/**
	 * Singleton instance of the plugin.
	 *
	 * @var Plugin|null
	 * @since 1.0.0
	 */
	private static ?Plugin $instance = null;

	/**
	 * Updater component instance.
	 *
	 * @var Updater
	 * @since 1.0.0
	 */
	public readonly Updater $updater;

	/**
	 * Migrator component instance.
	 *
	 * @var Migrator
	 * @since 1.0.0
	 */
	public readonly Migrator $migrator;

	/**
	 * Post type component instance.
	 *
	 * @var Post_Type
	 * @since 1.0.0
	 */
	public readonly Post_Type $post_type;

	/**
	 * Cookie manager component instance.
	 *
	 * @var Cookie_Manager
	 * @since 1.0.0
	 */
	public readonly Cookie_Manager $cookie_manager;

	/**
	 * Consent resolver component instance.
	 *
	 * @var Consent
	 * @since 1.0.0
	 */
	public readonly Consent $consent;

	/**
	 * Bot detector component instance.
	 *
	 * @var Bot_Detector
	 * @since 1.0.0
	 */
	public readonly Bot_Detector $bot_detector;

	/**
	 * Click handler component instance.
	 *
	 * @var Click_Handler
	 * @since 1.0.0
	 */
	public readonly Click_Handler $click_handler;

	/**
	 * Admin page component instance.
	 *
	 * @var Admin_Page
	 * @since 1.0.0
	 */
	public readonly Admin_Page $admin_page;

	/**
	 * REST endpoint component instance.
	 *
	 * @var Rest_Endpoint
	 * @since 1.0.0
	 */
	public readonly Rest_Endpoint $rest_endpoint;

	/**
	 * Conversion handler component instance.
	 *
	 * @var Conversion_Handler
	 * @since 1.0.0
	 */
	public readonly Conversion_Handler $conversion_handler;

	/**
	 * Cron component instance.
	 *
	 * @var Cron
	 * @since 1.0.0
	 */
	public readonly Cron $cron;

	/**
	 * Click ID store component instance.
	 *
	 * @var Click_ID_Store
	 * @since 1.2.0
	 */
	public readonly Click_ID_Store $click_id_store;

	/**
	 * Queue component instance.
	 *
	 * @var Queue
	 * @since 1.2.0
	 */
	public readonly Queue $queue;

	/**
	 * Queue processor component instance.
	 *
	 * @var Queue_Processor
	 * @since 1.2.0
	 */
	public readonly Queue_Processor $queue_processor;

	/**
	 * Cached plugin metadata from header.
	 *
	 * @var array|null
	 * @since 1.0.0
	 */
	private static ?array $plugin_data = null;

	/**
	 * Path to the main plugin file.
	 *
	 * @var string|null
	 * @since 1.0.0
	 */
	private static ?string $plugin_file = null;

	/**
	 * Plugin slug derived from filename.
	 *
	 * @var string|null
	 * @since 1.0.0
	 */
	private static ?string $plugin_slug = null;

	/**
	 * Private constructor for singleton pattern.
	 *
	 * Initializes plugin components and registers WordPress hooks.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {

		// Initialize plugin components.
		$this->updater              = new Updater();
		$this->migrator             = new Migrator();
		$this->post_type            = new Post_Type();
		$this->cookie_manager       = new Cookie_Manager();
		$this->consent              = new Consent();
		$this->bot_detector         = new Bot_Detector();
		$this->click_id_store       = new Click_ID_Store();
		$this->queue                = new Queue();
		$this->queue_processor      = new Queue_Processor( $this->queue );
		$this->click_handler        = new Click_Handler( $this->cookie_manager, $this->consent, $this->bot_detector, $this->click_id_store );
		$this->conversion_handler   = new Conversion_Handler( $this->cookie_manager, $this->click_id_store, $this->queue, $this->queue_processor );
		$this->cron                 = new Cron( $this->click_id_store, $this->queue );
		$this->admin_page           = new Admin_Page( $this->queue );
		$this->rest_endpoint        = new Rest_Endpoint( $this->cookie_manager, $this->consent );

		// Register WordPress hooks.
		$this->register_hooks();
	}

	/**
	 * Gets the singleton instance of the plugin.
	 *
	 * Creates the instance if it doesn't exist, otherwise returns existing instance.
	 *
	 * @return Plugin The plugin instance.
	 * @since 1.0.0
	 */
	public static function get_instance(): Plugin {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Sets the plugin file path. Called from the main plugin file.
	 *
	 * Must be called before any other plugin methods that depend on file paths.
	 *
	 * @param string $file Full path to the main plugin file.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function set_plugin_file( string $file ): void {
		self::$plugin_file = $file;
	}

	/**
	 * Gets the plugin file path.
	 *
	 * @return string Full path to the main plugin file.
	 * @throws LogicException If plugin file hasn't been set.
	 * @since 1.0.0
	 */
	public static function get_plugin_file(): string {
		if ( self::$plugin_file === null ) {
			throw new LogicException( 'Plugin file must be set using set_plugin_file() before accessing plugin metadata.' );
		}
		return self::$plugin_file;
	}

	/**
	 * Gets URL to the plugin directory.
	 *
	 * @return string URL to the plugin directory.
	 * @since 1.0.0
	 */
	public static function get_plugin_url(): string {
		return plugin_dir_url( self::get_plugin_file() );
	}

	/**
	 * Gets the plugin data from the plugin header.
	 *
	 * Reads version information from the main plugin file header. Caches
	 * the result to avoid repeated file parsing.
	 *
	 * @return array {
	 *     Plugin data. Values will be empty if not supplied by the plugin.
	 *
	 *     @type string $Name            Name of the plugin.
	 *     @type string $PluginURI       Plugin URI.
	 *     @type string $Version         Plugin version.
	 *     @type string $Description     Plugin description.
	 *     @type string $Author          Plugin author's name.
	 *     @type string $AuthorURI       Plugin author's website address.
	 *     @type string $TextDomain      Plugin text domain.
	 *     @type string $DomainPath      Relative path to .mo files.
	 *     @type string $RequiresWP      Minimum required WordPress version.
	 *     @type string $RequiresPHP     Minimum required PHP version.
	 * }
	 * @since 1.0.0
	 */
	public static function get_plugin_data(): array {

		// Load plugin data if not already cached.
		if ( self::$plugin_data === null ) {

			// get_plugin_data() is only available in admin context by default.
			// The plugin can be instantiated on the frontend (for click handling),
			// so we ensure the function is available.
			if ( ! function_exists( 'get_plugin_data' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			// Disable translation to avoid triggering _load_textdomain_just_in_time
			// when called before `init` (e.g. from Migrator on `plugins_loaded`).
			self::$plugin_data = get_plugin_data( self::get_plugin_file(), true, false );
		}

		return self::$plugin_data;
	}

	/**
	 * Gets the plugin version from the plugin header.
	 *
	 * @return string Plugin version number.
	 * @since 1.0.0
	 */
	public static function get_version(): string {
		return self::get_plugin_data()['Version'] ?? '';
	}

	/**
	 * Gets the plugin slug based on filename (without .php).
	 *
	 * @return string Plugin slug.
	 * @since 1.0.0
	 */
	public static function get_slug(): string {
		if ( self::$plugin_slug === null ) {
			self::$plugin_slug = basename( self::get_plugin_file(), '.php' );
		}
		return self::$plugin_slug;
	}

	/**
	 * Terminates execution if the current user lacks the plugin capability.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function authorize(): void {
		if ( ! current_user_can( 'kntnt_ad_attr' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to access this page.', 'kntnt-ad-attr' ),
				esc_html__( 'Access Denied', 'kntnt-ad-attr' ),
				[ 'response' => 403 ],
			);
		}
	}

	/**
	 * Gets plugin option data from WordPress options table.
	 *
	 * Can retrieve the entire option array or a specific key within it.
	 * Option name is automatically generated from plugin slug.
	 *
	 * @param string|null $key Specific option key to retrieve, or null for entire option.
	 *
	 * @return mixed Option value or null if not found.
	 * @since 1.0.0
	 */
	public static function get_option( ?string $key = null ): mixed {
		$option_name = str_replace( '-', '_', self::get_slug() );
		$option      = \get_option( $option_name, [] );

		if ( $key !== null ) {
			return $option[ $key ] ?? null;
		}
		return $option;
	}

	/**
	 * Sets plugin option data in WordPress options table.
	 *
	 * Can set the entire option or update a specific key within the option array.
	 * Creates the option if it doesn't exist.
	 *
	 * @param mixed       $value The value to set.
	 * @param string|null $key   Specific option key to update, or null to replace entire option.
	 *
	 * @return bool True on success, false on failure.
	 * @since 1.0.0
	 */
	public static function set_option( mixed $value, ?string $key = null ): bool {
		$option_name = str_replace( '-', '_', self::get_slug() );

		if ( $key !== null ) {
			$option         = \get_option( $option_name, [] );
			$option[ $key ] = $value;
			return update_option( $option_name, $option );
		}

		return update_option( $option_name, $value );
	}

	/**
	 * Gets the plugin directory path.
	 *
	 * @return string Full path to the plugin directory.
	 * @since 1.0.0
	 */
	public static function get_plugin_dir(): string {
		return plugin_dir_path( self::get_plugin_file() );
	}

	/**
	 * Registers WordPress hooks for plugin functionality.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	private function register_hooks(): void {

		// Run pending database migrations before other components initialize.
		add_action( 'plugins_loaded', [ $this->migrator, 'run' ] );

		// Register custom post type and load translations.
		add_action( 'init', [ $this->post_type, 'register' ] );
		add_action( 'init', [ $this, 'load_textdomain' ] );

		// Check for updates from GitHub.
		add_filter( 'pre_set_site_transient_update_plugins', [ $this->updater, 'check_for_updates' ] );

		// Register cron callbacks and target-trash warning.
		$this->cron->register();

		// Register queue processor cron callback.
		add_action( 'kntnt_ad_attr_process_queue', [ $this->queue_processor, 'process' ] );

		// Register bot detection filters and robots.txt rule.
		$this->bot_detector->register();

		// Register rewrite rule, query var, and click handler.
		$this->click_handler->register();

		// Register conversion attribution handler.
		$this->conversion_handler->register();

		// Register admin page, REST endpoint, and plugin action link.
		$this->admin_page->register();
		$this->rest_endpoint->register();

		$plugin_basename = plugin_basename( self::get_plugin_file() );
		add_filter( "plugin_action_links_{$plugin_basename}", [ $this, 'add_action_link' ] );

		// Enqueue the client-side pending consent script on public pages.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_public_scripts' ] );
	}

	/**
	 * Loads the plugin text domain for translations.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'kntnt-ad-attr',
			false,
			dirname( plugin_basename( self::get_plugin_file() ) ) . '/languages',
		);
	}

	/**
	 * Adds a quick link to the plugin's admin page on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 *
	 * @return string[] Modified action links.
	 * @since 1.0.0
	 */
	public function add_action_link( array $links ): array {
		$url  = admin_url( 'tools.php?page=' . self::get_slug() );
		$link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Ad Attribution', 'kntnt-ad-attr' ) . '</a>';
		array_unshift( $links, $link );
		return $links;
	}

	/**
	 * Enqueues the client-side script for pending consent handling.
	 *
	 * The script picks up hashes from the transport cookie or URL fragment,
	 * stores them in sessionStorage, and sends them to the REST endpoint
	 * when consent is granted.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function enqueue_public_scripts(): void {
		wp_enqueue_script(
			'kntnt-ad-attribution',
			self::get_plugin_url() . 'js/pending-consent.js',
			[],
			self::get_version(),
			true,
		);

		wp_localize_script( 'kntnt-ad-attribution', 'kntntAdAttribution', [
			'restUrl' => rest_url( 'kntnt-ad-attribution/v1/set-cookie' ),
			'nonce'   => wp_create_nonce( 'wp_rest' ),
		] );
	}

	/**
	 * Handles plugin deactivation cleanup.
	 *
	 * Removes transient resources while preserving persistent data
	 * (table, CPT posts, options) for potential reactivation.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public static function deactivate(): void {

		// Remove scheduled cron jobs.
		wp_clear_scheduled_hook( 'kntnt_ad_attr_daily_cleanup' );
		wp_clear_scheduled_hook( 'kntnt_ad_attr_process_queue' );

		// Remove plugin transients.
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				    OR option_name LIKE %s",
				'_transient_kntnt_ad_attr_%',
				'_transient_timeout_kntnt_ad_attr_%',
			),
		);

		// Flush rewrite rules so the CPT's rules are removed.
		flush_rewrite_rules();
	}

	/**
	 * Prevents cloning of singleton instance.
	 *
	 * @throws LogicException Always throws to prevent cloning.
	 * @since 1.0.0
	 */
	private function __clone(): void {
		throw new LogicException( 'Cannot clone a singleton.' );
	}

	/**
	 * Prevents unserialization of singleton instance.
	 *
	 * @throws LogicException Always throws to prevent unserialization.
	 * @since 1.0.0
	 */
	public function __wakeup(): void {
		throw new LogicException( 'Cannot unserialize a singleton.' );
	}

}
