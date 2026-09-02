<?php
/**
 * API credential resolution.
 *
 * @package Update_Zombie
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the OpenRouter API key.
 *
 * A constant in wp-config.php always wins over the stored setting, so a site
 * can keep the key out of the database (and out of every database backup)
 * while the settings screen still works on hosts where wp-config is not
 * editable.
 *
 * @since 0.2.0
 */
class Update_Zombie_Credentials {

	const CONSTANT = 'UPDATE_ZOMBIE_OPENROUTER_KEY';

	const SOURCE_CONSTANT = 'constant';
	const SOURCE_OPTION   = 'option';
	const SOURCE_NONE     = 'none';

	/**
	 * Returns the OpenRouter API key.
	 *
	 * @since 0.2.0
	 *
	 * @return string Empty string when no key is configured.
	 */
	public static function openrouter_key() {
		if ( defined( self::CONSTANT ) ) {
			$key = trim( (string) constant( self::CONSTANT ) );

			if ( '' !== $key ) {
				return $key;
			}
		}

		return trim( (string) Update_Zombie_Settings::get( 'openrouter_key', '' ) );
	}

	/**
	 * Returns where the active key came from.
	 *
	 * @since 0.2.0
	 *
	 * @return string One of the SOURCE_* constants.
	 */
	public static function key_source() {
		if ( defined( self::CONSTANT ) && '' !== trim( (string) constant( self::CONSTANT ) ) ) {
			return self::SOURCE_CONSTANT;
		}

		if ( '' !== trim( (string) Update_Zombie_Settings::get( 'openrouter_key', '' ) ) ) {
			return self::SOURCE_OPTION;
		}

		return self::SOURCE_NONE;
	}

	/**
	 * Returns whether a key is available.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public static function has_key() {
		return '' !== self::openrouter_key();
	}

	/**
	 * Returns a masked form of the key, safe to display.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public static function masked_key() {
		$key = self::openrouter_key();

		if ( '' === $key ) {
			return '';
		}

		if ( strlen( $key ) <= 12 ) {
			return str_repeat( '•', strlen( $key ) );
		}

		return substr( $key, 0, 8 ) . str_repeat( '•', 12 ) . substr( $key, -4 );
	}
}
