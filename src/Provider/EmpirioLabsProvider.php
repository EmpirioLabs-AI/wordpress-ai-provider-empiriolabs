<?php

declare(strict_types=1);

namespace EmpirioLabs\AiProvider\Provider;

if (!defined('ABSPATH')) {
    exit;
}

use EmpirioLabs\AiProvider\Metadata\EmpirioLabsModelMetadataDirectory;
use EmpirioLabs\AiProvider\Models\EmpirioLabsEmbeddingGenerationModel;
use EmpirioLabs\AiProvider\Models\EmpirioLabsImageGenerationModel;
use EmpirioLabs\AiProvider\Models\EmpirioLabsTextGenerationModel;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\EmbeddingGeneration\Contracts\EmbeddingGenerationModelInterface;

/**
 * Class for the EmpirioLabs AI provider.
 *
 * @since 1.0.0
 */
class EmpirioLabsProvider extends AbstractApiProvider
{
    /**
     * The plugin version, sent in the User-Agent header of every API request.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const VERSION = '1.0.0';

    /**
     * The provider ID. It also names the API key setting (`connectors_ai_empiriolabs_api_key`) and the
     * environment variable or PHP constant (`EMPIRIOLABS_API_KEY`) the AI Client reads.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const ID = 'empiriolabs';

    /**
     * Returns the User-Agent header value for API requests.
     *
     * @since 1.0.0
     *
     * @return string The User-Agent header value.
     */
    public static function userAgent(): string
    {
        return 'empiriolabs-ai-provider/' . self::VERSION . ' (WordPress)';
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function baseUrl(): string
    {
        return 'https://api.empiriolabs.ai/v1';
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createModel(
        ModelMetadata $modelMetadata,
        ProviderMetadata $providerMetadata
    ): ModelInterface {
        $capabilities = $modelMetadata->getSupportedCapabilities();
        foreach ($capabilities as $capability) {
            if ($capability->isTextGeneration()) {
                return new EmpirioLabsTextGenerationModel($modelMetadata, $providerMetadata);
            }
            if ($capability->isImageGeneration()) {
                return new EmpirioLabsImageGenerationModel($modelMetadata, $providerMetadata);
            }
            // Embedding generation needs version 1.4.0 or later of the AI Client.
            if (
                $capability->isEmbeddingGeneration() &&
                interface_exists(EmbeddingGenerationModelInterface::class)
            ) {
                return new EmpirioLabsEmbeddingGenerationModel($modelMetadata, $providerMetadata);
            }
        }

        throw new RuntimeException(
            'Unsupported model capabilities: ' . esc_html(implode(', ', $capabilities))
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createProviderMetadata(): ProviderMetadata
    {
        $supportsEmbeddings = interface_exists(EmbeddingGenerationModelInterface::class);

        if (function_exists('__')) {
            $description = $supportsEmbeddings
                ? __('Text, image, and embedding generation with the EmpirioLabs AI model catalog.', 'empiriolabs-ai-provider')
                : __('Text and image generation with the EmpirioLabs AI model catalog.', 'empiriolabs-ai-provider');
        } else {
            $description = $supportsEmbeddings
                ? 'Text, image, and embedding generation with the EmpirioLabs AI model catalog.'
                : 'Text and image generation with the EmpirioLabs AI model catalog.';
        }

        $pluginDir = dirname(__DIR__, 2);
        if (defined('EMPIRIOLABS_AI_PROVIDER_PLUGIN_DIR')) {
            $definedPluginDir = constant('EMPIRIOLABS_AI_PROVIDER_PLUGIN_DIR');
            if (is_string($definedPluginDir)) {
                $pluginDir = $definedPluginDir;
            }
        }

        return new ProviderMetadata(
            self::ID,
            'EmpirioLabs AI',
            ProviderTypeEnum::cloud(),
            'https://platform.empiriolabs.ai/dashboard/api-keys',
            RequestAuthenticationMethod::apiKey(),
            $description,
            $pluginDir . '/assets/images/empiriolabs.png'
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createProviderAvailability(): ProviderAvailabilityInterface
    {
        // The models endpoint answers 401 to a key that does not verify, so listing models checks the key.
        return new ListModelsApiBasedProviderAvailability(
            static::modelMetadataDirectory()
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface
    {
        return new EmpirioLabsModelMetadataDirectory();
    }
}
