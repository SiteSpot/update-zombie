<?php
/**
 * Directory diffing, filtering and budgeting.
 *
 * @package Update_Zombie
 */

defined( 'ABSPATH' ) || exit;

/**
 * Compares the installed copy of an item against an extracted update package
 * and produces a filtered, prioritised, budget-capped unified diff.
 *
 * The filtering matters as much as the diff: a plugin update is mostly
 * vendor code, minified assets and translation files, none of which tell you
 * anything about whether the release is safe.
 *
 * @since 0.1.0
 */
class Update_Zombie_Differ {

	const CONTEXT_LINES = 3;

	/**
	 * Path fragments that are never worth reading.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	protected static $skip_fragments = array(
		'/vendor/',
		'/node_modules/',
		'/.git/',
		'/.github/',
		'/languages/',
		'/dist/fonts/',
		'/__macosx/',
	);

	/**
	 * Filenames that carry no review signal, or that must never leave the site.
	 *
	 * The credential-bearing entries matter for core updates, where the "old"
	 * side of the diff is ABSPATH itself: wp-config.php sits right next to the
	 * files we do want to read, and it must never reach a prompt.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	protected static $skip_basenames = array(
		'composer.lock',
		'package-lock.json',
		'yarn.lock',
		'.ds_store',
		'.gitignore',
		'.gitattributes',
		'wp-config.php',
		'wp-config-local.php',
		'wp-tests-config.php',
		'.htaccess',
		'.htpasswd',
		'.user.ini',
		'.env',
		'php.ini',
	);

	/**
	 * Extensions treated as binary or generated.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	protected static $skip_extensions = array(
		'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'bmp', 'svg',
		'woff', 'woff2', 'ttf', 'eot', 'otf',
		'mp3', 'mp4', 'ogg', 'webm', 'wav', 'mov',
		'zip', 'gz', 'tar', 'rar', 'pdf', 'psd',
		'mo', 'po', 'pot',
		'map', 'lock',
	);

	/**
	 * Tokens that raise a changed file's review priority.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	protected static $security_tokens = array(
		'wp_verify_nonce', 'check_admin_referer', 'check_ajax_referer', 'wp_nonce',
		'current_user_can', 'permission_callback', 'is_user_logged_in', 'capability',
		'sanitize_', 'esc_html', 'esc_attr', 'esc_url', 'wp_kses',
		'$wpdb->prepare', 'esc_sql', 'unserialize', 'maybe_unserialize',
		'eval(', 'base64_decode', 'shell_exec', 'system(', 'passthru', 'popen',
		'file_get_contents', 'file_put_contents', 'unlink(', 'move_uploaded_file',
		'$_get', '$_post', '$_request', '$_cookie', '$_files', '$_server',
		'extract(', 'create_function', 'call_user_func', 'preg_replace',
		'admin-ajax', 'rest_route', 'register_rest_route', 'wp_ajax_nopriv',
	);

	/**
	 * Path of the installed copy.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected $old_path;

	/**
	 * Path of the extracted package.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	protected $new_path;

	/**
	 * Runtime options.
	 *
	 * @since 0.1.0
	 * @var array<string, mixed>
	 */
	protected $args;

	/**
	 * Filtered files seen during the current collect() call.
	 *
	 * @since 0.3.0
	 * @var array<string, array<string, int>>
	 */
	protected $filtered = array();

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $old_path Installed copy (directory or single file).
	 * @param string               $new_path Extracted package root.
	 * @param array<string, mixed> $args     Options: max_file_bytes, char_budget, path_prefixes.
	 */
	public function __construct( $old_path, $new_path, array $args = array() ) {
		$this->old_path = untrailingslashit( $old_path );
		$this->new_path = untrailingslashit( $new_path );
		$this->args     = wp_parse_args(
			$args,
			array(
				'max_file_bytes' => 524288,
				'char_budget'    => 350000,
				/**
				 * Optional whitelist of top-level path prefixes to consider.
				 * Used for core, where the package and ABSPATH both contain far
				 * more than the code we care about.
				 *
				 * @var string[]
				 */
				'path_prefixes'  => array(),
			)
		);
	}

	/**
	 * Builds the diff.
	 *
	 * @since 0.1.0
	 *
	 * @return array{diff: string, stats: array<string, int>, files: array<int, array<string, mixed>>, omitted: string[]}
	 */
	public function build() {
		$this->filtered = array();
		$old_files      = $this->collect( $this->old_path );
		$old_filtered   = $this->filtered;

		$this->filtered = array();
		$new_files      = $this->collect( $this->new_path );
		$new_filtered   = $this->filtered;

		$filtered_counts = $this->compare_filtered( $old_filtered, $new_filtered );

		$paths = array_unique( array_merge( array_keys( $old_files ), array_keys( $new_files ) ) );
		sort( $paths );

		$candidates = array();
		$stats      = array(
			'added'    => 0,
			'removed'  => 0,
			'modified' => 0,
			'skipped'  => 0,
			'lines_added'   => 0,
			'lines_removed' => 0,
		);

		foreach ( $paths as $path ) {
			$in_old = isset( $old_files[ $path ] );
			$in_new = isset( $new_files[ $path ] );

			if ( $in_old && $in_new && $old_files[ $path ]['hash'] === $new_files[ $path ]['hash'] ) {
				continue;
			}

			if ( $in_new && $in_old ) {
				++$stats['modified'];
				$change = 'modified';
			} elseif ( $in_new ) {
				++$stats['added'];
				$change = 'added';
			} else {
				++$stats['removed'];
				$change = 'removed';
			}

			$max = (int) $this->args['max_file_bytes'];

			if ( ( $in_old && $old_files[ $path ]['size'] > $max ) || ( $in_new && $new_files[ $path ]['size'] > $max ) ) {
				++$stats['skipped'];
				$candidates[] = array(
					'path'     => $path,
					'change'   => $change,
					'priority' => 5,
					'diff'     => sprintf( "--- a/%s\n+++ b/%s\n@@ file too large to diff (%s) @@\n", $path, $path, size_format( max( $in_old ? $old_files[ $path ]['size'] : 0, $in_new ? $new_files[ $path ]['size'] : 0 ) ) ),
					'adds'     => 0,
					'dels'     => 0,
				);
				continue;
			}

			$old_text = $in_old ? $this->read( $this->old_path, $path ) : '';
			$new_text = $in_new ? $this->read( $this->new_path, $path ) : '';

			if ( $this->looks_binary( $old_text ) || $this->looks_binary( $new_text ) ) {
				++$stats['skipped'];
				continue;
			}

			$rendered = $this->render_unified( $path, $old_text, $new_text, $change );

			if ( '' === $rendered['diff'] ) {
				continue;
			}

			$stats['lines_added']   += $rendered['adds'];
			$stats['lines_removed'] += $rendered['dels'];

			$candidates[] = array(
				'path'     => $path,
				'change'   => $change,
				'priority' => $this->priority( $path, $rendered['diff'] ),
				'diff'     => $rendered['diff'],
				'adds'     => $rendered['adds'],
				'dels'     => $rendered['dels'],
			);
		}

		$stats['files_changed'] = count( $candidates );

		// Signals are detected across every diffed file, before the budget
		// drops any of them: what an update contains should not depend on how
		// much of it we could afford to send for review.
		$signals = Update_Zombie_Signals::detect( $candidates, $filtered_counts, $stats );

		$result            = $this->apply_budget( $candidates, $stats );
		$result['signals'] = $signals;

		return $result;
	}

	/**
	 * Sorts candidates by priority and packs them into the character budget.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array<string, mixed>> $candidates Diffed files.
	 * @param array<string, int>               $stats      Running stats.
	 * @return array{diff: string, stats: array<string, int>, files: array<int, array<string, mixed>>, omitted: string[]}
	 */
	protected function apply_budget( array $candidates, array $stats ) {
		usort(
			$candidates,
			static function ( $a, $b ) {
				if ( $a['priority'] === $b['priority'] ) {
					return strcmp( $a['path'], $b['path'] );
				}

				return $b['priority'] <=> $a['priority'];
			}
		);

		$budget   = (int) $this->args['char_budget'];
		$used     = 0;
		$included = array();
		$omitted  = array();
		$chunks   = array();

		foreach ( $candidates as $candidate ) {
			$length = strlen( $candidate['diff'] );

			if ( $used + $length > $budget ) {
				$remaining = $budget - $used;

				// Only bother truncating if a useful amount of the file still fits.
				if ( $remaining > 4000 ) {
					$candidate['diff'] = substr( $candidate['diff'], 0, $remaining - 60 ) . "\n@@ diff truncated to fit the analysis budget @@\n";
					$chunks[]          = $candidate['diff'];
					$included[]        = $candidate;
					$used              = $budget;
					continue;
				}

				$omitted[] = $candidate['path'];
				continue;
			}

			$chunks[]   = $candidate['diff'];
			$included[] = $candidate;
			$used      += $length;
		}

		$stats['files_changed']  = count( $candidates );
		$stats['files_included'] = count( $included );
		$stats['files_omitted']  = count( $omitted );
		$stats['diff_chars']     = $used;

		return array(
			'diff'    => implode( "\n", $chunks ),
			'stats'   => $stats,
			'files'   => array_map(
				static function ( $file ) {
					unset( $file['diff'] );

					return $file;
				},
				$included
			),
			'omitted' => $omitted,
		);
	}

	/**
	 * Walks a directory and returns a map of relative path to size and hash.
	 *
	 * @since 0.1.0
	 *
	 * @param string $base Directory or single file path.
	 * @return array<string, array{size: int, hash: string}>
	 */
	protected function collect( $base ) {
		$map = array();

		if ( ! $base ) {
			return $map;
		}

		if ( is_file( $base ) ) {
			$name = basename( $base );

			return array(
				$name => array(
					'size' => (int) filesize( $base ),
					'hash' => (string) md5_file( $base ),
				),
			);
		}

		if ( ! is_dir( $base ) ) {
			return $map;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		$base_length = strlen( $base ) + 1;

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || $file->isLink() ) {
				continue;
			}

			$relative = str_replace( '\\', '/', substr( $file->getPathname(), $base_length ) );
			$category = $this->should_skip( $relative );

			if ( 'excluded' === $category ) {
				continue;
			}

			if ( false !== $category ) {
				/*
				 * Filtered files are tracked by size only. They exist to answer
				 * "did the bundled dependencies also change?", and hashing every
				 * file under vendor/ would cost more than that answer is worth.
				 * The trade-off is that a same-size edit goes unnoticed here.
				 */
				$this->filtered[ $category ][ $relative ] = (int) $file->getSize();
				continue;
			}

			$map[ $relative ] = array(
				'size' => (int) $file->getSize(),
				'hash' => (string) md5_file( $file->getPathname() ),
			);
		}

		return $map;
	}

	/**
	 * Counts filtered files that differ between the two sides.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, array<string, int>> $old Filtered map for the installed copy.
	 * @param array<string, array<string, int>> $new Filtered map for the package.
	 * @return array<string, int> Category to number of files that changed.
	 */
	protected function compare_filtered( array $old, array $new ) {
		$counts     = array();
		$categories = array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) );

		foreach ( $categories as $category ) {
			$old_files = $old[ $category ] ?? array();
			$new_files = $new[ $category ] ?? array();
			$paths     = array_unique( array_merge( array_keys( $old_files ), array_keys( $new_files ) ) );
			$changed   = 0;

			foreach ( $paths as $path ) {
				$in_old = array_key_exists( $path, $old_files );
				$in_new = array_key_exists( $path, $new_files );

				if ( ! $in_old || ! $in_new || $old_files[ $path ] !== $new_files[ $path ] ) {
					++$changed;
				}
			}

			if ( $changed ) {
				$counts[ $category ] = $changed;
			}
		}

		return $counts;
	}

	/**
	 * Decides whether a relative path is worth diffing.
	 *
	 * @since 0.1.0
	 *
	 * @param string $relative Relative path, forward-slashed.
	 * @return string|false Skip category, "excluded" for paths out of scope
	 *                      entirely, or false to diff the file.
	 */
	protected function should_skip( $relative ) {
		$lower = strtolower( $relative );

		if ( $this->args['path_prefixes'] && ! $this->matches_prefix( $lower ) ) {
			return 'excluded';
		}

		// Credential-bearing files are excluded outright rather than merely
		// filtered: they must not be counted, listed or read.
		if ( in_array( basename( $lower ), self::$skip_basenames, true ) ) {
			return 'excluded';
		}

		if ( false !== strpos( '/' . $lower, '/languages/' ) ) {
			return 'translations';
		}

		foreach ( self::$skip_fragments as $fragment ) {
			if ( false !== strpos( '/' . $lower, $fragment ) ) {
				return 'vendor';
			}
		}

		$extension = pathinfo( $lower, PATHINFO_EXTENSION );

		if ( $extension && in_array( $extension, array( 'po', 'mo', 'pot' ), true ) ) {
			return 'translations';
		}

		// Minified bundles are unreadable and change on every build.
		if ( preg_match( '/\.min\.(js|css)$/', $lower ) ) {
			return 'minified';
		}

		if ( $extension && in_array( $extension, self::$skip_extensions, true ) ) {
			return in_array( $extension, array( 'map', 'lock' ), true ) ? 'other' : 'assets';
		}

		return false;
	}

	/**
	 * Checks a path against the configured prefix whitelist.
	 *
	 * A bare filename (no slash) counts as a top-level file and is always kept,
	 * which is how core's root PHP files stay in scope.
	 *
	 * @since 0.1.0
	 *
	 * @param string $lower Lower-cased relative path.
	 * @return bool
	 */
	protected function matches_prefix( $lower ) {
		if ( false === strpos( $lower, '/' ) ) {
			return true;
		}

		foreach ( (array) $this->args['path_prefixes'] as $prefix ) {
			if ( 0 === strpos( $lower, strtolower( trailingslashit( $prefix ) ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Scores a changed file so the most informative ones survive the budget.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Relative path.
	 * @param string $diff Rendered diff for the file.
	 * @return int
	 */
	protected function priority( $path, $diff ) {
		$lower     = strtolower( $path );
		$extension = pathinfo( $lower, PATHINFO_EXTENSION );

		$score = 10;

		switch ( $extension ) {
			case 'php':
				$score = 100;
				break;
			case 'js':
			case 'mjs':
			case 'jsx':
			case 'ts':
			case 'tsx':
				$score = 70;
				break;
			case 'htaccess':
			case 'html':
			case 'htm':
				$score = 40;
				break;
			case 'json':
				$score = 30;
				break;
			case 'css':
			case 'scss':
				$score = 15;
				break;
		}

		if ( preg_match( '/(readme|changelog|security)/', basename( $lower ) ) ) {
			$score = max( $score, 85 );
		}

		// Anything under a test directory is real code but rarely the point.
		if ( preg_match( '#(^|/)(tests?|spec|e2e|cypress)/#', $lower ) ) {
			$score -= 40;
		}

		if ( preg_match( '#(^|/)(build|assets/dist)/#', $lower ) ) {
			$score -= 20;
		}

		$haystack = strtolower( $diff );
		$hits     = 0;

		foreach ( self::$security_tokens as $token ) {
			if ( false !== strpos( $haystack, $token ) ) {
				++$hits;

				if ( $hits >= 6 ) {
					break;
				}
			}
		}

		$score += $hits * 12;

		return max( 1, $score );
	}

	/**
	 * Renders a unified diff for a single file.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path     Relative path.
	 * @param string $old_text Old contents.
	 * @param string $new_text New contents.
	 * @param string $change   One of added, removed, modified.
	 * @return array{diff: string, adds: int, dels: int}
	 */
	protected function render_unified( $path, $old_text, $new_text, $change ) {
		$empty = array(
			'diff' => '',
			'adds' => 0,
			'dels' => 0,
		);

		if ( ! self::load_text_diff() ) {
			return $empty;
		}

		$old_lines = '' === $old_text ? array() : explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $old_text ) );
		$new_lines = '' === $new_text ? array() : explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", $new_text ) );

		$diff    = new Text_Diff( 'auto', array( $old_lines, $new_lines ) );
		$entries = array();

		foreach ( $diff->getDiff() as $op ) {
			if ( $op instanceof Text_Diff_Op_copy ) {
				foreach ( (array) $op->orig as $line ) {
					$entries[] = array( ' ', $line );
				}
			} elseif ( $op instanceof Text_Diff_Op_delete ) {
				foreach ( (array) $op->orig as $line ) {
					$entries[] = array( '-', $line );
				}
			} elseif ( $op instanceof Text_Diff_Op_add ) {
				foreach ( (array) $op->final as $line ) {
					$entries[] = array( '+', $line );
				}
			} elseif ( $op instanceof Text_Diff_Op_change ) {
				foreach ( (array) $op->orig as $line ) {
					$entries[] = array( '-', $line );
				}
				foreach ( (array) $op->final as $line ) {
					$entries[] = array( '+', $line );
				}
			}
		}

		$hunks = $this->group_hunks( $entries );

		if ( ! $hunks ) {
			return $empty;
		}

		$header = sprintf(
			"--- %s\n+++ %s\n",
			'added' === $change ? '/dev/null' : 'a/' . $path,
			'removed' === $change ? '/dev/null' : 'b/' . $path
		);

		$adds = 0;
		$dels = 0;
		$body = '';

		foreach ( $hunks as $hunk ) {
			$body .= sprintf(
				"@@ -%d,%d +%d,%d @@\n",
				$hunk['old_start'],
				$hunk['old_count'],
				$hunk['new_start'],
				$hunk['new_count']
			);

			foreach ( $hunk['lines'] as $entry ) {
				if ( '+' === $entry[0] ) {
					++$adds;
				} elseif ( '-' === $entry[0] ) {
					++$dels;
				}

				$body .= $entry[0] . $entry[1] . "\n";
			}
		}

		return array(
			'diff' => $header . $body,
			'adds' => $adds,
			'dels' => $dels,
		);
	}

	/**
	 * Groups a flat entry list into unified hunks with surrounding context.
	 *
	 * @since 0.1.0
	 *
	 * @param array<int, array{0: string, 1: string}> $entries Flat diff entries.
	 * @return array<int, array<string, mixed>>
	 */
	protected function group_hunks( array $entries ) {
		$changed = array();

		foreach ( $entries as $index => $entry ) {
			if ( ' ' !== $entry[0] ) {
				$changed[] = $index;
			}
		}

		if ( ! $changed ) {
			return array();
		}

		// Precompute the old/new line number each entry starts at.
		$old_no = array();
		$new_no = array();
		$old    = 1;
		$new    = 1;

		foreach ( $entries as $index => $entry ) {
			$old_no[ $index ] = $old;
			$new_no[ $index ] = $new;

			if ( '-' === $entry[0] ) {
				++$old;
			} elseif ( '+' === $entry[0] ) {
				++$new;
			} else {
				++$old;
				++$new;
			}
		}

		$ranges = array();
		$start  = max( 0, $changed[0] - self::CONTEXT_LINES );
		$end    = min( count( $entries ) - 1, $changed[0] + self::CONTEXT_LINES );

		foreach ( array_slice( $changed, 1 ) as $index ) {
			if ( $index - self::CONTEXT_LINES <= $end + 1 ) {
				$end = min( count( $entries ) - 1, $index + self::CONTEXT_LINES );
				continue;
			}

			$ranges[] = array( $start, $end );
			$start    = max( 0, $index - self::CONTEXT_LINES );
			$end      = min( count( $entries ) - 1, $index + self::CONTEXT_LINES );
		}

		$ranges[] = array( $start, $end );

		$hunks = array();

		foreach ( $ranges as $range ) {
			list( $from, $to ) = $range;

			$lines     = array_slice( $entries, $from, $to - $from + 1 );
			$old_count = 0;
			$new_count = 0;

			foreach ( $lines as $entry ) {
				if ( '-' === $entry[0] ) {
					++$old_count;
				} elseif ( '+' === $entry[0] ) {
					++$new_count;
				} else {
					++$old_count;
					++$new_count;
				}
			}

			$hunks[] = array(
				'old_start' => $old_no[ $from ],
				'old_count' => $old_count,
				'new_start' => $new_no[ $from ],
				'new_count' => $new_count,
				'lines'     => $lines,
			);
		}

		return $hunks;
	}

	/**
	 * Loads the Text_Diff library bundled with core.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	protected static function load_text_diff() {
		if ( class_exists( 'Text_Diff' ) ) {
			return true;
		}

		$path = ABSPATH . WPINC . '/Text/Diff.php';

		if ( ! is_readable( $path ) ) {
			return false;
		}

		require_once $path;

		return class_exists( 'Text_Diff' );
	}

	/**
	 * Reads a file under a base directory.
	 *
	 * @since 0.1.0
	 *
	 * @param string $base     Base path.
	 * @param string $relative Relative path.
	 * @return string
	 */
	protected function read( $base, $relative ) {
		if ( is_file( $base ) ) {
			$path = $base;
		} else {
			$path = self::safe_join( $base, $relative );
		}

		if ( ! $path || ! is_readable( $path ) || ! is_file( $path ) ) {
			return '';
		}

		$contents = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file read, not an HTTP request.

		return false === $contents ? '' : $contents;
	}

	/**
	 * Detects binary content that slipped past the extension filter.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text File contents.
	 * @return bool
	 */
	protected function looks_binary( $text ) {
		if ( '' === $text ) {
			return false;
		}

		return false !== strpos( substr( $text, 0, 8192 ), "\0" );
	}

	/**
	 * Joins a relative path onto a base, refusing anything that escapes it.
	 *
	 * @since 0.1.0
	 *
	 * @param string $base     Base directory.
	 * @param string $relative Relative path.
	 * @return string Absolute path, or an empty string when the join is unsafe.
	 */
	public static function safe_join( $base, $relative ) {
		$base     = untrailingslashit( str_replace( '\\', '/', $base ) );
		$relative = ltrim( str_replace( '\\', '/', (string) $relative ), '/' );

		if ( '' === $relative || false !== strpos( '/' . $relative . '/', '/../' ) ) {
			return '';
		}

		return $base . '/' . $relative;
	}
}
