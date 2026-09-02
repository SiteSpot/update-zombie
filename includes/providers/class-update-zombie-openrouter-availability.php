<?php
/**
 * OpenRouter availability check.
 *
 * @package Update_Zombie
 */

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;

/**
 * Reports whether OpenRouter is usable.
 *
 * Deliberately a local check rather than a call to the models endpoint: the
 * registry asks this question on ordinary admin page loads, and it should not
 * cost an HTTP round trip to answer.
 *
 * @since 0.2.0
 */
class Update_Zombie_OpenRouter_Availability implements ProviderAvailabilityInterface {

	/**
	 * Returns whether an API key is configured.
	 *
	 * @since 0.2.0
	 *
	 * @return bool
	 */
	public function isConfigured(): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Implements an SDK interface.
		return Update_Zombie_Credentials::has_key();
	}
}
