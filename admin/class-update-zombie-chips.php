<?php
/**
 * Signal chip rendering.
 *
 * @package Update_Zombie
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the computed change signals as compact chips.
 *
 * @since 0.3.0
 */
class Update_Zombie_Chips {

	/**
	 * Renders the chip row for a report.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, mixed> $signals Stored signals.
	 * @param int                  $limit   Maximum signal chips to show.
	 * @return string
	 */
	public static function render( array $signals, $limit = 6 ) {
		if ( ! $signals ) {
			return '';
		}

		$chips = array();

		$lines = (int) ( $signals['lines_changed'] ?? 0 );

		if ( $lines ) {
			$chips[] = self::chip(
				sprintf(
					/* translators: %s: number of lines. */
					_n( '%s line changed', '%s lines changed', $lines, 'update-zombie' ),
					number_format_i18n( $lines )
				),
				'count',
				sprintf(
					/* translators: 1: lines added, 2: lines removed. */
					__( '%1$s added, %2$s removed', 'update-zombie' ),
					number_format_i18n( (int) ( $signals['lines_added'] ?? 0 ) ),
					number_format_i18n( (int) ( $signals['lines_removed'] ?? 0 ) )
				)
			);
		}

		$found = $signals['signals'] ?? array();
		$keys  = Update_Zombie_Signals::sort_keys( $found );
		$shown = 0;

		foreach ( $keys as $key ) {
			if ( $shown >= $limit ) {
				break;
			}

			$entry = $found[ $key ];

			$chips[] = self::chip(
				Update_Zombie_Signals::label( $key ),
				Update_Zombie_Signals::tone( $key ),
				sprintf(
					/* translators: 1: number of files, 2: file list. */
					__( '%1$s file(s): %2$s', 'update-zombie' ),
					number_format_i18n( (int) $entry['count'] ),
					implode( ', ', array_slice( $entry['files'], 0, 5 ) )
				)
			);

			++$shown;
		}

		$remaining = count( $keys ) - $shown;

		if ( $remaining > 0 ) {
			$chips[] = self::chip(
				sprintf(
					/* translators: %s: number of further change types. */
					__( '+%s more', 'update-zombie' ),
					number_format_i18n( $remaining )
				),
				'count',
				implode( ', ', array_map( array( 'Update_Zombie_Signals', 'label' ), array_slice( $keys, $shown ) ) )
			);
		}

		foreach ( (array) ( $signals['filtered'] ?? array() ) as $category => $count ) {
			$chips[] = self::chip(
				sprintf(
					/* translators: 1: category, 2: number of files. */
					__( '%1$s (%2$s)', 'update-zombie' ),
					Update_Zombie_Signals::filtered_label( $category ),
					number_format_i18n( (int) $count )
				),
				'filtered',
				__( 'Changed, but not read: this kind of file is filtered out before review.', 'update-zombie' )
			);
		}

		if ( ! $chips ) {
			return '';
		}

		return '<span class="uz-chips">' . implode( '', $chips ) . '</span>';
	}

	/**
	 * Renders one chip.
	 *
	 * @since 0.3.0
	 *
	 * @param string $label Chip text.
	 * @param string $tone  Tone class suffix.
	 * @param string $title Tooltip text.
	 * @return string
	 */
	protected static function chip( $label, $tone, $title = '' ) {
		return sprintf(
			'<span class="uz-chip uz-chip-%1$s"%2$s>%3$s</span>',
			esc_attr( $tone ),
			$title ? ' title="' . esc_attr( $title ) . '"' : '',
			esc_html( $label )
		);
	}

	/**
	 * Renders the full grouped breakdown for the detail screen.
	 *
	 * @since 0.3.0
	 *
	 * @param array<string, mixed> $signals Stored signals.
	 * @return string
	 */
	public static function render_breakdown( array $signals ) {
		$found = $signals['signals'] ?? array();

		if ( ! $found && empty( $signals['filtered'] ) ) {
			return '';
		}

		$by_group = array();

		foreach ( Update_Zombie_Signals::sort_keys( $found ) as $key ) {
			$by_group[ Update_Zombie_Signals::group( $key ) ][] = $key;
		}

		$html = '<div class="uz-breakdown">';

		foreach ( Update_Zombie_Signals::groups() as $group => $group_label ) {
			if ( empty( $by_group[ $group ] ) ) {
				continue;
			}

			$html .= '<div class="uz-breakdown-group"><h3>' . esc_html( $group_label ) . '</h3><ul>';

			foreach ( $by_group[ $group ] as $key ) {
				$entry = $found[ $key ];

				$html .= sprintf(
					'<li><span class="uz-chip uz-chip-%1$s">%2$s</span> <span class="uz-muted">%3$s</span></li>',
					esc_attr( Update_Zombie_Signals::tone( $key ) ),
					esc_html( Update_Zombie_Signals::label( $key ) ),
					esc_html( implode( ', ', array_slice( $entry['files'], 0, 6 ) ) )
				);
			}

			$html .= '</ul></div>';
		}

		if ( ! empty( $signals['filtered'] ) ) {
			$html .= '<div class="uz-breakdown-group"><h3>' . esc_html__( 'Changed but not read', 'update-zombie' ) . '</h3><ul>';

			foreach ( $signals['filtered'] as $category => $count ) {
				$html .= sprintf(
					'<li><span class="uz-chip uz-chip-filtered">%1$s</span> <span class="uz-muted">%2$s</span></li>',
					esc_html( Update_Zombie_Signals::filtered_label( $category ) ),
					esc_html(
						sprintf(
							/* translators: %s: number of files. */
							_n( '%s file changed', '%s files changed', (int) $count, 'update-zombie' ),
							number_format_i18n( (int) $count )
						)
					)
				);
			}

			$html .= '</ul></div>';
		}

		return $html . '</div>';
	}
}
