<?php
/**
 * Diagnostic logger for Ad Attribution.
 *
 * Writes timestamped entries to a dedicated log file in wp-content/uploads/.
 * Logging is controlled by the `enable_logging` setting. Sensitive values
 * (client_secret, refresh_token, access tokens) are masked to reveal only
 * the last 4 characters. Shared by core and companion plugins via a
 * configurable prefix.
 *
 * @package Kntnt\Ad_Attribution
 * @since   1.8.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * File-based diagnostic logger with size rotation and credential masking.
 *
 * @since 1.8.0
 */
final class Logger {

	/**
	 * Log directory name inside the uploads folder.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	public const DIR_NAME = 'kntnt-ad-attribution';

	/**
	 * Log filename.
	 *
	 * @var string
	 * @since 1.8.0
	 */
	private const FILE_NAME = 'kntnt-ad-attribution.log';

	/**
	 * Settings instance for checking enable_logging and file size limits.
	 *
	 * @var Settings
	 * @since 1.8.0
	 */
	private readonly Settings $settings;

	/**
	 * Creates the logger with a Settings dependency.
	 *
	 * @param Settings $settings Plugin settings instance.
	 *
	 * @since 1.8.0
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Logs an informational message with a prefix.
	 *
	 * @param string $prefix  Source identifier (e.g. 'CORE', 'GADS').
	 * @param string $message Log message.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function info( string $prefix, string $message ): void {
		$this->write( $prefix, 'INFO', $message );
	}

	/**
	 * Logs an error message with a prefix.
	 *
	 * @param string $prefix  Source identifier (e.g. 'CORE', 'GADS').
	 * @param string $message Log message.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function error( string $prefix, string $message ): void {
		$this->write( $prefix, 'ERROR', $message );
	}

	/**
	 * Masks a string, revealing only the last 4 characters.
	 *
	 * @param string $value String to mask.
	 *
	 * @return string Masked string (e.g. "****hA_Z") or empty if input is empty.
	 * @since 1.8.0
	 */
	public static function mask( string $value ): string {
		$visible = 4;
		$length  = strlen( $value );

		if ( $length === 0 ) {
			return '';
		}

		if ( $length <= $visible ) {
			return str_repeat( '*', $length );
		}

		return str_repeat( '*', $length - $visible ) . substr( $value, -$visible );
	}

	/**
	 * Returns the absolute path to the log file.
	 *
	 * @return string Full filesystem path.
	 * @since 1.8.0
	 */
	public function get_path(): string {
		return $this->get_dir() . '/' . self::FILE_NAME;
	}

	/**
	 * Returns the log file path relative to ABSPATH.
	 *
	 * @return string Relative path suitable for display.
	 * @since 1.8.0
	 */
	public function get_relative_path(): string {
		return str_replace( ABSPATH, '', $this->get_path() );
	}

	/**
	 * Reads the entire log file contents.
	 *
	 * @return string Log contents or empty string if the file doesn't exist.
	 * @since 1.8.0
	 */
	public function get_contents(): string {
		$path = $this->get_path();
		return file_exists( $path ) ? (string) file_get_contents( $path ) : '';
	}

	/**
	 * Deletes the log file.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	public function clear(): void {
		$path = $this->get_path();
		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Checks whether the log file exists.
	 *
	 * @return bool True if the log file exists.
	 * @since 1.8.0
	 */
	public function exists(): bool {
		return file_exists( $this->get_path() );
	}

	/**
	 * Returns the absolute path to the log directory.
	 *
	 * @return string Full filesystem path to the log directory.
	 * @since 1.8.0
	 */
	private function get_dir(): string {
		$upload_dir = wp_upload_dir();
		return $upload_dir['basedir'] . '/' . self::DIR_NAME;
	}

	/**
	 * Writes a timestamped entry to the log file.
	 *
	 * No-ops when logging is disabled. Trims the file when it exceeds
	 * the configurable size limit.
	 *
	 * @param string $prefix  Source identifier (e.g. 'CORE', 'GADS').
	 * @param string $level   Log level (INFO or ERROR).
	 * @param string $message Log message.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function write( string $prefix, string $level, string $message ): void {

		// No-op when logging is disabled.
		if ( ! $this->settings->get( 'enable_logging' ) ) {
			return;
		}

		$path = $this->get_path();
		$dir  = dirname( $path );

		// Ensure the directory exists.
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// Trim the file if it exceeds the max size limit.
		$max_bytes = (int) $this->settings->get( 'log_file_size_max_KB' ) * 1024;
		if ( file_exists( $path ) && filesize( $path ) > $max_bytes ) {
			$this->trim( $path );
		}

		// Format: [2026-02-26 13:19:03+01:00][CORE][INFO] message
		$timestamp = wp_date( 'Y-m-d H:i:sP' );
		$line      = "[{$timestamp}][{$prefix}][{$level}] {$message}" . PHP_EOL;

		file_put_contents( $path, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Trims the log file to approximately the configured minimum size.
	 *
	 * Reads the tail of the file and cuts at the nearest line boundary
	 * to avoid partial lines.
	 *
	 * @param string $path Absolute path to the log file.
	 *
	 * @return void
	 * @since 1.8.0
	 */
	private function trim( string $path ): void {
		$contents = file_get_contents( $path );

		if ( $contents === false ) {
			return;
		}

		// Keep approximately the configured minimum size.
		$keep_bytes = (int) $this->settings->get( 'log_file_size_min_KB' ) * 1024;
		$tail       = substr( $contents, -$keep_bytes );

		// Cut at the first newline to avoid a partial opening line.
		$first_newline = strpos( $tail, "\n" );
		if ( $first_newline !== false ) {
			$tail = substr( $tail, $first_newline + 1 );
		}

		file_put_contents( $path, $tail, LOCK_EX );
	}

}
