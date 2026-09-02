<?php
/**
 * Analysis pipeline.
 *
 * @package Update_Zombie
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs one queued report end to end: download, diff, judge, decide, notify.
 *
 * @since 0.1.0
 */
class Update_Zombie_Processor {

	const LOCK_KEY = 'update_zombie_processing';

	/**
	 * Claims and processes the next pending report.
	 *
	 * @since 0.1.0
	 *
	 * @return bool Whether a report was processed.
	 */
	public function process_next() {
		// Check the site-wide preconditions before claiming anything. Without
		// this, a missing provider would burn every queued item's retries on a
		// problem that has nothing to do with any particular update.
		if ( self::uses_ai() && is_wp_error( Update_Zombie_Analyzer::availability() ) ) {
			return false;
		}

		if ( ! $this->acquire_lock() ) {
			return false;
		}

		try {
			$report = Update_Zombie_Store::claim_next_pending();

			if ( ! $report ) {
				return false;
			}

			/*
			 * One phase per tick. Downloading and diffing a large plugin is
			 * minutes of work on its own, and so is the model call; sharing a
			 * single PHP execution between them is what makes analyses die
			 * halfway on hosts with a modest max_execution_time. The queue
			 * already runs every five minutes, so the second phase simply
			 * happens on the next one.
			 */
			if ( Update_Zombie_Store::STATUS_DIFFED === ( $report->claimed_from ?? '' ) ) {
				$result = $this->run_analysis_phase( $report );
			} else {
				$result = $this->run_diff_phase( $report );
			}

			if ( is_wp_error( $result ) ) {
				$this->fail( $report, $result );
			}

			return true;
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Processes a specific report, whatever its current status.
	 *
	 * @since 0.1.0
	 *
	 * @param object $report Report row.
	 * @return true|WP_Error
	 */
	public function process( $report ) {
		self::extend_time_limit( 300 );

		$result = $this->run( $report );

		if ( is_wp_error( $result ) ) {
			$this->fail( $report, $result );

			return $result;
		}

		return true;
	}

	/**
	 * The pipeline proper.
	 *
	 * @since 0.1.0
	 *
	 * @param object $report Report row.
	 * @return true|WP_Error
	 */
	protected function run( $report ) {
		/*
		 * Hold the row as "analyzing" for the whole manual run. If the diff
		 * phase parked it as "diffed", the cron queue could claim it between
		 * phases and analyse the same update a second time in parallel.
		 */
		Update_Zombie_Store::update( $report->id, array( 'status' => Update_Zombie_Store::STATUS_ANALYZING ) );

		$diffed = $this->run_diff_phase( $report, true );

		if ( is_wp_error( $diffed ) ) {
			return $diffed;
		}

		$fresh = Update_Zombie_Store::get( $report->id );

		if ( ! $fresh || Update_Zombie_Store::STATUS_ANALYZING !== $fresh->status ) {
			// Already finished, e.g. an identical package with nothing to read.
			return true;
		}

		return $this->run_analysis_phase( $fresh );
	}

	/**
	 * Phase one: download the package, diff it, and store the computed facts.
	 *
	 * Leaves the report in the diffed state with everything the analysis phase
	 * will need, so that phase does no network work beyond the model call.
	 *
	 * @since 0.3.3
	 *
	 * @param object $report Report row.
	 * @param bool   $hold   Keep the row as "analyzing" afterwards instead of
	 *                       parking it as "diffed" for the queue. Used when the
	 *                       caller will run the analysis phase itself.
	 * @return true|WP_Error
	 */
	protected function run_diff_phase( $report, $hold = false ) {
		if ( self::uses_ai() ) {
			$availability = Update_Zombie_Analyzer::availability();

			if ( is_wp_error( $availability ) ) {
				return $availability;
			}
		}

		Update_Zombie_Log::record(
			Update_Zombie_Log::ANALYSIS_START,
			sprintf(
				/* translators: %s: engine name. */
				__( 'Analysing with the %s engine.', 'update-zombie' ),
				self::uses_ai() ? __( 'AI', 'update-zombie' ) : __( 'no-AI', 'update-zombie' )
			),
			$report
		);

		$installed = Update_Zombie_Scanner::installed_path( $report );

		if ( ! $installed ) {
			return new WP_Error(
				'update_zombie_missing_install',
				__( 'The installed copy of this item could not be found, so there is nothing to compare against.', 'update-zombie' )
			);
		}

		$url = Update_Zombie_Scanner::package_url( $report );

		if ( ! $url ) {
			return new WP_Error(
				'update_zombie_no_package',
				__( 'WordPress is no longer offering this update, so its package could not be downloaded. It may have been installed already or withdrawn.', 'update-zombie' )
			);
		}

		$package = new Update_Zombie_Package();
		$root    = $package->fetch( $url );

		if ( is_wp_error( $root ) ) {
			return $root;
		}

		$differ = new Update_Zombie_Differ(
			$installed,
			$root,
			array(
				'max_file_bytes' => (int) Update_Zombie_Settings::get( 'max_file_bytes' ),
				'char_budget'    => (int) Update_Zombie_Settings::get( 'diff_char_budget' ),
				'path_prefixes'  => 'core' === $report->item_type ? array( 'wp-admin', 'wp-includes' ) : array(),
			)
		);

		$diff = $differ->build();

		// Store the computed signals immediately. They are facts derived from
		// the diff, so they stay useful even if the AI call below fails, and
		// they are what the no-AI engine reasons from.
		Update_Zombie_Store::update( $report->id, array( 'signals' => $diff['signals'] ) );

		if ( '' === $diff['diff'] && 0 === (int) $diff['stats']['files_changed'] ) {
			$package->cleanup();

			return $this->record_no_change( $report, $diff );
		}

		$changelog_reader = new Update_Zombie_Changelog();
		$changelog        = $changelog_reader->for_report( $report, $package );

		$package->cleanup();

		// Hand the next phase everything it needs. Held only until the verdict
		// is stored, then cleared: this is a work queue, not diff retention.
		Update_Zombie_Store::update(
			$report->id,
			array(
				'status'       => $hold ? Update_Zombie_Store::STATUS_ANALYZING : Update_Zombie_Store::STATUS_DIFFED,
				'attempts'     => 0,
				'prompt_cache' => wp_json_encode(
					array(
						'diff'      => $diff,
						'changelog' => $changelog,
					)
				),
			)
		);

		return true;
	}

	/**
	 * Phase two: judge the stored diff.
	 *
	 * @since 0.3.3
	 *
	 * @param object $report Report row, already diffed.
	 * @return true|WP_Error
	 */
	protected function run_analysis_phase( $report ) {
		$cached = json_decode( (string) $report->prompt_cache, true );

		if ( ! is_array( $cached ) || empty( $cached['diff'] ) ) {
			// The cache is gone, so start this item over rather than guess.
			Update_Zombie_Store::update(
				$report->id,
				array(
					'status'       => Update_Zombie_Store::STATUS_PENDING,
					'attempts'     => 0,
					'prompt_cache' => null,
				)
			);

			// Not a failure: the item is back in the queue and will be picked
			// up again. Returning an error here would have fail() overwrite
			// the pending status we just set with an error status.
			Update_Zombie_Log::record(
				Update_Zombie_Log::ANALYSIS_START,
				__( 'The stored diff was missing, so this update was queued to download again.', 'update-zombie' ),
				$report
			);

			return true;
		}

		$diff      = $cached['diff'];
		$changelog = $cached['changelog'];

		if ( self::uses_ai() ) {
			$analyzer = new Update_Zombie_Analyzer();
			$verdict  = $analyzer->analyze( $report, $diff, $changelog );
		} else {
			$heuristic = new Update_Zombie_Heuristic();
			$verdict   = $heuristic->evaluate( $report, $diff['signals'], $changelog );
		}

		if ( is_wp_error( $verdict ) ) {
			return $verdict;
		}

		$this->store_verdict( $report, $verdict, $diff, $changelog );

		return true;
	}

	/**
	 * Gives the current phase of work more execution time.
	 *
	 * Only ever raises the limit. Calling set_time_limit() unconditionally is
	 * a trap: where max_execution_time is already 0 — WP-CLI and most CLI
	 * SAPIs — passing a number imposes a ceiling that was not there before,
	 * and a long analysis then dies partway through a request it would
	 * otherwise have finished.
	 *
	 * @since 0.3.2
	 *
	 * @param int $seconds Seconds to allow.
	 * @return void
	 */
	public static function extend_time_limit( $seconds ) {
		if ( 0 === (int) ini_get( 'max_execution_time' ) ) {
			return;
		}

		if ( ! function_exists( 'set_time_limit' ) ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit -- Best effort; disabled on many hosts.
		@set_time_limit( (int) $seconds );
	}

	/**
	 * Returns whether the AI engine is selected.
	 *
	 * @since 0.3.0
	 *
	 * @return bool
	 */
	public static function uses_ai() {
		return Update_Zombie_Settings::ENGINE_AI === Update_Zombie_Settings::get( 'analysis_engine', Update_Zombie_Settings::ENGINE_AI );
	}

	/**
	 * Writes a completed verdict, applies policy and notifies.
	 *
	 * @since 0.1.0
	 *
	 * @param object               $report    Report row.
	 * @param array<string, mixed> $verdict   Verdict payload.
	 * @param array<string, mixed> $diff      Diff payload.
	 * @param array<string, mixed> $changelog Changelog payload.
	 * @return void
	 */
	protected function store_verdict( $report, array $verdict, array $diff, array $changelog ) {
		$payload = array(
			'engine'                 => $verdict['engine'] ?? 'ai',
			'security_substantiated' => $verdict['security_substantiated'] ?? true,
			'security_findings'      => $verdict['security_findings'],
			'concerns'               => $verdict['concerns'],
			'breaking_changes'       => $verdict['breaking_changes'],
			'changelog_corroborates' => $verdict['changelog_corroborates'],
			'changelog_cves'         => $verdict['changelog_cves'],
			'changelog_notes'        => $changelog['notes'],
			'changelog_source'       => $changelog['source'],
			'stats'                  => $diff['stats'],
			'files'                  => array_slice( $diff['files'], 0, 200 ),
			'omitted'                => array_slice( $diff['omitted'], 0, 200 ),
		);

		Update_Zombie_Store::update(
			$report->id,
			array(
				'status'         => Update_Zombie_Store::STATUS_COMPLETE,
				'verdict'        => $verdict['verdict'],
				'recommendation' => $verdict['recommendation'],
				'is_security'    => $verdict['is_security_fix'] ? 1 : 0,
				'confidence'     => (int) $verdict['security_confidence'],
				'headline'       => $verdict['headline'],
				'summary'        => $verdict['summary'],
				'payload'        => wp_json_encode( $payload ),
				'prompt_cache'   => null,
				'error_message'  => null,
				'analyzed_at'    => current_time( 'mysql', true ),
			)
		);

		$fresh = Update_Zombie_Store::get( $report->id );

		if ( ! $fresh ) {
			return;
		}

		Update_Zombie_Log::record(
			Update_Zombie_Log::VERDICT,
			sprintf(
				/* translators: 1: verdict label, 2: headline. */
				__( '%1$s — %2$s', 'update-zombie' ),
				Update_Zombie_Admin::verdict_label( $fresh->verdict ),
				$fresh->headline
			),
			$fresh,
			array(
				'engine'      => $payload['engine'],
				'is_security' => (bool) $fresh->is_security,
				'confidence'  => (int) $fresh->confidence,
			)
		);

		Update_Zombie_Enforcer::record_decision( $fresh );

		Update_Zombie_Log::record(
			Update_Zombie_Log::DECISION,
			Update_Zombie_Admin::decision_label( $fresh->decision ),
			$fresh,
			array( 'decision' => $fresh->decision )
		);

		/**
		 * Fires after an update has been analysed and a decision recorded.
		 *
		 * @since 0.1.0
		 *
		 * @param object               $fresh   The completed report row.
		 * @param array<string, mixed> $verdict The normalised verdict payload.
		 */
		do_action( 'update_zombie_verdict_recorded', $fresh, $verdict );

		$notifier = new Update_Zombie_Notifier();
		$notifier->notify( $fresh, $verdict );
	}

	/**
	 * Records the case where the package is byte-identical to what is installed.
	 *
	 * @since 0.1.0
	 *
	 * @param object               $report Report row.
	 * @param array<string, mixed> $diff   Diff payload.
	 * @return true
	 */
	protected function record_no_change( $report, array $diff ) {
		Update_Zombie_Store::update(
			$report->id,
			array(
				'status'         => Update_Zombie_Store::STATUS_COMPLETE,
				'verdict'        => 'neutral',
				'recommendation' => 'apply',
				'is_security'    => 0,
				'confidence'     => 0,
				'headline'       => __( 'No reviewable code changes in this release.', 'update-zombie' ),
				'summary'        => __( 'Every file that survived filtering is identical to the copy already installed. The release may contain only assets, translations or vendor updates, which Update Zombie does not read.', 'update-zombie' ),
				'payload'        => wp_json_encode(
					array(
						'security_findings' => array(),
						'concerns'          => array(),
						'breaking_changes'  => array(),
						'stats'             => $diff['stats'],
						'files'             => array(),
						'omitted'           => array(),
					)
				),
				'prompt_cache'   => null,
				'error_message'  => null,
				'analyzed_at'    => current_time( 'mysql', true ),
			)
		);

		return true;
	}

	/**
	 * Records a failed analysis.
	 *
	 * @since 0.1.0
	 *
	 * @param object   $report Report row.
	 * @param WP_Error $error  What went wrong.
	 * @return void
	 */
	protected function fail( $report, WP_Error $error ) {
		$fresh     = Update_Zombie_Store::get( $report->id );
		$attempts  = $fresh ? (int) $fresh->attempts : 0;
		$transient = in_array( $error->get_error_code(), array( 'update_zombie_ai_timeout', 'update_zombie_ai_transient' ), true );

		/*
		 * A slow or hiccuping provider is not a verdict on the update. Put the
		 * item back in the queue for the next tick instead of parking it as a
		 * failure someone has to notice and click. The diff is kept, so the
		 * retry is only the model call. Three strikes and it does stay failed.
		 */
		if ( $transient && $attempts < 3 ) {
			Update_Zombie_Store::update(
				$report->id,
				array(
					'status'        => ( $fresh && ! empty( $fresh->prompt_cache ) ) ? Update_Zombie_Store::STATUS_DIFFED : Update_Zombie_Store::STATUS_PENDING,
					'error_message' => $error->get_error_message(),
				)
			);

			Update_Zombie_Log::record(
				Update_Zombie_Log::ANALYSIS_ERROR,
				sprintf(
					/* translators: 1: error message, 2: attempt number. */
					__( '%1$s Retrying on the next queue run (attempt %2$d of 3).', 'update-zombie' ),
					$error->get_error_message(),
					$attempts + 1
				),
				$report,
				array(
					'code'  => $error->get_error_code(),
					'retry' => true,
				)
			);

			return;
		}

		Update_Zombie_Store::update(
			$report->id,
			array(
				'status'        => Update_Zombie_Store::STATUS_ERROR,
				'error_message' => $error->get_error_message(),
				'prompt_cache'  => null,
			)
		);

		Update_Zombie_Log::record(
			Update_Zombie_Log::ANALYSIS_ERROR,
			$error->get_error_message(),
			$report,
			array( 'code' => $error->get_error_code() )
		);

		$notifier = new Update_Zombie_Notifier();
		$notifier->notify_error( $report, $error->get_error_message() );
	}

	/**
	 * Requeues a report for another attempt.
	 *
	 * @since 0.1.0
	 *
	 * @param int $id Report ID.
	 * @return void
	 */
	public static function requeue( $id ) {
		Update_Zombie_Store::update(
			$id,
			array(
				'status'        => Update_Zombie_Store::STATUS_PENDING,
				'attempts'      => 0,
				'error_message' => null,
			)
		);
	}

	/**
	 * Takes the processing lock.
	 *
	 * Analyses are slow and download megabytes; overlapping runs would waste
	 * bandwidth and tokens on the same package.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	protected function acquire_lock() {
		if ( get_transient( self::LOCK_KEY ) ) {
			return false;
		}

		set_transient( self::LOCK_KEY, time(), 15 * MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Releases the processing lock.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	protected function release_lock() {
		delete_transient( self::LOCK_KEY );
	}
}
