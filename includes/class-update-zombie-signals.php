<?php
/**
 * Deterministic change signals.
 *
 * @package Update_Zombie
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classifies what kinds of change an update contains, by reading the diff
 * directly rather than asking a model.
 *
 * These are facts, not judgements: a CSS file either changed or it did not.
 * Computing them here rather than in the prompt makes them exact, free,
 * identical between runs, and available even when the AI call fails or no
 * API key is configured.
 *
 * @since 0.3.0
 */
class Update_Zombie_Signals {

	const GROUP_STRUCTURE    = 'structure';
	const GROUP_BEHAVIOUR    = 'behaviour';
	const GROUP_DATA         = 'data';
	const GROUP_HOUSEKEEPING = 'housekeeping';

	const TONE_NEUTRAL = 'neutral';
	const TONE_NOTABLE = 'notable';
	const TONE_GOOD    = 'good';
	const TONE_ALERT   = 'alert';

	/**
	 * The WordPress calls that constitute a security check.
	 *
	 * @since 0.3.0
	 */
	const HARDENING_PATTERN = '/\b(current_user_can|wp_verify_nonce|check_admin_referer|check_ajax_referer|wp_nonce_field|wp_create_nonce|esc_html|esc_attr|esc_url|esc_js|esc_textarea|wp_kses|wp_kses_post|sanitize_[a-z_]+)\s*\(/i';

	/**
	 * Returns the signal catalogue.
	 *
	 * Each entry declares how it is detected:
	 * - "extensions": the changed file's extension is in this list.
	 * - "added"/"removed": a regex matched an added or removed diff line.
	 * - "path": a regex matched the file path.
	 *
	 * Patterns are deliberately matched against changed lines only, never
	 * context lines, so unrelated code near an edit cannot trigger a signal.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function definitions() {
		return array(

			// Structure and presentation.
			'styles'            => array(
				'label'      => __( 'Styling changed', 'update-zombie' ),
				'group'      => self::GROUP_STRUCTURE,
				'tone'       => self::TONE_NEUTRAL,
				'extensions' => array( 'css', 'scss', 'less', 'sass' ),
			),
			'markup'            => array(
				'label' => __( 'HTML structure modified', 'update-zombie' ),
				'group' => self::GROUP_STRUCTURE,
				'tone'  => self::TONE_NOTABLE,
				'both'  => '/<\/?[a-z][a-z0-9-]*(\s[^>]*)?>/i',
				'scope' => array( 'php', 'html', 'htm', 'twig', 'blade', 'tpl', 'jsx', 'tsx', 'vue', 'svelte' ),
			),
			'javascript'        => array(
				'label'      => __( 'JavaScript changed', 'update-zombie' ),
				'group'      => self::GROUP_STRUCTURE,
				'tone'       => self::TONE_NEUTRAL,
				'extensions' => array( 'js', 'mjs', 'cjs', 'jsx', 'ts', 'tsx' ),
			),
			'files_added'       => array(
				'label' => __( 'New files added', 'update-zombie' ),
				'group' => self::GROUP_STRUCTURE,
				'tone'  => self::TONE_NOTABLE,
			),
			'files_removed'     => array(
				'label' => __( 'Files removed', 'update-zombie' ),
				'group' => self::GROUP_STRUCTURE,
				'tone'  => self::TONE_NOTABLE,
			),

			// Behaviour and integrations.
			'http'              => array(
				'label' => __( 'New outbound HTTP calls', 'update-zombie' ),
				'group' => self::GROUP_BEHAVIOUR,
				'tone'  => self::TONE_ALERT,
				'added' => '/\b(wp_remote_(get|post|head|request)|wp_safe_remote_\w+|curl_init|curl_exec)\s*\(|file_get_contents\s*\(\s*[\'"]https?:/i',
			),
			'rest'              => array(
				'label' => __( 'REST routes changed', 'update-zombie' ),
				'group' => self::GROUP_BEHAVIOUR,
				'tone'  => self::TONE_NOTABLE,
				'both'  => '/\bregister_rest_route\s*\(|\bregister_rest_field\s*\(/i',
			),
			'ajax'              => array(
				'label' => __( 'AJAX handlers changed', 'update-zombie' ),
				'group' => self::GROUP_BEHAVIOUR,
				'tone'  => self::TONE_NOTABLE,
				'both'  => '/[\'"]wp_ajax(_nopriv)?_|admin-ajax\.php/i',
			),
			'cron'              => array(
				'label' => __( 'Scheduled tasks changed', 'update-zombie' ),
				'group' => self::GROUP_BEHAVIOUR,
				'tone'  => self::TONE_NOTABLE,
				'both'  => '/\bwp_(schedule_event|schedule_single_event|clear_scheduled_hook|next_scheduled)\s*\(|[\'"]cron_schedules[\'"]/i',
			),
			'redirects'         => array(
				'label' => __( 'Redirects changed', 'update-zombie' ),
				'group' => self::GROUP_BEHAVIOUR,
				'tone'  => self::TONE_NOTABLE,
				'added' => '/\bwp_(safe_)?redirect\s*\(|\bheader\s*\(\s*[\'"]Location:/i',
			),

			// Data and security.
			'schema'            => array(
				'label' => __( 'Database schema changed', 'update-zombie' ),
				'group' => self::GROUP_DATA,
				'tone'  => self::TONE_ALERT,
				'both'  => '/\bdbDelta\s*\(|\b(CREATE|ALTER|DROP|TRUNCATE)\s+TABLE\b/i',
			),
			'options'           => array(
				'label' => __( 'New stored options', 'update-zombie' ),
				'group' => self::GROUP_DATA,
				'tone'  => self::TONE_NEUTRAL,
				'added' => '/\b(add_option|update_option|add_site_option|update_site_option|register_setting)\s*\(/i',
			),
			'user_data'         => array(
				'label' => __( 'User data handling changed', 'update-zombie' ),
				'group' => self::GROUP_DATA,
				'tone'  => self::TONE_NOTABLE,
				'both'  => '/\b(add|update|delete)_user_meta\s*\(|\bwp_(insert|update)_user\s*\(/i',
			),
			'sql'               => array(
				'label' => __( 'Direct database queries changed', 'update-zombie' ),
				'group' => self::GROUP_DATA,
				'tone'  => self::TONE_NOTABLE,
				'both'  => '/\$wpdb->(query|get_(row|var|col|results)|prepare|insert|update|delete)\s*\(/i',
			),
			/*
			 * These two are measured as a net change, not a presence check.
			 * A modified line shows up as both a removal and an addition, so
			 * reformatting a line that merely contains esc_attr() would
			 * otherwise report "security checks removed" — the loudest signal
			 * here, firing on the most ordinary edit there is.
			 */
			'hardening_added'   => array(
				'label'     => __( 'Security checks added', 'update-zombie' ),
				'group'     => self::GROUP_DATA,
				'tone'      => self::TONE_GOOD,
				'net'       => self::HARDENING_PATTERN,
				'direction' => 'gain',
			),
			'hardening_removed' => array(
				'label'     => __( 'Security checks removed', 'update-zombie' ),
				'group'     => self::GROUP_DATA,
				'tone'      => self::TONE_ALERT,
				'net'       => self::HARDENING_PATTERN,
				'direction' => 'loss',
			),
			'dangerous'         => array(
				'label' => __( 'Risky PHP functions', 'update-zombie' ),
				'group' => self::GROUP_DATA,
				'tone'  => self::TONE_ALERT,
				'added' => '/\b(eval|assert|create_function|shell_exec|system|passthru|proc_open|popen|exec)\s*\(|\bbase64_decode\s*\(|\bunserialize\s*\(/i',
			),
			'file_ops'          => array(
				'label' => __( 'Filesystem writes changed', 'update-zombie' ),
				'group' => self::GROUP_DATA,
				'tone'  => self::TONE_NOTABLE,
				'added' => '/\b(file_put_contents|fwrite|unlink|rename|move_uploaded_file|mkdir|rmdir|chmod)\s*\(/i',
			),
		);
	}

	/**
	 * Detects signals across a set of diffed files.
	 *
	 * @since 0.3.0
	 *
	 * @param array<int, array<string, mixed>> $candidates Diffed files, each with path, change and diff.
	 * @param array<string, int>               $filtered   Counts of changed-but-not-diffed files by category.
	 * @param array<string, int>               $stats      Diff stats.
	 * @return array<string, mixed>
	 */
	public static function detect( array $candidates, array $filtered, array $stats ) {
		$definitions = self::definitions();
		$found       = array();

		foreach ( $candidates as $candidate ) {
			$path      = $candidate['path'];
			$extension = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );

			if ( 'added' === $candidate['change'] ) {
				self::record( $found, 'files_added', $path );
			} elseif ( 'removed' === $candidate['change'] ) {
				self::record( $found, 'files_removed', $path );
			}

			list( $added_lines, $removed_lines ) = self::split_changed_lines( (string) $candidate['diff'] );

			foreach ( $definitions as $key => $definition ) {
				if ( ! empty( $definition['extensions'] ) ) {
					if ( in_array( $extension, $definition['extensions'], true ) ) {
						self::record( $found, $key, $path );
					}
					continue;
				}

				if ( ! empty( $definition['scope'] ) && ! in_array( $extension, $definition['scope'], true ) ) {
					continue;
				}

				if ( ! empty( $definition['net'] ) ) {
					$gained = preg_match_all( $definition['net'], $added_lines );
					$lost   = preg_match_all( $definition['net'], $removed_lines );

					if ( 'gain' === $definition['direction'] && $gained > $lost ) {
						self::record( $found, $key, $path, $gained - $lost );
					} elseif ( 'loss' === $definition['direction'] && $lost > $gained ) {
						self::record( $found, $key, $path, $lost - $gained );
					}

					continue;
				}

				if ( ! empty( $definition['both'] ) ) {
					if ( preg_match( $definition['both'], $added_lines ) || preg_match( $definition['both'], $removed_lines ) ) {
						self::record( $found, $key, $path );
					}
					continue;
				}

				if ( ! empty( $definition['added'] ) && preg_match( $definition['added'], $added_lines ) ) {
					self::record( $found, $key, $path );
					continue;
				}

				if ( ! empty( $definition['removed'] ) && preg_match( $definition['removed'], $removed_lines ) ) {
					self::record( $found, $key, $path );
				}
			}
		}

		return array(
			'signals'       => $found,
			'filtered'      => array_filter( $filtered ),
			'lines_changed' => (int) ( $stats['lines_added'] ?? 0 ) + (int) ( $stats['lines_removed'] ?? 0 ),
			'lines_added'   => (int) ( $stats['lines_added'] ?? 0 ),
			'lines_removed' => (int) ( $stats['lines_removed'] ?? 0 ),
			'files_changed' => (int) ( $stats['files_changed'] ?? 0 ),
			'code_reviewed' => (int) ( $stats['files_included'] ?? 0 ) > 0,
		);
	}

	/**
	 * Records a signal hit.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, array<string, mixed>> $found Accumulator, by reference.
	 * @param string                              $key   Signal key.
	 * @param string                              $path  File the signal came from.
	 * @param int                                 $delta Net magnitude, for net-measured signals.
	 * @return void
	 */
	protected static function record( array &$found, $key, $path, $delta = 0 ) {
		if ( ! isset( $found[ $key ] ) ) {
			$found[ $key ] = array(
				'count' => 0,
				'files' => array(),
				'net'   => 0,
			);
		}

		++$found[ $key ]['count'];
		$found[ $key ]['net'] += (int) $delta;

		if ( count( $found[ $key ]['files'] ) < 8 ) {
			$found[ $key ]['files'][] = $path;
		}
	}

	/**
	 * Splits a unified diff into its added and removed lines.
	 *
	 * Context lines are dropped: matching against them would report a signal
	 * for code that merely sits near an edit.
	 *
	 * @since 0.3.0
	 *
	 * @param string $diff Unified diff for one file.
	 * @return array{0: string, 1: string} Added lines, removed lines.
	 */
	protected static function split_changed_lines( $diff ) {
		$added   = array();
		$removed = array();

		foreach ( explode( "\n", $diff ) as $line ) {
			if ( '' === $line ) {
				continue;
			}

			// Skip the file headers, which start with --- and +++.
			if ( 0 === strpos( $line, '+++' ) || 0 === strpos( $line, '---' ) ) {
				continue;
			}

			if ( '+' === $line[0] ) {
				$added[] = substr( $line, 1 );
			} elseif ( '-' === $line[0] ) {
				$removed[] = substr( $line, 1 );
			}
		}

		return array( implode( "\n", $added ), implode( "\n", $removed ) );
	}

	/**
	 * Returns whether an update looks like housekeeping only.
	 *
	 * True when files changed but none of them were code worth reviewing.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, mixed> $signals Detection result.
	 * @return bool
	 */
	public static function is_housekeeping_only( array $signals ) {
		if ( ! empty( $signals['signals'] ) ) {
			$interesting = array_diff( array_keys( $signals['signals'] ), array( 'files_added', 'files_removed' ) );

			if ( $interesting ) {
				return false;
			}
		}

		return ! empty( $signals['filtered'] ) && 0 === (int) $signals['lines_changed'];
	}

	/**
	 * Returns the label for a filtered-file category.
	 *
	 * @since 0.3.0
	 *
	 * @param string $key Category key.
	 * @return string
	 */
	public static function filtered_label( $key ) {
		$labels = array(
			'vendor'       => __( 'Bundled dependencies', 'update-zombie' ),
			'translations' => __( 'Translations', 'update-zombie' ),
			'assets'       => __( 'Images and fonts', 'update-zombie' ),
			'minified'     => __( 'Minified bundles', 'update-zombie' ),
			'other'        => __( 'Other non-code files', 'update-zombie' ),
		);

		return $labels[ $key ] ?? $key;
	}

	/**
	 * Returns a signal's label.
	 *
	 * @since 0.3.0
	 *
	 * @param string $key Signal key.
	 * @return string
	 */
	public static function label( $key ) {
		$definitions = self::definitions();

		return $definitions[ $key ]['label'] ?? $key;
	}

	/**
	 * Returns a signal's tone, used for colour.
	 *
	 * @since 0.3.0
	 *
	 * @param string $key Signal key.
	 * @return string
	 */
	public static function tone( $key ) {
		$definitions = self::definitions();

		return $definitions[ $key ]['tone'] ?? self::TONE_NEUTRAL;
	}

	/**
	 * Returns a signal's group.
	 *
	 * @since 0.3.0
	 *
	 * @param string $key Signal key.
	 * @return string
	 */
	public static function group( $key ) {
		$definitions = self::definitions();

		return $definitions[ $key ]['group'] ?? self::GROUP_STRUCTURE;
	}

	/**
	 * Returns the group labels, in display order.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string, string>
	 */
	public static function groups() {
		return array(
			self::GROUP_DATA         => __( 'Data and security', 'update-zombie' ),
			self::GROUP_BEHAVIOUR    => __( 'Behaviour and integrations', 'update-zombie' ),
			self::GROUP_STRUCTURE    => __( 'Structure and presentation', 'update-zombie' ),
			self::GROUP_HOUSEKEEPING => __( 'Housekeeping', 'update-zombie' ),
		);
	}

	/**
	 * Orders signal keys for display, most important first.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, mixed> $signals Signal map.
	 * @return string[]
	 */
	public static function sort_keys( array $signals ) {
		$tone_rank = array(
			self::TONE_ALERT   => 0,
			self::TONE_GOOD    => 1,
			self::TONE_NOTABLE => 2,
			self::TONE_NEUTRAL => 3,
		);

		$keys = array_keys( $signals );

		usort(
			$keys,
			static function ( $a, $b ) use ( $tone_rank ) {
				$rank_a = $tone_rank[ self::tone( $a ) ] ?? 9;
				$rank_b = $tone_rank[ self::tone( $b ) ] ?? 9;

				return $rank_a === $rank_b ? strcmp( $a, $b ) : $rank_a <=> $rank_b;
			}
		);

		return $keys;
	}
}
