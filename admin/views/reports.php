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
	Update_Zombie_Settings::MODE_GUARDED   => __( 'Guarded — security fixes install automatically, bad updates are held back.', 'update-zombie' ),
	Update_Zombie_Settings::MODE_AUTOPILOT => __( 'Autopilot — security and good updates install automatically, bad ones are held back.', 'update-zombie' ),
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

	<?php $table->views(); ?>

	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( Update_Zombie_Admin::PAGE_REPORTS ); ?>">
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		if ( isset( $_GET['status'] ) ) {
			printf( '<input type="hidden" name="status" value="%s">', esc_attr( sanitize_key( wp_unslash( $_GET['status'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$table->search_box( __( 'Search reports', 'update-zombie' ), 'update-zombie-search' );
		$table->display();
		?>
	</form>
</div>
