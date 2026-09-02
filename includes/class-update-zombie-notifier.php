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
 * Email is HTML, kept plain: one headline, a small fact table, the summary,
 * the findings as short lists, and a button to the report. It should read
 * fine in any client and survive being forwarded.
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

		Update_Zombie_Log::record(
			Update_Zombie_Log::NOTIFIED,
			sprintf(
				/* translators: %s: comma-separated reasons. */
				__( 'Notification sent (%s).', 'update-zombie' ),
				implode( ', ', $reasons )
			),
			$report
		);
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
			$body = $this->wrap(
				__( 'Analysis failed', 'update-zombie' ),
				'<h2 style="margin:0 0 14px;font-size:20px;line-height:1.3;color:#1d2327;">'
				. esc_html(
					sprintf(
						/* translators: 1: item name, 2: version. */
						__( 'Could not analyse %1$s %2$s', 'update-zombie' ),
						$report->item_name,
						$report->new_version
					)
				)
				. '</h2>'
				. '<p style="margin:0 0 12px;font-size:14px;line-height:1.6;color:#1d2327;">' . esc_html__( 'Update Zombie tried three times and gave up. The update itself is untouched; WordPress will still offer it as usual.', 'update-zombie' ) . '</p>'
				. '<p style="background:#fcf0f1;border-left:4px solid #d63638;padding:10px 12px;margin:14px 0;font-size:13px;color:#8a1f22;">' . esc_html( $message ) . '</p>'
				. $this->button( Update_Zombie_Admin::report_url( $report->id ), __( 'Open the report', 'update-zombie' ) )
			);

			$this->mail(
				sprintf(
					/* translators: 1: site name, 2: item name. */
					__( '[%1$s] Update Zombie could not analyse %2$s', 'update-zombie' ),
					$this->site_name(),
					$report->item_name
				),
				$body
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

		$has_concerns = ! empty( $verdict['concerns'] ) || in_array( $report->verdict, array( 'bad', 'questionable' ), true );

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
	 * Builds and sends the verdict email.
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
			/* translators: 1: site name, 2: what happened, 3: item name, 4: version. */
			__( '[%1$s] %2$s: %3$s %4$s', 'update-zombie' ),
			$this->site_name(),
			$this->subject_tag( $report, $reasons ),
			$report->item_name,
			$report->new_version
		);

		$security = ! empty( $report->is_security )
			? sprintf(
				/* translators: %d: confidence percentage. */
				__( 'Yes — %d%% confidence', 'update-zombie' ),
				(int) $report->confidence
			)
			: __( 'No', 'update-zombie' );

		$facts = array(
			__( 'Update', 'update-zombie' )         => esc_html( $report->item_name ) . ' &nbsp;<code style="font-size:12px;">' . esc_html( $report->old_version ) . '</code> &rarr; <code style="font-size:12px;">' . esc_html( $report->new_version ) . '</code>',
			__( 'Verdict', 'update-zombie' )        => $this->verdict_pill( $report->verdict ),
			__( 'Security fix', 'update-zombie' )   => esc_html( $security ),
			__( 'Recommendation', 'update-zombie' ) => esc_html( Update_Zombie_Admin::recommendation_label( $report->recommendation ) ),
			__( 'What happened', 'update-zombie' )  => esc_html( Update_Zombie_Admin::decision_label( $report->decision ) ),
		);

		$html  = '<h2 style="margin:0 0 14px;font-size:20px;line-height:1.3;color:#1d2327;">' . esc_html( $report->headline ) . '</h2>';
		$html .= '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;margin:0 0 16px;">';

		foreach ( $facts as $label => $value ) {
			$html .= '<tr>'
				. '<td style="padding:6px 10px 6px 0;color:#646970;font-size:12px;text-transform:uppercase;letter-spacing:.02em;white-space:nowrap;vertical-align:top;width:120px;">' . esc_html( $label ) . '</td>'
				. '<td style="padding:6px 0;font-size:14px;color:#1d2327;vertical-align:top;">' . $value . '</td>'
				. '</tr>';
		}

		$html .= '</table>';
		$html .= '<p style="margin:0 0 18px;font-size:14px;line-height:1.6;color:#1d2327;">' . esc_html( $report->summary ) . '</p>';

		$html .= $this->list_section( __( 'Security findings', 'update-zombie' ), (array) ( $verdict['security_findings'] ?? array() ), '#d63638' );
		$html .= $this->list_section( __( 'Concerns', 'update-zombie' ), (array) ( $verdict['concerns'] ?? array() ), '#dba617' );

		if ( ! empty( $verdict['breaking_changes'] ) ) {
			$html .= '<h3 style="margin:18px 0 6px;font-size:14px;color:#1d2327;">' . esc_html__( 'Possible breaking changes', 'update-zombie' ) . '</h3><ul style="margin:0 0 0 18px;padding:0;font-size:13px;line-height:1.5;color:#3c434a;">';

			foreach ( array_slice( (array) $verdict['breaking_changes'], 0, 6 ) as $change ) {
				$html .= '<li style="margin:0 0 4px;">' . esc_html( $change ) . '</li>';
			}

			$html .= '</ul>';
		}

		$html .= $this->button( Update_Zombie_Admin::report_url( $report->id ), __( 'Open the full report', 'update-zombie' ) );

		$this->mail( $subject, $this->wrap( $this->subject_tag( $report, $reasons ), $html ) );
	}

	/**
	 * Renders a findings or concerns list.
	 *
	 * @since 0.6.0
	 *
	 * @param string                           $title  Section heading.
	 * @param array<int, array<string, mixed>> $items  Findings or concerns.
	 * @param string                           $colour Accent colour.
	 * @return string
	 */
	protected function list_section( $title, array $items, $colour ) {
		if ( ! $items ) {
			return '';
		}

		$html = '<h3 style="margin:18px 0 8px;font-size:14px;color:#1d2327;">' . esc_html( $title ) . '</h3>';

		foreach ( array_slice( $items, 0, 6 ) as $item ) {
			$meta = array();

			if ( ! empty( $item['severity'] ) ) {
				$meta[] = strtoupper( (string) $item['severity'] );
			}

			if ( ! empty( $item['file'] ) ) {
				$meta[] = (string) $item['file'];
			}

			$html .= '<div style="border-left:3px solid ' . esc_attr( $colour ) . ';padding:6px 12px;margin:0 0 10px;">'
				. '<div style="font-size:14px;font-weight:600;color:#1d2327;">' . esc_html( (string) ( $item['title'] ?? '' ) ) . '</div>'
				. ( $meta ? '<div style="font-size:12px;color:#646970;font-family:monospace;margin:2px 0 4px;">' . esc_html( implode( ' · ', $meta ) ) . '</div>' : '' )
				. '<div style="font-size:13px;line-height:1.5;color:#3c434a;">' . esc_html( (string) ( $item['detail'] ?? '' ) ) . '</div>'
				. '</div>';
		}

		if ( count( $items ) > 6 ) {
			$html .= '<p style="font-size:12px;color:#646970;margin:0;">' . esc_html(
				sprintf(
					/* translators: %d: number of further items. */
					__( '…and %d more in the full report.', 'update-zombie' ),
					count( $items ) - 6
				)
			) . '</p>';
		}

		return $html;
	}

	/**
	 * Renders a verdict as a coloured pill.
	 *
	 * @since 0.6.0
	 *
	 * @param string $verdict Verdict key.
	 * @return string
	 */
	protected function verdict_pill( $verdict ) {
		$colours = array(
			'security'     => array( '#fcf0f1', '#8a1f22' ),
			'good'         => array( '#edfaef', '#005a1f' ),
			'neutral'      => array( '#f6f7f7', '#3c434a' ),
			'questionable' => array( '#fcf9e8', '#7a5c00' ),
			'bad'          => array( '#3c434a', '#ffffff' ),
		);

		list( $bg, $fg ) = $colours[ $verdict ] ?? $colours['neutral'];

		return sprintf(
			'<span style="display:inline-block;padding:2px 9px;border-radius:3px;font-size:12px;font-weight:600;background:%s;color:%s;">%s</span>',
			esc_attr( $bg ),
			esc_attr( $fg ),
			esc_html( Update_Zombie_Admin::verdict_label( $verdict ) )
		);
	}

	/**
	 * Renders a button link.
	 *
	 * @since 0.6.0
	 *
	 * @param string $url   Destination.
	 * @param string $label Button text.
	 * @return string
	 */
	protected function button( $url, $label ) {
		return '<p style="margin:22px 0 0;"><a href="' . esc_url( $url ) . '" style="display:inline-block;background:#2271b1;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:4px;font-size:14px;font-weight:600;">' . esc_html( $label ) . '</a></p>';
	}

	/**
	 * Wraps content in the email shell.
	 *
	 * @since 0.6.0
	 *
	 * @param string $kicker Small line above the content.
	 * @param string $body   Inner HTML, already escaped.
	 * @return string
	 */
	protected function wrap( $kicker, $body ) {
		$footer = esc_html__( 'This verdict came from an AI reading a code diff. It can be wrong. Treat it as a second opinion, not a guarantee.', 'update-zombie' )
			. ' <a href="' . esc_url( Update_Zombie_Admin::settings_url() ) . '" style="color:#646970;">' . esc_html__( 'Notification settings', 'update-zombie' ) . '</a>';

		return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>'
			. '<body style="margin:0;padding:0;background:#f0f0f1;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;">'
			. '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#f0f0f1;padding:24px 12px;"><tr><td align="center">'
			. '<table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;width:100%;background:#ffffff;border:1px solid #dcdcde;border-radius:6px;">'
			. '<tr><td style="padding:18px 28px 0;font-size:12px;letter-spacing:.04em;text-transform:uppercase;color:#646970;">&#129503; ' . esc_html__( 'Update Zombie', 'update-zombie' ) . ' &middot; ' . esc_html( $kicker ) . ' &middot; ' . esc_html( $this->site_name() ) . '</td></tr>'
			. '<tr><td style="padding:12px 28px 26px;">' . $body . '</td></tr>'
			. '<tr><td style="padding:14px 28px;border-top:1px solid #f0f0f1;font-size:12px;line-height:1.5;color:#646970;">' . $footer . '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}

	/**
	 * Sends an HTML email to the configured address.
	 *
	 * @since 0.6.0
	 *
	 * @param string $subject Subject line.
	 * @param string $html    Full HTML document.
	 * @return void
	 */
	protected function mail( $subject, $html ) {
		$content_type = static function () {
			return 'text/html';
		};

		add_filter( 'wp_mail_content_type', $content_type );

		wp_mail( Update_Zombie_Settings::notification_address(), $subject, $html );

		remove_filter( 'wp_mail_content_type', $content_type );
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
	 * Returns a short description of what happened, for subjects and kickers.
	 *
	 * @since 0.1.0
	 *
	 * @param object   $report  Report row.
	 * @param string[] $reasons Notification reasons.
	 * @return string
	 */
	protected function subject_tag( $report, array $reasons ) {
		if ( in_array( 'auto_update', $reasons, true ) ) {
			return __( 'Security update installing', 'update-zombie' );
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
