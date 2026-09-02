<?php
/**
 * Reports list view.
 *
 * @package Update_Zombie
 *
 * @var Update_Zombie_Reports_Table $table Prepared list table.
 */

defined( 'ABSPATH' ) || exit;

$uz_availability = Update_Zombie_Analyzer::availability();
$uz_mode         = Update_Zombie_Enforcer::mode();
$uz_mode_labels  = array(
	Update_Zombie_Settings::MODE_ADVISORY  => __( 'Advisory — reports only, WordPress decides what installs.', 'update-zombie' ),
	Update_Zombie_Settings::MODE_GUARDED   => __( 'Guarded — high-impact security fixes install automatically, bad updates are held back.', 'update-zombie' ),
	Update_Zombie_Settings::MODE_AUTOPILOT => __( 'Autopilot — high-impact security and good updates install automatically, bad ones are held back.', 'update-zombie' ),
);
?>
<div class="wrap update-zombie-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Update Reports', 'update-zombie' ); ?></h1>

	<a href="<?php echo esc_url( Update_Zombie_Admin::action_url( 'scan' ) ); ?>" class="page-title-action">
		<?php esc_html_e( 'Check for updates', 'update-zombie' ); ?>
	</a>
	<a href="<?php echo esc_url( Update_Zombie_Admin::action_url( 'run_queue' ) ); ?>" class="page-title-action">
		<?php esc_html_e( 'Analyse next in queue', 'update-zombie' ); ?>
	</a>

	<hr class="wp-header-end">

	<?php Update_Zombie_Admin::render_tabs(); ?>

	<?php if ( is_wp_error( $uz_availability ) ) : ?>
		<div class="notice notice-error">
			<p><strong><?php esc_html_e( 'Analysis is not running.', 'update-zombie' ); ?></strong></p>
			<p><?php echo esc_html( $uz_availability->get_error_message() ); ?></p>
		</div>
	<?php endif; ?>

	<p class="uz-mode-line">
		<strong><?php esc_html_e( 'Mode:', 'update-zombie' ); ?></strong>
		<?php echo esc_html( $uz_mode_labels[ $uz_mode ] ?? $uz_mode ); ?>
		<a href="<?php echo esc_url( Update_Zombie_Admin::settings_url() ); ?>"><?php esc_html_e( 'Change', 'update-zombie' ); ?></a>
	</p>

	<?php
	$uz_brag_days = 7;
	$uz_auto      = Update_Zombie_Store::auto_applied_since( $uz_brag_days );

	if ( $uz_auto ) :
		$uz_security = count( array_filter( $uz_auto, static function ( $r ) { return ! empty( $r->is_security ); } ) );
		?>
		<details class="uz-brag">
			<summary>
				<span class="uz-brag-tick" aria-hidden="true">&#10003;</span>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of updates, 2: number of security fixes, 3: number of days. */
						_n(
							'The zombie installed %1$s update on its own in the last %3$d days — %2$s of them security fixes.',
							'The zombie installed %1$s updates on its own in the last %3$d days — %2$s of them security fixes.',
							count( $uz_auto ),
							'update-zombie'
						),
						number_format_i18n( count( $uz_auto ) ),
						number_format_i18n( $uz_security ),
						$uz_brag_days
					)
				);
				?>
				<span class="uz-brag-more"><?php esc_html_e( 'Show them', 'update-zombie' ); ?></span>
			</summary>
			<ul>
				<?php foreach ( $uz_auto as $uz_row ) : ?>
					<li>
						<a href="<?php echo esc_url( Update_Zombie_Admin::report_url( $uz_row->id ) ); ?>"><?php echo esc_html( $uz_row->item_name ); ?></a>
						<code><?php echo esc_html( $uz_row->old_version . ' → ' . $uz_row->new_version ); ?></code>
						<?php if ( ! empty( $uz_row->is_security ) ) : ?>
							<span class="uz-badge uz-badge-security"><?php esc_html_e( 'Security fix', 'update-zombie' ); ?></span>
						<?php endif; ?>
						<span class="uz-muted">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: human readable time difference. */
									__( '%s ago', 'update-zombie' ),
									human_time_diff( strtotime( $uz_row->applied_at . ' UTC' ) )
								)
							);
							?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
	<?php endif; ?>

	<?php $table->views(); ?>

	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( Update_Zombie_Admin::PAGE_REPORTS ); ?>">
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		if ( isset( $_GET['view'] ) ) {
			printf( '<input type="hidden" name="view" value="%s">', esc_attr( sanitize_key( wp_unslash( $_GET['view'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$table->search_box( __( 'Search reports', 'update-zombie' ), 'update-zombie-search' );
		$table->display();
		?>
	</form>
</div>
