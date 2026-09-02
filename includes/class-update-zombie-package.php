<?php
/**
 * Update package download and extraction.
 *
 * @package Update_Zombie
 */

defined( 'ABSPATH' ) || exit;

/**
 * Downloads an update ZIP to a temporary directory and unpacks it.
 *
 * Nothing here touches the live install: packages are extracted somewhere
 * disposable purely so they can be read and diffed.
 *
 * @since 0.1.0
 */
class Update_Zombie_Package {

	/**
	 * Absolute path of the temporary working directory, if one is open.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected $work_dir = '';

	/**
	 * Absolute path of the extracted package root.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected $root = '';

	/**
	 * Downloads and extracts a package.
	 *
	 * @since 0.1.0
	 *
	 * @param string $url Package URL, as advertised by the update transient.
	 * @return string|WP_Error Absolute path to the extracted package root.
	 */
	public function fetch( $url ) {
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'update_zombie_bad_url', __( 'The update package URL is missing or invalid.', 'update-zombie' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$filesystem = $this->filesystem();

		if ( is_wp_error( $filesystem ) ) {
			return $filesystem;
		}

		// Downloading a large package is its own long operation, so give it a
		// fresh execution allowance rather than sharing the job's.
		Update_Zombie_Processor::extend_time_limit( 360 );

		$zip = download_url( $url, 300 );

		if ( is_wp_error( $zip ) ) {
			return $zip;
		}

		$work_dir = $this->make_work_dir();

		if ( is_wp_error( $work_dir ) ) {
			wp_delete_file( $zip );

			return $work_dir;
		}

		$unzipped = unzip_file( $zip, $work_dir );

		wp_delete_file( $zip );

		if ( is_wp_error( $unzipped ) ) {
			$this->cleanup();

			return $unzipped;
		}

		$root = $this->resolve_root( $work_dir );

		if ( is_wp_error( $root ) ) {
			$this->cleanup();

			return $root;
		}

		$this->root = $root;

		return $root;
	}

	/**
	 * Returns the extracted package root.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function root() {
		return $this->root;
	}

	/**
	 * Removes the temporary working directory.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function cleanup() {
		global $wp_filesystem;

		if ( ! $this->work_dir ) {
			return;
		}

		if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
			$wp_filesystem->delete( $this->work_dir, true );
		}

		$this->work_dir = '';
		$this->root     = '';
	}

	/**
	 * Initialises WP_Filesystem, requiring direct access.
	 *
	 * Anything other than the direct transport would need FTP credentials we
	 * cannot prompt for from cron, so bail with a clear message instead.
	 *
	 * @since 0.1.0
	 *
	 * @return true|WP_Error
	 */
	protected function filesystem() {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! WP_Filesystem() ) {
			return new WP_Error(
				'update_zombie_filesystem',
				__( 'Update Zombie needs direct filesystem access to unpack update packages, and this site is configured to use FTP or SSH credentials instead.', 'update-zombie' )
			);
		}

		if ( 'direct' !== $wp_filesystem->method ) {
			return new WP_Error(
				'update_zombie_filesystem_method',
				sprintf(
					/* translators: %s: filesystem transport name, e.g. ftpext. */
					__( 'Update Zombie needs direct filesystem access, but this site uses the "%s" transport.', 'update-zombie' ),
					$wp_filesystem->method
				)
			);
		}

		return true;
	}

	/**
	 * Creates a unique temporary directory.
	 *
	 * @since 0.1.0
	 *
	 * @return string|WP_Error
	 */
	protected function make_work_dir() {
		global $wp_filesystem;

		$base = trailingslashit( get_temp_dir() ) . 'update-zombie-' . wp_generate_password( 12, false ) . '/';

		if ( ! $wp_filesystem->mkdir( $base, FS_CHMOD_DIR ) ) {
			return new WP_Error( 'update_zombie_mkdir', __( 'Could not create a temporary directory to unpack the update.', 'update-zombie' ) );
		}

		$this->work_dir = $base;

		return $base;
	}

	/**
	 * Finds the real root inside an extracted package.
	 *
	 * Most packages wrap everything in a single directory named for the slug;
	 * a few dump their files at the top level.
	 *
	 * @since 0.1.0
	 *
	 * @param string $dir Extraction directory.
	 * @return string|WP_Error
	 */
	protected function resolve_root( $dir ) {
		$entries = array_values(
			array_diff( (array) scandir( $dir ), array( '.', '..', '__MACOSX' ) )
		);

		if ( ! $entries ) {
			return new WP_Error( 'update_zombie_empty_package', __( 'The update package unpacked to an empty directory.', 'update-zombie' ) );
		}

		if ( 1 === count( $entries ) && is_dir( trailingslashit( $dir ) . $entries[0] ) ) {
			return untrailingslashit( trailingslashit( $dir ) . $entries[0] );
		}

		return untrailingslashit( $dir );
	}

	/**
	 * Reads a file from inside the extracted package.
	 *
	 * @since 0.1.0
	 *
	 * @param string $relative_path Path relative to the package root.
	 * @param int    $max_bytes     Maximum number of bytes to read.
	 * @return string File contents, or an empty string when unreadable.
	 */
	public function read( $relative_path, $max_bytes = 262144 ) {
		if ( ! $this->root ) {
			return '';
		}

		$path = Update_Zombie_Differ::safe_join( $this->root, $relative_path );

		if ( ! $path || ! is_readable( $path ) || ! is_file( $path ) ) {
			return '';
		}

		$contents = file_get_contents( $path, false, null, 0, $max_bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local temporary file, not a remote request.

		return false === $contents ? '' : $contents;
	}

	/**
	 * Makes sure temporary files are cleaned up even on a fatal path.
	 *
	 * @since 0.1.0
	 */
	public function __destruct() {
		$this->cleanup();
	}
}
