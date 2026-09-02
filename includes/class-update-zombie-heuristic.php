<?php
/**
 * Verdicts without AI.
 *
 * @package Update_Zombie
 */

defined( 'ABSPATH' ) || exit;

/**
 * Produces a verdict from the changelog and the computed diff signals alone.
 *
 * This exists so the plugin is useful with no API key, no cost, and nothing
 * leaving the site. It is deliberately more cautious than the AI engine: it
 * can see that escaping was added to a file, but not whether that escaping
 * actually closes the hole the changelog claims. So it will call something a
 * likely security fix, and it will flag alarming changes, but it never claims
 * an update is good or bad on quality grounds — that needs reading the code.
 *
 * @since 0.3.0
 */
class Update_Zombie_Heuristic {

	/**
	 * Highest confidence this engine will ever report.
	 *
	 * Corroborating a changelog against code signals is real evidence, but it
	 * is not the same as reading the fix, and the number gates unattended
	 * installation. It should never look as certain as the AI engine.
	 *
	 * @since 0.3.0
	 */
	const MAX_CONFIDENCE = 75;

	/**
	 * Builds a verdict in the same shape the analyzer returns.
	 *
	 * @since 0.3.0
	 *
	 * @param object               $report    Report row.
	 * @param array<string, mixed> $signals   Detection result from Update_Zombie_Signals.
	 * @param array<string, mixed> $changelog Changelog payload.
	 * @return array<string, mixed>
	 */
	public function evaluate( $report, array $signals, array $changelog ) {
		$found    = $signals['signals'] ?? array();
		$keywords = $changelog['keywords'] ?? array();
		$cves     = $changelog['cves'] ?? array();

		$hardening_added   = isset( $found['hardening_added'] );
		$hardening_removed = isset( $found['hardening_removed'] );

		$claims_security = ! empty( $keywords ) || ! empty( $cves );

		// Both halves are required: a changelog saying "security" with no
		// matching code change is exactly the case this plugin exists to catch.
		$is_security = $claims_security && $hardening_added;

		$confidence = 0;

		if ( $is_security ) {
			$confidence = 35;

			if ( $cves ) {
				$confidence += 25;
			}

			if ( count( $keywords ) > 2 ) {
				$confidence += 10;
			}

			$confidence += min( 20, (int) $found['hardening_added']['count'] * 5 );

			if ( $hardening_removed ) {
				// Checks added in one place and removed in another is not a
				// clean fix, and not something to install unattended.
				$confidence -= 40;
			}

			if ( isset( $found['dangerous'] ) ) {
				$confidence -= 25;
			}

			$confidence = max( 0, min( self::MAX_CONFIDENCE, $confidence ) );
		}

		$concerns = $this->concerns( $found );

		$verdict = 'neutral';

		if ( $concerns ) {
			$verdict = 'questionable';
		} elseif ( $is_security && $confidence > 0 ) {
			$verdict = 'security';
		}

		return array(
			'is_security_fix'        => $is_security && $confidence > 0,
			'security_confidence'    => $confidence,
			'verdict'                => $verdict,
			'recommendation'         => $concerns ? 'review' : ( $is_security ? 'apply' : 'review' ),
			'headline'               => $this->headline( $report, $signals, $is_security, $concerns ),
			'summary'                => $this->summary( $report, $signals, $changelog, $is_security, $confidence ),
			'security_findings'      => $this->findings( $found, $cves, $confidence ),
			'concerns'               => $concerns,
			'breaking_changes'       => array(),
			'changelog_corroborates' => $claims_security,
			'changelog_cves'         => $cves,
			'engine'                 => 'signals',
			'analyzed_at'            => current_time( 'mysql', true ),
		);
	}

	/**
	 * Turns alarming signals into concerns.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, array<string, mixed>> $found Detected signals.
	 * @return array<int, array<string, string>>
	 */
	protected function concerns( array $found ) {
		$map = array(
			'hardening_removed' => array(
				'severity' => 'high',
				'title'    => __( 'Security checks were removed', 'update-zombie' ),
				'detail'   => __( 'Capability checks, nonce verification, escaping or sanitisation calls were deleted in this release. That is sometimes a legitimate refactor, but it is the single most common shape of an introduced vulnerability.', 'update-zombie' ),
			),
			'dangerous'         => array(
				'severity' => 'high',
				'title'    => __( 'Risky PHP functions were added', 'update-zombie' ),
				'detail'   => __( 'This release adds calls such as eval, base64_decode, unserialize or shell execution. These have legitimate uses, but they are also what obfuscated and backdoored code looks like.', 'update-zombie' ),
			),
			'http'              => array(
				'severity' => 'medium',
				'title'    => __( 'New outbound HTTP calls', 'update-zombie' ),
				'detail'   => __( 'The update adds code that contacts a remote server. Worth checking what it sends and where it goes, particularly if it runs on every page load.', 'update-zombie' ),
			),
			'schema'            => array(
				'severity' => 'medium',
				'title'    => __( 'Database schema changes', 'update-zombie' ),
				'detail'   => __( 'This release creates, alters or drops database tables. Schema changes are usually hard to reverse, so take a backup before installing.', 'update-zombie' ),
			),
		);

		$concerns = array();

		foreach ( $map as $key => $concern ) {
			if ( ! isset( $found[ $key ] ) ) {
				continue;
			}

			$files = $found[ $key ]['files'];

			$concerns[] = array(
				'severity' => $concern['severity'],
				'title'    => $concern['title'],
				'detail'   => $concern['detail'],
				'file'     => (string) ( $files[0] ?? '' ),
				'excerpt'  => '',
			);
		}

		return $concerns;
	}

	/**
	 * Builds security findings that name real files.
	 *
	 * The enforcer refuses to auto-install on a security claim that cites no
	 * file, so these have to be genuine or nothing installs.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, array<string, mixed>> $found Detected signals.
	 * @param string[]                            $cves       Advisory identifiers from the changelog.
	 * @param int                                 $confidence Heuristic confidence.
	 * @return array<int, array<string, mixed>>
	 */
	protected function findings( array $found, array $cves, $confidence ) {
		if ( ! isset( $found['hardening_added'] ) ) {
			return array();
		}

		$findings = array();

		foreach ( array_slice( $found['hardening_added']['files'], 0, 5 ) as $index => $file ) {
			$findings[] = array(
				'severity'   => 'unknown',
				'title'      => __( 'Security checks added', 'update-zombie' ),
				'detail'     => __( 'Capability checks, nonce verification, escaping or sanitisation calls were added to this file. Detected from the diff, not from reading the logic, so what it protects against is unknown.', 'update-zombie' ),
				'file'       => (string) $file,
				'confidence' => (int) $confidence,
				'excerpt'    => '',
				'identifier' => (string) ( $cves[ $index ] ?? ( $cves[0] ?? '' ) ),
			);
		}

		return $findings;
	}

	/**
	 * Writes the one-line headline.
	 *
	 * @since 0.3.0
	 *
	 * @param object               $report      Report row.
	 * @param array<string, mixed> $signals     Detection result.
	 * @param bool                 $is_security Whether this looks like a security fix.
	 * @param array<int, mixed>    $concerns    Concerns raised.
	 * @return string
	 */
	protected function headline( $report, array $signals, $is_security, array $concerns ) {
		if ( $concerns ) {
			return $concerns[0]['title'];
		}

		if ( $is_security ) {
			return __( 'Changelog claims a security fix, and the code adds matching checks.', 'update-zombie' );
		}

		if ( Update_Zombie_Signals::is_housekeeping_only( $signals ) ) {
			return __( 'Housekeeping only — no reviewable code changed.', 'update-zombie' );
		}

		$lines = (int) $signals['lines_changed'];

		return sprintf(
			/* translators: 1: number of lines, 2: number of files. */
			_n( '%1$s line changed across %2$s file.', '%1$s lines changed across %2$s files.', $lines, 'update-zombie' ),
			number_format_i18n( $lines ),
			number_format_i18n( (int) $signals['files_changed'] )
		);
	}

	/**
	 * Writes the summary paragraph.
	 *
	 * @since 0.3.0
	 *
	 * @param object               $report      Report row.
	 * @param array<string, mixed> $signals     Detection result.
	 * @param array<string, mixed> $changelog   Changelog payload.
	 * @param bool                 $is_security Whether this looks like a security fix.
	 * @param int                  $confidence  Security confidence.
	 * @return string
	 */
	protected function summary( $report, array $signals, array $changelog, $is_security, $confidence ) {
		$parts = array();

		$parts[] = sprintf(
			/* translators: 1: lines added, 2: lines removed, 3: number of files. */
			__( 'This release adds %1$s and removes %2$s lines across %3$s changed files.', 'update-zombie' ),
			number_format_i18n( (int) $signals['lines_added'] ),
			number_format_i18n( (int) $signals['lines_removed'] ),
			number_format_i18n( (int) $signals['files_changed'] )
		);

		$found = $signals['signals'] ?? array();

		if ( $found ) {
			$labels = array();

			foreach ( Update_Zombie_Signals::sort_keys( $found ) as $key ) {
				$labels[] = strtolower( Update_Zombie_Signals::label( $key ) );
			}

			$parts[] = sprintf(
				/* translators: %s: comma separated list of change types. */
				__( 'Detected changes: %s.', 'update-zombie' ),
				implode( ', ', array_slice( $labels, 0, 8 ) )
			);
		}

		if ( ! empty( $changelog['keywords'] ) ) {
			$parts[] = sprintf(
				/* translators: %s: comma separated list of phrases. */
				__( 'The changelog uses security language: %s.', 'update-zombie' ),
				implode( ', ', array_slice( $changelog['keywords'], 0, 6 ) )
			);
		} else {
			$parts[] = __( 'The changelog contains no security language.', 'update-zombie' );
		}

		if ( $is_security ) {
			$parts[] = sprintf(
				/* translators: %d: confidence percentage. */
				__( 'Both the changelog and the code point to a security fix, so this is treated as one at %d%% confidence.', 'update-zombie' ),
				$confidence
			);
		} elseif ( ! empty( $changelog['keywords'] ) ) {
			$parts[] = __( 'The changelog mentions security, but no matching security checks were added anywhere in the diff. That gap is worth a look before trusting the claim.', 'update-zombie' );
		}

		$parts[] = __( 'This verdict was produced without AI, from the changelog and a pattern scan of the diff. It cannot judge whether the code is correct or whether a fix is complete.', 'update-zombie' );

		return implode( ' ', $parts );
	}
}
