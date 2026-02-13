<?php

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution;

/**
 * Handles checking for plugin updates from GitHub.
 *
 * Hooks into the WordPress update process to check the GitHub repository
 * for new releases and present them in the WordPress admin area.
 *
 * @package Kntnt\Ad_Attribution
 * @since 1.0.0
 */
final class Updater {

	/**
	 * Checks for new plugin releases on GitHub.
	 *
	 * This is the callback function for the 'pre_set_site_transient_update_plugins'
	 * filter. It compares the installed version with the latest release tag on GitHub.
	 *
	 * @param object $transient The update transient object passed by the filter.
	 *
	 * @return object The (potentially modified) transient object.
	 * @since 1.0.0
	 */
	public function check_for_updates( object $transient ): object {

		// If WordPress hasn't checked recently, don't check again.
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		// Get data from the plugin's main file header.
		$plugin_data = Plugin::get_plugin_data();
		$github_uri = $plugin_data['PluginURI'] ?? '';

		// Extract the repository slug (e.g., "Kntnt/kntnt-ad-attribution") from the URI.
		$github_repo = $this->get_github_repo_from_uri( $github_uri );
		if ( ! $github_repo ) {
			return $transient;
		}

		// Fetch the latest release information from the GitHub API.
		$latest_release = $this->get_latest_github_release( $github_repo );
		if ( ! $latest_release ) {
			return $transient;
		}

		// Compare the currently installed version with the latest version from GitHub.
		$current_version = $plugin_data['Version'];
		$latest_version = ltrim( $latest_release->tag_name, 'v' );

		if ( version_compare( $current_version, $latest_version, '<' ) ) {
			$plugin_slug_path = plugin_basename( Plugin::get_plugin_file() );

			// Initialize package URL as null. It must be found.
			$package_url = null;

			// Look for a manually uploaded .zip asset.
			if ( ! empty( $latest_release->assets ) ) {
				foreach ( $latest_release->assets as $asset ) {
					if ( $asset->content_type === 'application/zip' ) {
						$package_url = $asset->browser_download_url;
						break; // Use the first .zip asset found.
					}
				}
			}

			// If no suitable package URL was found in the assets, do not proceed.
			if ( ! $package_url ) {
				return $transient;
			}

			// Create an object with the update information WordPress needs.
			$update_info = new \stdClass;
			$update_info->slug = dirname( $plugin_slug_path );
			$update_info->plugin = $plugin_slug_path;
			$update_info->new_version = $latest_version;
			$update_info->url = $latest_release->html_url;
			$update_info->package = $package_url; // Use the found package URL.
			$update_info->tested = $plugin_data['Requires at least'] ?? get_bloginfo( 'version' );

			// Add the update information to the WordPress transient.
			$transient->response[ $plugin_slug_path ] = $update_info;
		}

		return $transient;
	}

	/**
	 * Fetches the latest release data from the GitHub API.
	 *
	 * Performs a remote GET request to the GitHub API's 'latest release' endpoint.
	 *
	 * @param string $repo The repository name in 'user/repo' format.
	 *
	 * @return object|null The release data object on success, or null on failure.
	 * @since 1.0.0
	 */
	private function get_latest_github_release( string $repo ): ?object {
		$request_uri = "https://api.github.com/repos/{$repo}/releases/latest";
		$response = wp_remote_get( $request_uri );

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}

		$release_data = json_decode( wp_remote_retrieve_body( $response ) );

		if ( empty( $release_data ) || ! isset( $release_data->tag_name, $release_data->zipball_url ) ) {
			return null;
		}

		return $release_data;
	}

	/**
	 * Parses the GitHub repository slug from a URI.
	 *
	 * Extracts the 'user/repo' part from a full GitHub URL, such as
	 * 'https://github.com/user/repo'.
	 *
	 * @param string $uri The full GitHub Plugin URI from the plugin header.
	 *
	 * @return string|null The 'user/repo' slug on success, or null if the URI is invalid.
	 * @since 1.0.0
	 */
	private function get_github_repo_from_uri( string $uri ): ?string {
		if ( empty( $uri ) || ! str_contains( $uri, 'github.com' ) ) {
			return null;
		}

		$path = parse_url( $uri, PHP_URL_PATH );
		if ( ! $path ) {
			return null;
		}

		$parts = explode( '/', trim( $path, '/' ) );
		if ( count( $parts ) >= 2 ) {
			return "{$parts[0]}/{$parts[1]}";
		}

		return null;
	}

}