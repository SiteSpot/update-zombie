<?php
/**
 * Single report view.
 *
 * @package Update_Zombie
 *
 * @var object               $report  Report row.
 * @var array<string, mixed> $payload Decoded analysis payload.
 */

defined( 'ABSPATH' ) || exit;

$uz_stats    = $payload['stats'] ?? array();
$uz_concerns = $payload['concerns'] ?? array();
$uz_findings = $payload['security_findings'] ?? array();
$uz_breaking = $payload['breaking_changes'] ?? array();
$uz_signals  = Update_Zombie_Store::signals( $report );
$uz_engine   = $payload['engine'] ?? 'ai';
?>
<div class="wrap update-zombie-wrap update-zombie-report">
	<h1 class="wp-heading-inline">
		<?php echo esc_html( $report->item_name ); ?>
		<span class="uz-version-pill"><?php echo esc_html( $report->old_version . ' → ' . $report->new_version ); ?></span>
	</h1>

	<a href="<?php echo esc_url( Update_Zombie_Admin::reports_url() ); ?>" class="page-title-action">
		<?php esc_html_e( 'Back to reports', 'update-zombie' ); ?>
	</a>
	<a href="<?php echo esc_url( Update_Zombie_Admin::action_url( 'analyze', $report->id ) ); ?>" class="page-title-action">
		<?php esc_html_e( 'Re-analyse', 'update-zombie' ); ?>
	</a>

	<hr class="wp-header-end">

	<?php
	// While the analysis is still running the computed facts are the whole
	// story, so they lead. Once there is a verdict, that leads instead and the
	// facts follow it (see the complete branch below).
	if ( Update_Zombie_Store::STATUS_COMPLETE !== $report->status ) {
		require UPDATE_ZOMBIE_DIR . 'admin/views/partials/what-changed.php';
	}
	?>

	<?php if ( Update_Zombie_Store::STATUS_ERROR === $report->status ) : ?>
		<div class="notice notice-error">
			<p><strong><?php esc_html_e( 'This analysis failed.', 'update-zombie' ); ?></strong></p>
			<p><?php echo esc_html( (string) $report->error_message ); ?></p>
			<p>
				<a href="<?php echo esc_url( Update_Zombie_Admin::action_url( 'requeue', $report->id ) ); ?>" class="button">
					<?php esc_html_e( 'Put back in the queue', 'update-zombie' ); ?>
				</a>
			</p>
		</div>
	<?php elseif ( Update_Zombie_Store::STATUS_COMPLETE !== $report->status ) : ?>
		<?php
		$uz_phase   = Update_Zombie_Admin::phase_for( $report );
		$uz_started = $report->created_at ? strtotime( $report->created_at . ' UTC' ) : time();
		?>
		<div class="uz-progress" id="uz-progress" data-report="<?php echo (int) $report->id; ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'update_zombie_status' ) ); ?>" data-phase="<?php echo esc_attr( $uz_phase['phase'] ); ?>">
			<div class="uz-progress-head">
				<span class="uz-spinner" aria-hidden="true"></span>
				<div>
					<h2><?php esc_html_e( 'Analysing this update', 'update-zombie' ); ?></h2>
					<p class="uz-progress-label"><?php echo esc_html( $uz_phase['label'] ); ?></p>
				</div>
			</div>
			<p class="uz-progress-eta">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of minutes. */
						__( 'This usually takes two to five minutes, and up to %d for a large update. The work runs in the background — you can leave this page and come back, or just wait; it refreshes itself.', 'update-zombie' ),
						(int) $uz_phase['estimate']
					)
				);
				?>
			</p>
			<p class="uz-progress-steps">
				<span class="uz-step <?php echo in_array( $uz_phase['phase'], array( 'diffing', 'waiting_review', 'reviewing' ), true ) ? 'is-done' : ( 'queued' === $uz_phase['phase'] ? 'is-current' : '' ); ?>"><?php esc_html_e( 'Queued', 'update-zombie' ); ?></span>
				<span class="uz-step <?php echo in_array( $uz_phase['phase'], array( 'waiting_review', 'reviewing' ), true ) ? 'is-done' : ( 'diffing' === $uz_phase['phase'] ? 'is-current' : '' ); ?>"><?php esc_html_e( 'Download & diff', 'update-zombie' ); ?></span>
				<span class="uz-step <?php echo 'reviewing' === $uz_phase['phase'] ? 'is-current' : ''; ?>"><?php esc_html_e( 'AI review', 'update-zombie' ); ?></span>
				<span class="uz-step"><?php esc_html_e( 'Verdict', 'update-zombie' ); ?></span>
			</p>
			<?php if ( empty( $uz_signals ) ) : ?>
				<p class="uz-muted"><?php esc_html_e( 'The "What changed" facts appear here as soon as the diff is done, before the AI has said anything.', 'update-zombie' ); ?></p>
			<?php endif; ?>
		</div>
		<script>
		( function () {
			var box = document.getElementById( 'uz-progress' );
			if ( ! box || ! window.fetch ) { return; }
			var label = box.querySelector( '.uz-progress-label' );
			var lastPhase = box.getAttribute( 'data-phase' );
			function poll() {
				var body = new URLSearchParams( { action: 'update_zombie_status', nonce: box.getAttribute( 'data-nonce' ), report: box.getAttribute( 'data-report' ) } );
				fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( r ) { return r.json(); } )
					.then( function ( j ) {
						if ( ! j || ! j.success ) { return; }
						if ( j.data.done ) { window.location.reload(); return; }
						if ( j.data.phase !== lastPhase ) { window.location.reload(); return; }
						if ( label ) { label.textContent = j.data.label; }
					} )
					.catch( function () {} );
			}
			setInterval( poll, 10000 );
		} )();
		</script>
	<?php else : ?>

		<div class="uz-verdict-card uz-verdict-<?php echo esc_attr( $report->verdict ); ?>">
			<div class="uz-verdict-headline">
				<span class="uz-badge uz-badge-<?php echo esc_attr( $report->verdict ); ?>">
					<?php echo esc_html( Update_Zombie_Admin::verdict_label( $report->verdict ) ); ?>
				</span>
				<?php
				$uz_state = Update_Zombie_Reports_Table::installed_state( $report );

				if ( $uz_state['updated'] ) :
					$uz_auto = in_array( $report->decision, array( Update_Zombie_Store::DECISION_AUTO, Update_Zombie_Store::DECISION_SCHEDULED ), true );
					?>
					<span class="uz-badge uz-badge-applied">&#10003; <?php echo esc_html( $uz_auto ? __( 'Automatically updated', 'update-zombie' ) : __( 'Updated', 'update-zombie' ) ); ?></span>
				<?php endif; ?>
				<h2><?php echo esc_html( $report->headline ); ?></h2>
			</div>

			<dl class="uz-facts">
				<div>
					<dt><?php esc_html_e( 'Security fix', 'update-zombie' ); ?></dt>
					<dd>
						<?php if ( ! empty( $report->is_security ) ) : ?>
							<?php
							echo esc_html(
								sprintf(
									/* translators: %d: confidence percentage. */
									__( 'Yes, %d%% confidence', 'update-zombie' ),
									(int) $report->confidence
								)
							);
							?>
						<?php else : ?>
							<?php esc_html_e( 'No', 'update-zombie' ); ?>
						<?php endif; ?>
					</dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Recommendation', 'update-zombie' ); ?></dt>
					<dd><?php echo esc_html( Update_Zombie_Admin::recommendation_label( $report->recommendation ) ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Action taken', 'update-zombie' ); ?></dt>
					<dd>
						<?php
						$uz_state = Update_Zombie_Reports_Table::installed_state( $report );

						if ( $uz_state['updated'] ) {
							$uz_auto = in_array( $report->decision, array( Update_Zombie_Store::DECISION_AUTO, Update_Zombie_Store::DECISION_SCHEDULED ), true );
							echo '<span class="uz-fact-ok">&#10003; ' . esc_html( $uz_auto ? __( 'Automatically updated', 'update-zombie' ) : __( 'Updated', 'update-zombie' ) ) . '</span>';
						} else {
							echo esc_html( Update_Zombie_Admin::decision_label( $report->decision ) );
							echo ' ' . Update_Zombie_Reports_Table::update_link( $report ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside the helper.
						}
						?>
					</dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Release notes back this up', 'update-zombie' ); ?></dt>
					<dd>
						<?php
						echo esc_html(
							! empty( $payload['changelog_corroborates'] )
								? __( 'Yes — security language found in the changelog', 'update-zombie' )
								: __( 'No security language in the changelog', 'update-zombie' )
						);
						?>
					</dd>
				</div>
			</dl>

			<p class="uz-summary"><?php echo esc_html( $report->summary ); ?></p>
		</div>

		<?php require UPDATE_ZOMBIE_DIR . 'admin/views/partials/what-changed.php'; ?>

		<?php if ( ( ! empty( $report->is_security ) || 'security' === $report->verdict ) && isset( $payload['security_substantiated'] ) && ! $payload['security_substantiated'] ) : ?>
			<div class="notice notice-warning inline">
				<p><strong><?php esc_html_e( 'Security claim not substantiated.', 'update-zombie' ); ?></strong></p>
				<p><?php esc_html_e( 'The analysis called this a security fix but did not name a single file where the fix appears. Update Zombie never installs an update automatically on an uncited claim, so this one is waiting for you even in Guarded or Autopilot mode. Re-analysing often produces a properly cited answer.', 'update-zombie' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $uz_findings ) : ?>
			<h2><?php esc_html_e( 'Security findings', 'update-zombie' ); ?></h2>
			<?php foreach ( $uz_findings as $uz_finding ) : ?>
				<div class="uz-finding uz-finding-security">
					<h3>
						<span class="uz-severity"><?php echo esc_html( strtoupper( (string) ( $uz_finding['severity'] ?? 'unknown' ) ) ); ?></span>
						<?php echo esc_html( $uz_finding['title'] ); ?>
					</h3>
					<p class="uz-file">
						<code><?php echo esc_html( $uz_finding['file'] ); ?></code>
						<?php if ( ! empty( $uz_finding['identifier'] ) ) : ?>
							<span class="uz-cve"><?php echo esc_html( $uz_finding['identifier'] ); ?></span>
						<?php endif; ?>
					</p>
					<p><?php echo esc_html( $uz_finding['detail'] ); ?></p>
					<?php if ( ! empty( $uz_finding['excerpt'] ) ) : ?>
						<pre class="uz-excerpt"><?php echo esc_html( $uz_finding['excerpt'] ); ?></pre>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>

		<?php if ( $uz_concerns ) : ?>
			<h2><?php esc_html_e( 'Concerns', 'update-zombie' ); ?></h2>
			<?php foreach ( $uz_concerns as $uz_concern ) : ?>
				<div class="uz-finding uz-severity-<?php echo esc_attr( $uz_concern['severity'] ); ?>">
					<h3>
						<span class="uz-severity"><?php echo esc_html( strtoupper( $uz_concern['severity'] ) ); ?></span>
						<?php echo esc_html( $uz_concern['title'] ); ?>
					</h3>
					<p class="uz-file"><code><?php echo esc_html( $uz_concern['file'] ); ?></code></p>
					<p><?php echo esc_html( $uz_concern['detail'] ); ?></p>
					<?php if ( ! empty( $uz_concern['excerpt'] ) ) : ?>
						<pre class="uz-excerpt"><?php echo esc_html( $uz_concern['excerpt'] ); ?></pre>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>

		<?php if ( $uz_breaking ) : ?>
			<h2><?php esc_html_e( 'Possible breaking changes', 'update-zombie' ); ?></h2>
			<ul class="uz-list">
				<?php foreach ( $uz_breaking as $uz_change ) : ?>
					<li><?php echo esc_html( $uz_change ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<h2><?php esc_html_e( 'What was reviewed', 'update-zombie' ); ?></h2>
		<p class="uz-muted">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: files changed, 2: files included, 3: files omitted, 4: files skipped. */
					__( '%1$d files changed. %2$d were sent for review, %3$d were left out to stay inside the budget, and %4$d were skipped as binary or oversized.', 'update-zombie' ),
					(int) ( $uz_stats['files_changed'] ?? 0 ),
					(int) ( $uz_stats['files_included'] ?? 0 ),
					(int) ( $uz_stats['files_omitted'] ?? 0 ),
					(int) ( $uz_stats['skipped'] ?? 0 )
				)
			);
			?>
		</p>

		<?php if ( ! empty( $payload['files'] ) ) : ?>
			<table class="widefat striped uz-files">
				<thead>
					<tr>
						<th><?php esc_html_e( 'File', 'update-zombie' ); ?></th>
						<th><?php esc_html_e( 'Change', 'update-zombie' ); ?></th>
						<th><?php esc_html_e( 'Lines', 'update-zombie' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $payload['files'] as $uz_file ) : ?>
						<tr>
							<td><code><?php echo esc_html( $uz_file['path'] ); ?></code></td>
							<td><?php echo esc_html( $uz_file['change'] ); ?></td>
							<td>
								<span class="uz-adds">+<?php echo (int) $uz_file['adds']; ?></span>
								<span class="uz-dels">−<?php echo (int) $uz_file['dels']; ?></span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( ! empty( $payload['omitted'] ) ) : ?>
			<h3><?php esc_html_e( 'Not reviewed', 'update-zombie' ); ?></h3>
			<p class="uz-muted uz-omitted"><?php echo esc_html( implode( ', ', $payload['omitted'] ) ); ?></p>
		<?php endif; ?>

		<?php if ( ! empty( $payload['changelog_notes'] ) ) : ?>
			<h2><?php esc_html_e( 'Release notes from the package', 'update-zombie' ); ?></h2>
			<pre class="uz-changelog"><?php echo esc_html( $payload['changelog_notes'] ); ?></pre>
		<?php endif; ?>

		<p class="uz-disclaimer">
			<?php if ( 'signals' === $uz_engine ) : ?>
				<?php esc_html_e( 'This verdict was produced without AI, by corroborating the changelog against a pattern scan of the diff. It can tell you that security checks were added; it cannot tell you whether they are correct or complete. The "What changed" section above is exact either way.', 'update-zombie' ); ?>
			<?php else : ?>
				<?php esc_html_e( 'This verdict came from an AI reading a code diff. It can be wrong, and it only saw the files listed above. Treat it as a second opinion, not a guarantee. The "What changed" section above is computed, not generated, and is exact.', 'update-zombie' ); ?>
			<?php endif; ?>
		</p>

	<?php endif; ?>
</div>
