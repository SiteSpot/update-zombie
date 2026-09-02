<?php
/**
 * Changelog extraction and security-signal heuristics.
 *
 * @package Update_Zombie
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pulls the release notes out of an update package and scores them for
 * security language, so the model's verdict has something to corroborate.
 *
 * @since 0.1.0
 */
class Update_Zombie_Changelog {

	/**
	 * Phrases that suggest a release fixes a vulnerability.
	 *
	 * @since 0.1.0
	 * @var string[]
	 */
	protected static $keywords = array(
		'security',
		'vulnerability',
		'vulnerabilities',
		'exploit',
		'cve-',
		'xss',
		'cross-site scripting',
		'csrf',
		'cross-site request forgery',
		'sql injection',
		'sqli',
		'rce',
		'remote code execution',
		'privilege escalation',
		'authentication bypass',
		'auth bypass',
		'unauthenticated',
		'arbitrary file',
		'path traversal',
		'directory traversal',
		'object injection',
		'ssrf',
		'lfi',
		'hardening',
		'sanitization',
		'sanitisation',
		'escaping',
		'nonce check',
		'capability check',
		'patchstack',
		'wordfence',
		'wpscan',
		'responsibly disclosed',
	);

	/**
	 * Extracts release notes and signals for a queued report.
	 *
	 * @since 0.1.0
	 *
	 * @param object                 $report  Report row.
	 * @param Update_Zombie_Package  $package Extracted package.
	 * @return array{notes: string, keywords: string[], cves: string[], is_point_release: bool, source: string}
	 */
	public function for_report( $report, Update_Zombie_Package $package ) {
		$notes  = '';
		$source = '';

		if ( 'core' !== $report->item_type ) {
			foreach ( array( 'readme.txt', 'README.txt', 'readme.md', 'README.md', 'CHANGELOG.md', 'changelog.txt' ) as $candidate ) {
				$contents = $package->read( $candidate );

				if ( '' === $contents ) {
					continue;
				}

				$section = $this->extract_version_section( $contents, $report->new_version );

				if ( '' !== $section ) {
					$notes  = $section;
					$source = $candidate;
					break;
				}

				if ( '' === $notes ) {
					$notes  = $this->extract_changelog_head( $contents );
					$source = $candidate;
				}
			}
		}

		$notes = trim( $notes );

		if ( '' === $notes ) {
			$notes  = $this->transient_notice( $report );
			$source = $notes ? 'update-transient' : $source;
		}

		$notes = $this->truncate( $notes, 8000 );

		return array(
			'notes'            => $notes,
			'keywords'         => $this->match_keywords( $notes ),
			'cves'             => $this->match_cves( $notes ),
			'is_point_release' => self::is_point_release( $report->old_version, $report->new_version ),
			'source'           => $source,
		);
	}

	/**
	 * Finds the changelog entry for a specific version.
	 *
	 * Handles both readme.txt headings (`= 1.2.3 =`) and Markdown headings
	 * (`## 1.2.3`), with or without a trailing date.
	 *
	 * @since 0.1.0
	 *
	 * @param string $contents Changelog file contents.
	 * @param string $version  Version to look for.
	 * @return string
	 */
	protected function extract_version_section( $contents, $version ) {
		$contents = str_replace( array( "\r\n", "\r" ), "\n", $contents );
		$quoted   = preg_quote( $version, '/' );

		$patterns = array(
			'/^=\s*v?' . $quoted . '\b.*$/mi',
			'/^#{1,4}\s*\[?v?' . $quoted . '\b.*$/mi',
			'/^v?' . $quoted . '\s*[-–—:]\s*.*$/mi',
		);

		foreach ( $patterns as $pattern ) {
			if ( ! preg_match( $pattern, $contents, $match, PREG_OFFSET_CAPTURE ) ) {
				continue;
			}

			$start = $match[0][1];
			$rest  = substr( $contents, $start + strlen( $match[0][0] ) );

			// Stop at the next heading of the same shape.
			if ( preg_match( '/^(=\s*v?\d|#{1,4}\s*\[?v?\d)/mi', $rest, $next, PREG_OFFSET_CAPTURE ) ) {
				$rest = substr( $rest, 0, $next[0][1] );
			}

			return trim( $match[0][0] . "\n" . $rest );
		}

		return '';
	}

	/**
	 * Falls back to the top of the changelog section when the exact version
	 * heading cannot be found.
	 *
	 * @since 0.1.0
	 *
	 * @param string $contents Changelog file contents.
	 * @return string
	 */
	protected function extract_changelog_head( $contents ) {
		$contents = str_replace( array( "\r\n", "\r" ), "\n", $contents );

		if ( preg_match( '/^==\s*Changelog\s*==\s*$/mi', $contents, $match, PREG_OFFSET_CAPTURE ) ) {
			$rest = substr( $contents, $match[0][1] + strlen( $match[0][0] ) );

			if ( preg_match( '/^==\s*\w/m', $rest, $next, PREG_OFFSET_CAPTURE ) ) {
				$rest = substr( $rest, 0, $next[0][1] );
			}

			return trim( $rest );
		}

		return '';
	}

	/**
	 * Reads the upgrade notice WordPress already has for this item.
	 *
	 * @since 0.1.0
	 *
	 * @param object $report Report row.
	 * @return string
	 */
	protected function transient_notice( $report ) {
		if ( 'plugin' !== $report->item_type ) {
			return '';
		}

		$updates = get_site_transient( 'update_plugins' );
		$update  = $updates->response[ $report->item_file ] ?? null;

		if ( ! $update ) {
			return '';
		}

		/*
		 * Self-hosted plugins (EDD, Plugin Update Checker, Freemius and the
		 * like) usually carry their changelog in the update data rather than
		 * in a readme.txt inside the zip. Prefer the entry for this version.
		 */
		$sections  = (array) ( $update->sections ?? array() );
		$changelog = (string) ( $sections['changelog'] ?? '' );

		if ( '' !== $changelog ) {
			$text    = wp_strip_all_tags( html_entity_decode( $changelog, ENT_QUOTES ) );
			$section = $this->extract_version_section( $text, $report->new_version );

			return '' !== $section ? $section : $text;
		}

		if ( ! empty( $update->upgrade_notice ) ) {
			return wp_strip_all_tags( (string) $update->upgrade_notice );
		}

		return '';
	}

	/**
	 * Returns the security keywords present in some text.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Text to scan.
	 * @return string[]
	 */
	public function match_keywords( $text ) {
		$found = array();

		foreach ( self::$keywords as $keyword ) {
			// Word boundaries, not substrings: short acronyms like "rce" and
			// "xss" otherwise fire on "source", "resource" and "xsslib", which
			// turns half of every changelog into a false security signal.
			$pattern = '/\b' . preg_quote( $keyword, '/' ) . ( preg_match( '/\w$/', $keyword ) ? '\b' : '' ) . '/i';

			if ( preg_match( $pattern, $text ) ) {
				$found[] = $keyword;
			}
		}

		return array_values( array_unique( $found ) );
	}

	/**
	 * Returns any CVE identifiers mentioned in some text.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text Text to scan.
	 * @return string[]
	 */
	public function match_cves( $text ) {
		if ( ! preg_match_all( '/CVE-\d{4}-\d{4,7}/i', $text, $matches ) ) {
			return array();
		}

		return array_values( array_unique( array_map( 'strtoupper', $matches[0] ) ) );
	}

	/**
	 * Decides whether a version bump is a point release.
	 *
	 * Used to keep core auto-updates to minor releases.
	 *
	 * @since 0.1.0
	 *
	 * @param string $old Old version.
	 * @param string $new New version.
	 * @return bool
	 */
	public static function is_point_release( $old, $new ) {
		$old_parts = array_map( 'intval', explode( '.', preg_replace( '/[^0-9.].*$/', '', (string) $old ) ) );
		$new_parts = array_map( 'intval', explode( '.', preg_replace( '/[^0-9.].*$/', '', (string) $new ) ) );

		if ( count( $old_parts ) < 2 || count( $new_parts ) < 2 ) {
			return false;
		}

		return $old_parts[0] === $new_parts[0] && $old_parts[1] === $new_parts[1];
	}

	/**
	 * Truncates text on a line boundary.
	 *
	 * @since 0.1.0
	 *
	 * @param string $text  Text to truncate.
	 * @param int    $limit Maximum length.
	 * @return string
	 */
	protected function truncate( $text, $limit ) {
		if ( strlen( $text ) <= $limit ) {
			return $text;
		}

		$cut  = substr( $text, 0, $limit );
		$last = strrpos( $cut, "\n" );

		if ( false !== $last && $last > $limit / 2 ) {
			$cut = substr( $cut, 0, $last );
		}

		return $cut . "\n[…changelog truncated…]";
	}
}
