<?php
/**
 * Verdict notifications.
 *
 * @package Update_Zombie
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends verdicts out by email and webhook.
 *
 * @since 0.1.0
 */
class Update_Zombie_Notifier {

	/**
	 * Notifies about a finished report, if it meets the configured triggers.
	 *
	 * @since 0.1.0
	 *
	 * @param object               $report  Report row.
	 * @param array<string, mixed> $verdict Normalised verdict payload.
	 * @return void
	 */
	public function notify( $report, array $verdict ) {
		$reasons = $this->reasons( $report, $verdict );

		if ( ! $reasons ) {
			return;
		}

		if ( Update_Zombie_Settings::get( 'notify_email' ) ) {
			$this->send_email( $report, $verdict, $reasons );
		}

		if ( Update_Zombie_Settings::get( 'webhook_enabled' ) ) {
			$this->send_webhook( $report, $verdict, $reasons );
		}

		Update_Zombie_Store::update( $report->id, array( 'notified_at' => current_time( 'mysql', true ) ) );
	}

	/**
	 * Notifies about an analysis failure.
	 *
	 * @since 0.1.0
	 *
	 * @param object $report  Report row.
	 * @param string $message Error message.
	 * @return void
	 */
	public function notify_error( $report, $message ) {
		if ( ! Update_Zombie_Settings::get( 'notify_on_error' ) ) {
			return;
		}

		if ( Update_Zombie_Settings::get( 'notify_email' ) ) {
			wp_mail(
				Update_Zombie_Settings::notification_address(),
				sprintf(
					/* translators: 1: site name, 2: item name. */
					__( '[%1$s] Update Zombie could not analyse %2$s', 'update-zombie' ),
					$this->site_name(),
					$report->item_name
				),
				sprintf(
					/* translators: 1: item name, 2: version, 3: error message, 4: reports URL. */
					__( "Update Zombie tried to analyse the update for %1\$s to version %2\$s and failed.\n\nReason: %3\$s\n\nThe update itself is untouched. You can retry from the reports screen:\n%4\$s\n", 'update-zombie' ),
					$report->item_name,
					$report->new_version,
					$message,
					Update_Zombie_Admin::reports_url()
				)
			);
		}

		if ( Update_Zombie_Settings::get( 'webhook_enabled' ) ) {
			$this->post_webhook(
				array(
					'event'   => 'analysis_failed',
					'item'    => $this->item_payload( $report ),
					'error'   => $message,
					'site'    => home_url(),
					'sent_at' => gmdate( 'c' ),
				)
			);
		}
	}

	/**
	 * Works out why this report is worth telling someone about.
	 *
	 * @since 0.1.0
	 *
	 * @param object               $report  Report row.
	 * @param array<string, mixed> $verdict Verdict payload.
	 * @return string[]
	 */
	protected function reasons( $report, array $verdict ) {
		$reasons = array();

		if ( ! empty( $report->is_security ) && Update_Zombie_Settings::get( 'notify_on_security' ) ) {
			$reasons[] = 'security';
		}

		$has_concerns = ! empty( $verdict['concerns'] ) || in_array( $report->verdict, array( 'shit', 'questionable' ), true );

		if ( $has_concerns && Update_Zombie_Settings::get( 'notify_on_concerns' ) ) {
			$reasons[] = 'concerns';
		}

		if ( Update_Zombie_Store::DECISION_HELD === $report->decision && Update_Zombie_Settings::get( 'notify_on_held' ) ) {
			$reasons[] = 'held';
		}

		if ( Update_Zombie_Store::DECISION_SCHEDULED === $report->decision && Update_Zombie_Settings::get( 'notify_on_security' ) ) {
			$reasons[] = 'auto_update';
		}

		return array_values( array_unique( $reasons ) );
	}

	/**
	 * Sends the notification email.
	 *
	 * @since 0.1.0
	 *
	 * @param object               $report  Report row.
	 * @param array<string, mixed> $verdict Verdict payload.
	 * @param string[]             $reasons Notification reasons.
	 * @return void
	 */
	protected function send_email( $report, array $verdict, array $reasons ) {
		$subject = sprintf(
			/* translators: 1: site name, 2: verdict label, 3: item name, 4: version. */
			__( '[%1$s] %2$s: %3$s %4$s', 'update-zombie' ),
			$this->site_name(),
			$this->subject_tag( $report, $reasons ),
			$report->item_name,
			$report->new_version
		);

		$lines = array();

		$lines[] = $report->headline;
		$lines[] = '';
		$lines[] = sprintf(
			/* translators: 1: item name, 2: old version, 3: new version. */
			__( 'Item: %1$s (%2$s → %3$s)', 'update-zombie' ),
			$report->item_name,
			$report->old_version,
			$report->new_version
		);
		$lines[] = sprintf(
			/* translators: 1: verdict, 2: recommendation. */
			__( 'Verdict: %1$s. Recommendation: %2$s.', 'update-zombie' ),
			Update_Zombie_Admin::verdict_label( $report->verdict ),
			Update_Zombie_Admin::recommendation_label( $report->recommendation )
		);

		if ( ! empty( $report->is_security ) ) {
			$lines[] = sprintf(
				/* translators: %d: confidence percentage. */
				__( 'Assessed as a security fix, %d%% confidence.', 'update-zombie' ),
				(int) $report->confidence
			);
		}

		$lines[] = sprintf(
			/* translators: %s: description of what the plugin did. */
			__( 'Action taken: %s', 'update-zombie' ),
			Update_Zombie_Admin::decision_label( $report->decision )
		);

		$lines[] = '';
		$lines[] = $report->summary;

		if ( ! empty( $verdict['security_findings'] ) ) {
			$lines[] = '';
			$lines[] = __( 'Security findings', 'update-zombie' );
			$lines[] = str_repeat( '-', 40 );

			foreach ( $verdict['security_findings'] as $finding ) {
				$lines[] = sprintf( '* [%s] %s (%s)', strtoupper( (string) ( $finding['severity'] ?? 'unknown' ) ), $finding['title'], $finding['file'] );
				$lines[] = '  ' . $finding['detail'];
			}
		}

		if ( ! empty( $verdict['concerns'] ) ) {
			$lines[] = '';
			$lines[] = __( 'Concerns', 'update-zombie' );
			$lines[] = str_repeat( '-', 40 );

			foreach ( $verdict['concerns'] as $concern ) {
				$lines[] = sprintf( '* [%s] %s (%s)', strtoupper( $concern['severity'] ), $concern['title'], $concern['file'] );
				$lines[] = '  ' . $concern['detail'];
			}
		}

		if ( ! empty( $verdict['breaking_changes'] ) ) {
			$lines[] = '';
			$lines[] = __( 'Possible breaking changes', 'update-zombie' );
			$lines[] = str_repeat( '-', 40 );

			foreach ( $verdict['breaking_changes'] as $change ) {
				$lines[] = '* ' . $change;
			}
		}

		$lines[] = '';
		$lines[] = __( 'Full report:', 'update-zombie' );
		$lines[] = Update_Zombie_Admin::report_url( $report->id );
		$lines[] = '';
		$lines[] = Update_Zombie_Settings::ENGINE_SIGNALS === ( $verdict['engine'] ?? 'ai' )
			? __( 'This verdict came from deterministic changelog and diff signals. It cannot grade impact or verify that a fix is complete.', 'update-zombie' )
			: __( 'This verdict came from an AI reading a code diff. It can be wrong. Treat it as a second opinion, not a guarantee.', 'update-zombie' );

		wp_mail( Update_Zombie_Settings::notification_address(), $subject, implode( "\n", $lines ) );
	}

	/**
	 * Builds and posts the webhook payload.
	 *
	 * @since 0.1.0
	 *
	 * @param object               $report  Report row.
	 * @param array<string, mixed> $verdict Verdict payload.
	 * @param string[]             $reasons Notification reasons.
	 * @return void
	 */
	protected function send_webhook( $report, array $verdict, array $reasons ) {
		$this->post_webhook(
			array(
				'event'          => 'verdict',
				'reasons'        => $reasons,
				'site'           => home_url(),
				'item'           => $this->item_payload( $report ),
				'verdict'        => $report->verdict,
				'recommendation' => $report->recommendation,
				'is_security'    => (bool) $report->is_security,
				'confidence'     => (int) $report->confidence,
				'decision'       => $report->decision,
				'headline'       => $report->headline,
				'summary'        => $report->summary,
				'concerns'       => $verdict['concerns'] ?? array(),
				'findings'       => $verdict['security_findings'] ?? array(),
				'report_url'     => Update_Zombie_Admin::report_url( $report->id ),
				'sent_at'        => gmdate( 'c' ),
			)
		);
	}

	/**
	 * POSTs a payload to the configured webhook.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $payload Payload to send.
	 * @return void
	 */
	protected function post_webhook( array $payload ) {
		$url = (string) Update_Zombie_Settings::get( 'webhook_url', '' );

		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return;
		}

		$body = wp_json_encode( $payload );

		if ( false === $body ) {
			return;
		}

		$headers = array( 'Content-Type' => 'application/json' );
		$secret  = (string) Update_Zombie_Settings::get( 'webhook_secret', '' );

		if ( '' !== $secret ) {
			$headers['X-Update-Zombie-Signature'] = 'sha256=' . hash_hmac( 'sha256', $body, $secret );
		}

		wp_remote_post(
			$url,
			array(
				'timeout'  => 15,
				'headers'  => $headers,
				'body'     => $body,
				'blocking' => false,
			)
		);
	}

	/**
	 * Returns the item identity portion of a webhook payload.
	 *
	 * @since 0.1.0
	 *
	 * @param object $report Report row.
	 * @return array<string, string>
	 */
	protected function item_payload( $report ) {
		return array(
			'type'        => $report->item_type,
			'slug'        => $report->item_slug,
			'name'        => $report->item_name,
			'old_version' => $report->old_version,
			'new_version' => $report->new_version,
		);
	}

	/**
	 * Returns a short tag for the email subject.
	 *
	 * @since 0.1.0
	 *
	 * @param object   $report  Report row.
	 * @param string[] $reasons Notification reasons.
	 * @return string
	 */
	protected function subject_tag( $report, array $reasons ) {
		if ( in_array( 'auto_update', $reasons, true ) ) {
			return __( 'Security update queued', 'update-zombie' );
		}

		if ( in_array( 'security', $reasons, true ) ) {
			return __( 'Security update available', 'update-zombie' );
		}

		if ( Update_Zombie_Store::DECISION_HELD === $report->decision ) {
			return __( 'Update held back', 'update-zombie' );
		}

		return __( 'Update flagged', 'update-zombie' );
	}

	/**
	 * Returns the site name for message subjects.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	protected function site_name() {
		return wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
	}
}
