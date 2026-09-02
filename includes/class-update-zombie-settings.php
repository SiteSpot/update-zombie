<?php
/**
 * Plugin settings storage and defaults.
 *
 * @package Update_Zombie
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads, writes and sanitizes the plugin's single options array.
 *
 * @since 0.1.0
 */
class Update_Zombie_Settings {

	const OPTION = 'update_zombie_settings';

	/**
	 * Enforcement mode: report only, never change update behaviour.
	 */
	const MODE_ADVISORY = 'advisory';

	/**
	 * Enforcement mode: auto-apply security fixes, hold updates judged bad.
	 */
	const MODE_GUARDED = 'guarded';

	/**
	 * Enforcement mode: auto-apply security fixes and good updates, hold bad ones.
	 */
	const MODE_AUTOPILOT = 'autopilot';

	/**
	 * Analysis engine: send diffs to a model.
	 */
	const ENGINE_AI = 'ai';

	/**
	 * Analysis engine: changelog and diff pattern scanning only, no AI.
	 */
	const ENGINE_SIGNALS = 'signals';

	/**
	 * Returns the default settings.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'mode'                  => self::MODE_ADVISORY,
			'analysis_engine'       => self::ENGINE_AI,
			'signals_auto_security' => false,
			'watch_plugins'         => true,
			'watch_themes'          => true,
			'watch_core'            => true,
			'core_majors'           => false,
			'security_confidence'   => 70,
			/*
			 * Measured, not guessed. The same plugin update analysed at a 1 MB
			 * prompt returned a fluent summary with every structured array
			 * empty and cost 13 times more; at 300 KB the same model returned
			 * seven cited security findings, four concerns and three breaking
			 * changes. Big context windows are not the same as reading well,
			 * and the report always lists what it could not fit.
			 */
			'diff_char_budget'      => 300000,
			'max_file_bytes'        => 524288,
			// Fifteen minutes, polling WordPress.org ourselves: with the queue
			// draining every five, a security patch is judged and installed in
			// roughly ten to twenty minutes of being published.
			'analysis_interval'     => 'update_zombie_fifteen_minutes',
			'retention_days'        => 90,
			'openrouter_key'        => '',
			// Literal rather than Update_Zombie_OpenRouter_Directory::DEFAULT_MODEL:
			// defaults() runs on every page load, and referencing that class
			// would autoload one that implements an AI Client SDK interface.
			'model_preference'      => 'z-ai/glm-5.3-flash',
			'request_timeout'       => 300,
			'notify_email'          => true,
			'notify_email_address'  => '',
			'notify_on_security'    => true,
			'notify_on_concerns'    => true,
			'notify_on_held'        => true,
			'notify_on_error'       => false,
			'webhook_enabled'       => false,
			'webhook_url'           => '',
			'webhook_secret'        => '',
		);
	}

	/**
	 * Returns all settings, merged over the defaults.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string, mixed>
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );

		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Returns a single setting.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Value to return when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Merges a partial array of settings into the stored option.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string, mixed> $values Values to write.
	 * @return void
	 */
	public static function update( array $values ) {
		update_option( self::OPTION, array_merge( self::all(), $values ) );
	}

	/**
	 * Sanitizes a submitted settings array.
	 *
	 * Registered as the Settings API sanitize callback, so it receives raw
	 * user input and must be defensive about every key.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $input Raw submitted value.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$out      = array();

		$modes       = array( self::MODE_ADVISORY, self::MODE_GUARDED, self::MODE_AUTOPILOT );
		$out['mode'] = in_array( $input['mode'] ?? '', $modes, true ) ? $input['mode'] : $defaults['mode'];

		$engines                = array( self::ENGINE_AI, self::ENGINE_SIGNALS );
		$out['analysis_engine'] = in_array( $input['analysis_engine'] ?? '', $engines, true ) ? $input['analysis_engine'] : $defaults['analysis_engine'];

		foreach ( array( 'signals_auto_security', 'watch_plugins', 'watch_themes', 'watch_core', 'core_majors', 'notify_email', 'notify_on_security', 'notify_on_concerns', 'notify_on_held', 'notify_on_error', 'webhook_enabled' ) as $flag ) {
			$out[ $flag ] = ! empty( $input[ $flag ] );
		}

		$out['security_confidence'] = min( 100, max( 0, absint( $input['security_confidence'] ?? $defaults['security_confidence'] ) ) );
		$out['diff_char_budget']    = min( 8000000, max( 20000, absint( $input['diff_char_budget'] ?? $defaults['diff_char_budget'] ) ) );
		$out['max_file_bytes']      = min( 4194304, max( 8192, absint( $input['max_file_bytes'] ?? $defaults['max_file_bytes'] ) ) );
		$out['retention_days']      = min( 3650, max( 1, absint( $input['retention_days'] ?? $defaults['retention_days'] ) ) );
		$out['request_timeout']     = min( 900, max( 30, absint( $input['request_timeout'] ?? $defaults['request_timeout'] ) ) );

		$intervals                = array_keys( wp_get_schedules() );
		$interval                 = sanitize_key( $input['analysis_interval'] ?? '' );
		$out['analysis_interval'] = in_array( $interval, $intervals, true ) ? $interval : $defaults['analysis_interval'];

		$model                   = trim( sanitize_text_field( $input['model_preference'] ?? '' ) );
		$out['model_preference'] = preg_match( '#^[a-z0-9._~\-]+/[a-z0-9._~:\-]+$#i', $model ) ? $model : $defaults['model_preference'];

		$out['notify_email_address'] = sanitize_email( $input['notify_email_address'] ?? '' );
		$out['webhook_secret']       = sanitize_text_field( $input['webhook_secret'] ?? '' );
		$out['openrouter_key']       = self::sanitize_api_key( $input );

		$webhook            = esc_url_raw( trim( (string) ( $input['webhook_url'] ?? '' ) ) );
		$out['webhook_url'] = ( $webhook && wp_http_validate_url( $webhook ) ) ? $webhook : '';

		if ( $out['webhook_enabled'] && ! $out['webhook_url'] ) {
			$out['webhook_enabled'] = false;
			add_settings_error(
				self::OPTION,
				'update_zombie_webhook',
				__( 'Webhook notifications were turned off because the URL was empty or invalid.', 'update-zombie' ),
				'warning'
			);
		}

		return array_merge( $defaults, $out );
	}

	/**
	 * Returns the selectable diff budgets.
	 *
	 * Offered as a fixed set rather than a free number field: there is no
	 * meaningful difference between 3.2 and 3.3 million characters, and a
	 * number input on a range this wide is all downside.
	 *
	 * @since 0.3.1
	 *
	 * @return array<int, string> Character budget to label.
	 */
	public static function budget_presets() {
		$presets = array( 150000, 300000, 500000, 1000000, 2000000, 4000000 );
		$options = array();

		foreach ( $presets as $value ) {
			$label = sprintf(
				/* translators: 1: number of characters, 2: approximate number of tokens. */
				__( '%1$s characters — roughly %2$s tokens', 'update-zombie' ),
				number_format_i18n( $value ),
				number_format_i18n( (int) round( $value / 3.5, -3 ) )
			);

			if ( 300000 === $value ) {
				$label .= __( ' — recommended', 'update-zombie' );
			}

			if ( $value >= 1000000 ) {
				$label .= __( ' — slow, and measurably worse', 'update-zombie' );
			}

			$options[ $value ] = $label;
		}

		return $options;
	}

	/**
	 * Returns the selectable per-file size limits.
	 *
	 * @since 0.3.1
	 *
	 * @return array<int, string> Byte limit to label.
	 */
	public static function file_size_presets() {
		$presets = array( 131072, 262144, 524288, 1048576, 2097152, 4194304 );
		$options = array();

		foreach ( $presets as $value ) {
			$options[ $value ] = size_format( $value );
		}

		return $options;
	}

	/**
	 * Works out what to store for the API key.
	 *
	 * The settings form never renders the real key, so an empty submission
	 * means "unchanged", not "delete". Clearing is an explicit checkbox. When
	 * wp-config supplies the key, nothing is stored at all.
	 *
	 * @since 0.2.0
	 *
	 * @param array<string, mixed> $input Raw submitted settings.
	 * @return string
	 */
	protected static function sanitize_api_key( array $input ) {
		if ( defined( Update_Zombie_Credentials::CONSTANT ) && '' !== trim( (string) constant( Update_Zombie_Credentials::CONSTANT ) ) ) {
			return '';
		}

		if ( ! empty( $input['openrouter_key_clear'] ) ) {
			return '';
		}

		$submitted = trim( (string) ( $input['openrouter_key'] ?? '' ) );

		if ( '' === $submitted ) {
			$stored = get_option( self::OPTION, array() );

			return is_array( $stored ) ? (string) ( $stored['openrouter_key'] ?? '' ) : '';
		}

		// Keys are opaque tokens; strip anything that could not be one rather
		// than running them through a text sanitiser that might mangle them.
		return preg_replace( '/[^A-Za-z0-9._\-]/', '', $submitted );
	}

	/**
	 * Returns the address that notification email should go to.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public static function notification_address() {
		$address = (string) self::get( 'notify_email_address' );

		if ( ! is_email( $address ) ) {
			$address = (string) get_option( 'admin_email' );
		}

		return $address;
	}
}
