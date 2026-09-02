<?php
/**
 * Activity log view.
 *
 * @package Update_Zombie
 *
 * @var Update_Zombie_Activity_Table $table Prepared list table.
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap update-zombie-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Activity', 'update-zombie' ); ?></h1>

	<hr class="wp-header-end">

	<?php Update_Zombie_Admin::render_tabs(); ?>

	<p class="description">
		<?php esc_html_e( 'Everything Update Zombie did, including while you were not looking: updates spotted, analyses run, verdicts reached, and anything installed or held back.', 'update-zombie' ); ?>
	</p>

	<?php $table->views(); ?>

	<form method="get">
		<input type="hidden" name="page" value="<?php echo esc_attr( Update_Zombie_Admin::PAGE_REPORTS ); ?>">
		<input type="hidden" name="tab" value="activity">
		<?php
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		if ( isset( $_GET['event'] ) ) {
			printf( '<input type="hidden" name="event" value="%s">', esc_attr( sanitize_key( wp_unslash( $_GET['event'] ) ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$table->search_box( __( 'Search activity', 'update-zombie' ), 'update-zombie-activity-search' );
		$table->display();
		?>
	</form>
</div>
