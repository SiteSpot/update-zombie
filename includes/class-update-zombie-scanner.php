<?php
/**
 * Discovers pending updates and queues them for analysis.
 *
 * @package Update_Zombie
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads WordPress's own update transients and turns them into report rows.
 *
 * @since 0.1.0
 */
class Update_Zombie_Scanner {

	/**
	 * Scans every watched update source and enqueues anything new.
	 *
	 * @since 0.1.0
	 *
	 * @return int Number of reports created.
	 */
	public function scan_all() {
		$created = 0;

		if ( Update_Zombie_Settings::get( 'watch_plugins' ) ) {
			$created += $this->scan_plugins();
		}

		if ( Update_Zombie_Settings::get( 'watch_themes' ) ) {
			$created += $this->scan_themes();
		}

		if ( Update_Zombie_Settings::get( 'watch_core' ) ) {
			$created += $this->scan_core();
		}

		if ( $created ) {
			Update_Zombie_Log::record(
				Update_Zombie_Log::SCAN,
				sprintf(
					/* translators: %s: number of updates. */
					_n( '%s new update queued for analysis.', '%s new updates queued for analysis.', $created, 'update-zombie' ),
					number_format_i18n( $created )
				)
			);
		}

		return $created;
	}

	/**
	 * Records a newly queued update in the activity log.
	 *
	 * @since 0.3.0
	 *
	 * @param int $id New report ID.
	 * @return void
	 */
	protected function log_spotted( $id ) {
		$report = Update_Zombie_Store::get( $id );

		if ( ! $report ) {
			return;
		}

		Update_Zombie_Log::record(
			Update_Zombie_Log::UPDATE_SPOTTED,
			sprintf(
				/* translators: 1: old version, 2: new version. */
				__( 'Update available: %1$s to %2$s.', 'update-zombie' ),
				$report->old_version ? $report->old_version : __( 'unknown', 'update-zombie' ),
				$report->new_version
			),
			$report
		);
	}

	/**
	 * Queues pending plugin updates.
	 *
	 * @since 0.1.0
	 *
	 * @return int Number of reports created.
	 */
	public function scan_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$updates = get_site_transient( 'update_plugins' );

		if ( ! $updates || empty( $updates->response ) || ! is_array( $updates->response ) ) {
			return 0;
		}

		$installed = get_plugins();
		$created   = 0;

		foreach ( $updates->response as $plugin_file => $update ) {
			$update = (object) $update;

			if ( empty( $update->new_version ) || empty( $update->package ) ) {
				continue;
			}

			$data = $installed[ $plugin_file ] ?? array();
			$slug = ! empty( $update->slug ) ? $update->slug : dirname( $plugin_file );

			if ( '.' === $slug ) {
				$slug = basename( $plugin_file, '.php' );
			}

			$enqueued = Update_Zombie_Store::enqueue(
				array(
					'item_type'   => 'plugin',
					'item_slug'   => $slug,
					'item_file'   => $plugin_file,
					'item_name'   => $data['Name'] ?? $slug,
					'old_version' => $data['Version'] ?? '',
					'new_version' => $update->new_version,
				)
			);

			if ( $enqueued ) {
				$this->log_spotted( $enqueued );
				++$created;
			}
		}

		return $created;
	}

	/**
	 * Queues pending theme updates.
	 *
	 * @since 0.1.0
	 *
	 * @return int Number of reports created.
	 */
	public function scan_themes() {
		$updates = get_site_transient( 'update_themes' );

		if ( ! $updates || empty( $updates->response ) || ! is_array( $updates->response ) ) {
			return 0;
		}

		$created = 0;

		foreach ( $updates->response as $stylesheet => $update ) {
			$update = (array) $update;

			if ( empty( $update['new_version'] ) || empty( $update['package'] ) ) {
				continue;
			}

			$theme = wp_get_theme( $stylesheet );

			if ( ! $theme->exists() ) {
				continue;
			}

			$enqueued = Update_Zombie_Store::enqueue(
				array(
					'item_type'   => 'theme',
					'item_slug'   => $stylesheet,
					'item_file'   => $stylesheet,
					'item_name'   => $theme->get( 'Name' ),
					'old_version' => $theme->get( 'Version' ),
					'new_version' => $update['new_version'],
				)
			);

			if ( $enqueued ) {
				$this->log_spotted( $enqueued );
				++$created;
			}
		}

		return $created;
	}

	/**
	 * Queues a pending core update.
	 *
	 * @since 0.1.0
	 *
	 * @return int Number of reports created.
	 */
	public function scan_core() {
		$update = self::pending_core_update();

		if ( ! $update ) {
			return 0;
		}

		$enqueued = Update_Zombie_Store::enqueue(
			array(
				'item_type'   => 'core',
				'item_slug'   => 'wordpress',
				'item_file'   => '',
				'item_name'   => __( 'WordPress core', 'update-zombie' ),
				'old_version' => self::current_core_version(),
				'new_version' => $update->current,
			)
		);

		if ( ! $enqueued ) {
			return 0;
		}

		$this->log_spotted( $enqueued );

		return 1;
	}

	/**
	 * Returns the pending core update object, if there is one for this locale.
	 *
	 * @since 0.1.0
	 *
	 * @return object|null
	 */
	public static function pending_core_update() {
		$updates = get_site_transient( 'update_core' );

		if ( ! $updates || empty( $updates->updates ) || ! is_array( $updates->updates ) ) {
			return null;
		}

		$locale = get_locale();

		foreach ( $updates->updates as $update ) {
			if ( ! isset( $update->response ) || 'upgrade' !== $update->response ) {
				continue;
			}

			if ( empty( $update->current ) || empty( $update->packages ) ) {
				continue;
			}

			if ( isset( $update->locale ) && $update->locale !== $locale && 'en_US' !== $update->locale ) {
				continue;
			}

			return $update;
		}

		return null;
	}

	/**
	 * Returns the running core version.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function current_core_version() {
		global $wp_version;

		return (string) $wp_version;
	}

	/**
	 * Returns the download URL for a queued report's package.
	 *
	 * @since 0.1.0
	 *
	 * @param object $report Report row.
	 * @return string Package URL, or an empty string when it can no longer be resolved.
	 */
	public static function package_url( $report ) {
		if ( 'plugin' === $report->item_type ) {
			$updates = get_site_transient( 'update_plugins' );
			$update  = $updates->response[ $report->item_file ] ?? null;

			return $update && ! empty( $update->package ) ? (string) $update->package : '';
		}

		if ( 'theme' === $report->item_type ) {
			$updates = get_site_transient( 'update_themes' );
			$update  = (array) ( $updates->response[ $report->item_slug ] ?? array() );

			return ! empty( $update['package'] ) ? (string) $update['package'] : '';
		}

		if ( 'core' === $report->item_type ) {
			$update = self::pending_core_update();

			if ( ! $update || $update->current !== $report->new_version ) {
				return '';
			}

			$packages = (array) $update->packages;

			// Prefer the no-content build: same code, none of the bundled themes.
			foreach ( array( 'no_content', 'full' ) as $key ) {
				if ( ! empty( $packages[ $key ] ) ) {
					return (string) $packages[ $key ];
				}
			}
		}

		return '';
	}

	/**
	 * Returns the absolute path of the currently installed copy of an item.
	 *
	 * @since 0.1.0
	 *
	 * @param object $report Report row.
	 * @return string Directory or file path, or an empty string when not found.
	 */
	public static function installed_path( $report ) {
		if ( 'plugin' === $report->item_type ) {
			$dir = dirname( $report->item_file );

			if ( '.' === $dir || '' === $dir ) {
				$path = WP_PLUGIN_DIR . '/' . $report->item_file;

				return file_exists( $path ) ? $path : '';
			}

			$path = WP_PLUGIN_DIR . '/' . $dir;

			return is_dir( $path ) ? $path : '';
		}

		if ( 'theme' === $report->item_type ) {
			$theme = wp_get_theme( $report->item_slug );

			return $theme->exists() ? $theme->get_stylesheet_directory() : '';
		}

		if ( 'core' === $report->item_type ) {
			return untrailingslashit( ABSPATH );
		}

		return '';
	}
}
