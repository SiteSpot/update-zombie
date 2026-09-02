<?php
/**
 * OpenRouter provider for the WordPress AI Client.
 *
 * @package Update_Zombie
 */

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * Registers OpenRouter with core's AI Client so any model it proxies can be
 * used through wp_ai_client_prompt().
 *
 * WordPress 7.x ships the AI Client SDK but registers no providers of its own,
 * so a plugin that wants to talk to a model has to supply one.
 *
 * @since 0.2.0
 */
class Update_Zombie_OpenRouter_Provider extends AbstractApiProvider {

	const PROVIDER_ID = 'openrouter';

	/**
	 * Returns the API root.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	protected static function baseUrl(): string { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Implements an SDK interface.
		return 'https://openrouter.ai/api/v1';
	}

	/**
	 * Describes the provider.
	 *
	 * @since 0.2.0
	 *
	 * @return ProviderMetadata
	 */
	protected static function createProviderMetadata(): ProviderMetadata { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Implements an SDK interface.
		return new ProviderMetadata(
			self::PROVIDER_ID,
			'OpenRouter',
			ProviderTypeEnum::from( ProviderTypeEnum::CLOUD ),
			'https://openrouter.ai/keys',
			RequestAuthenticationMethod::from( RequestAuthenticationMethod::API_KEY )
		);
	}

	/**
	 * Reports whether a key is present.
	 *
	 * @since 0.2.0
	 *
	 * @return ProviderAvailabilityInterface
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Implements an SDK interface.
		return new Update_Zombie_OpenRouter_Availability();
	}

	/**
	 * Returns the model catalogue.
	 *
	 * @since 0.2.0
	 *
	 * @return ModelMetadataDirectoryInterface
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Implements an SDK interface.
		return new Update_Zombie_OpenRouter_Directory();
	}

	/**
	 * Builds a model instance.
	 *
	 * @since 0.2.0
	 *
	 * @param ModelMetadata    $modelMetadata    Model being instantiated.
	 * @param ProviderMetadata $providerMetadata This provider's metadata.
	 * @return ModelInterface
	 */
	protected static function createModel( ModelMetadata $modelMetadata, ProviderMetadata $providerMetadata ): ModelInterface { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Implements an SDK interface.
		return new Update_Zombie_OpenRouter_Model( $modelMetadata, $providerMetadata, new ModelConfig() );
	}

	/**
	 * Returns the API root for collaborators that need it.
	 *
	 * baseUrl() is protected by the SDK contract, so expose it separately
	 * rather than widening the override.
	 *
	 * @since 0.2.0
	 *
	 * @return string
	 */
	public static function api_root() {
		return 'https://openrouter.ai/api/v1';
	}
}
