<?php
/**
 * OpenRouter model catalogue.
 *
 * @package Update_Zombie
 */

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * Describes the OpenRouter models this plugin knows about.
 *
 * OpenRouter proxies several hundred models and the list changes constantly,
 * so this is a curated shortlist for the settings dropdown rather than a
 * mirror of the catalogue. Any other OpenRouter model ID still works: unknown
 * IDs are synthesised on demand, so typing one into the settings field is
 * enough.
 *
 * @since 0.2.0
 */
class Update_Zombie_OpenRouter_Directory implements ModelMetadataDirectoryInterface {

	const DEFAULT_MODEL = 'z-ai/glm-5.3-flash';

	/**
	 * Curated model shortlist, keyed by OpenRouter model ID.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, string> Model ID to display name.
	 */
	public static function known_models() {
		$models = array(
			'z-ai/glm-5.3-flash'          => 'GLM 5.3 Flash (fast, very cheap, 1.3M context)',
			'z-ai/glm-5.3'                => 'GLM 5.3',
			'z-ai/glm-4.7'                => 'GLM 4.7',
			'anthropic/claude-sonnet-5'   => 'Claude Sonnet 5',
			'anthropic/claude-opus-5'     => 'Claude Opus 5',
			'openai/gpt-5'                => 'GPT-5',
			'google/gemini-3-pro'         => 'Gemini 3 Pro',
		);

		/**
		 * Filters the OpenRouter model shortlist offered in the settings screen.
		 *
		 * Any model ID OpenRouter accepts works whether or not it appears here.
		 *
		 * @since 0.2.0
		 *
		 * @param array<string, string> $models Model ID to display name.
		 */
		return apply_filters( 'update_zombie_openrouter_models', $models );
	}

	/**
	 * Lists metadata for every known model.
	 *
	 * @since 0.2.0
	 *
	 * @return list<ModelMetadata>
	 */
	public function listModelMetadata(): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Implements an SDK interface.
		$list = array();

		foreach ( self::known_models() as $id => $name ) {
			$list[] = self::build_metadata( $id, $name );
		}

		return $list;
	}

	/**
	 * Returns whether a model ID can be used.
	 *
	 * Accepts any well-formed OpenRouter identifier, not just the shortlist,
	 * so a newly released model does not need a plugin update to be usable.
	 *
	 * @since 0.2.0
	 *
	 * @param string $modelId Model identifier.
	 * @return bool
	 */
	public function hasModelMetadata( string $modelId ): bool { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Implements an SDK interface.
		return (bool) preg_match( '#^[a-z0-9._~\-]+/[a-z0-9._~:\-]+$#i', $modelId );
	}

	/**
	 * Returns metadata for a model.
	 *
	 * @since 0.2.0
	 *
	 * @param string $modelId Model identifier.
	 * @return ModelMetadata
	 * @throws InvalidArgumentException When the identifier is not usable.
	 */
	public function getModelMetadata( string $modelId ): ModelMetadata { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Implements an SDK interface.
		if ( ! $this->hasModelMetadata( $modelId ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Unknown OpenRouter model ID "%s".', esc_html( $modelId ) )
			);
		}

		$known = self::known_models();

		return self::build_metadata( $modelId, $known[ $modelId ] ?? $modelId );
	}

	/**
	 * Builds metadata for a model ID.
	 *
	 * Capabilities are the real gate here: declaring only text generation is
	 * what stops the registry offering this provider for image or speech work
	 * it would fail at.
	 *
	 * @since 0.2.0
	 *
	 * @param string $id   Model identifier.
	 * @param string $name Display name.
	 * @return ModelMetadata
	 */
	protected static function build_metadata( $id, $name ) {
		$capabilities = array(
			CapabilityEnum::from( CapabilityEnum::TEXT_GENERATION ),
			CapabilityEnum::from( CapabilityEnum::CHAT_HISTORY ),
		);

		$option_keys = array(
			ModelConfig::KEY_SYSTEM_INSTRUCTION,
			ModelConfig::KEY_MAX_TOKENS,
			ModelConfig::KEY_TEMPERATURE,
			ModelConfig::KEY_TOP_P,
			ModelConfig::KEY_STOP_SEQUENCES,
			ModelConfig::KEY_CANDIDATE_COUNT,
			ModelConfig::KEY_PRESENCE_PENALTY,
			ModelConfig::KEY_FREQUENCY_PENALTY,
			ModelConfig::KEY_FUNCTION_DECLARATIONS,
			ModelConfig::KEY_OUTPUT_MIME_TYPE,
			ModelConfig::KEY_OUTPUT_SCHEMA,
			ModelConfig::KEY_OUTPUT_MODALITIES,
			ModelConfig::KEY_INPUT_MODALITIES,
		);

		$options = array();

		foreach ( $option_keys as $key ) {
			$options[] = new SupportedOption( OptionEnum::from( $key ) );
		}

		return new ModelMetadata( $id, $name, $capabilities, $options );
	}
}
