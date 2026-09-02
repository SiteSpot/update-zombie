<?php
/**
 * "What changed" panel: the computed signals for a report.
 *
 * @package Update_Zombie
 *
 * @var array<string, mixed> $update_zombie_signals Stored signals.
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $update_zombie_signals ) ) {
	return;
}
?>
<div class="uz-signal-bar">
	<h2><?php esc_html_e( 'What changed', 'update-zombie' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Computed directly from the diff. These are facts about the files, not the AI\'s opinion, and they are the same every run.', 'update-zombie' ); ?></p>
	<?php
	echo Update_Zombie_Chips::render( $update_zombie_signals, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside the renderer.
	echo Update_Zombie_Chips::render_breakdown( $update_zombie_signals ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside the renderer.
	?>
</div>
