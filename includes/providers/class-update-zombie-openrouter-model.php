<?php
/**
 * OpenRouter text generation model.
 *
 * @package Update_Zombie
 */

defined( 'ABSPATH' ) || exit;

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

/**
 * Talks to OpenRouter's chat completions endpoint.
 *
 * OpenRouter speaks OpenAI's wire format, so the SDK's OpenAI-compatible base
 * class already handles message shaping, JSON schema response formats, tool
 * calls and response parsing. Only request construction is left to do.
 *
 * @since 0.2.0
 */
class Update_Zombie_OpenRouter_Model extends AbstractOpenAiCompatibleTextGenerationModel {

	/**
	 * Sends the response schema in the shape OpenAI-compatible APIs enforce.
	 *
	 * The SDK's base class puts the schema directly under "json_schema",
	 * but the OpenAI format — and OpenRouter's strict enforcement — expects
	 * {"name", "strict", "schema"}. Without the wrapper, models drift: bare
	 * top-level arrays, invented key names, missing required fields.
	 *
	 * @since 0.4.0
	 *
	 * @param array<string, mixed>|null $outputSchema JSON schema, if one was set.
	 * @return array<string, mixed>
	 */
	protected function prepareResponseFormatParam( ?array $outputSchema ): array { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid, WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- Implements an SDK interface.
		if ( ! is_array( $outputSchema ) ) {
			return array( 'type' => 'json_object' );
		}

		return array(
			'type'        => 'json_schema',
			'json_schema' => array(
				'name'   => 'update_zombie_review',
				'strict' => true,
				'schema' => $outputSchema,
			),
		);
	}

	/**
	 * Builds a request against the OpenRouter API.
	 *
	 * Authentication is not applied here: the registry injects an
	 * ApiKeyRequestAuthentication which adds the bearer header downstream.
	 *
	 * The model's request options must be passed to the Request. Without them
	 * the transport falls back to WordPress's five second default, which a
	 * diff-sized prompt will never finish inside.
	 *
	 * @since 0.2.0
	 *
	 * @param HttpMethodEnum       $method  HTTP method.
	 * @param string               $path    Path relative to the API root, e.g. "chat/completions".
	 * @param array<string, string> $headers Additional headers.
	 * @param mixed                $data    Request body.
	 * @return Request
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- Implements an SDK interface.
		$uri = Update_Zombie_OpenRouter_Provider::api_root() . '/' . ltrim( $path, '/' );

		// OpenRouter uses these for attribution in its dashboard and rankings.
		// They are the site's own public URL and the plugin name, nothing more.
		$headers = array_merge(
			array(
				'HTTP-Referer' => home_url( '/' ),
				'X-Title'      => 'Update Zombie',
			),
			$headers
		);

		return new Request( $method, $uri, $headers, $data, $this->getRequestOptions() );
	}
}
