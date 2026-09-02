<?php
/**
 * "What changed" panel: the computed signals for a report.
 *
 * @package Update_Zombie
 *
 * @var array<string, mixed> $uz_signals Stored signals.
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $uz_signals ) ) {
	return;
}
?>
<div class="uz-signal-bar">
	<h2><?php esc_html_e( 'What changed', 'update-zombie' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Computed directly from the diff. These are facts about the files, not the AI\'s opinion, and they are the same every run.', 'update-zombie' ); ?></p>
	<?php
	echo Update_Zombie_Chips::render( $uz_signals, 20 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside the renderer.
	echo Update_Zombie_Chips::render_breakdown( $uz_signals ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped inside the renderer.
	?>
</div>
