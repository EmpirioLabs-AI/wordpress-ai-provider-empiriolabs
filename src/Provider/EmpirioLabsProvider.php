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
use EmpirioLabs\AiProvider\Models\EmpirioLabsTextToSpeechConversionModel;
use EmpirioLabs\AiProvider\Models\EmpirioLabsVideoGenerationModel;
use EmpirioLabs\AiProvider\Operations\EmpirioLabsOperationsHandler;
use WordPress\AiClient\Common\Exception\RuntimeException;
use WordPress\AiClient\Operations\DTO\GenerativeAiOperation;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\Contracts\ProviderOperationsHandlerInterface;
use WordPress\AiClient\Providers\Contracts\ProviderWithOperationsHandlerInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\EmbeddingGeneration\Contracts\EmbeddingGenerationModelInterface;
use WordPress\AiClient\Providers\Models\TextToSpeechConversion\Contracts\TextToSpeechConversionModelInterface;
use WordPress\AiClient\Providers\Models\VideoGeneration\Contracts\VideoGenerationModelInterface;
use WordPress\AiClient\Providers\Models\VideoGeneration\Contracts\VideoGenerationOperationModelInterface;

/**
 * Class for the EmpirioLabs AI provider.
 *
 * @since 1.0.0
 * @since 1.1.0 Offers text-to-speech and video generation models, and an operations handler for generations
 *              that run in the background.
 */
class EmpirioLabsProvider extends AbstractApiProvider implements ProviderWithOperationsHandlerInterface
{
    /**
     * The plugin version, sent in the User-Agent header of every API request.
     *
     * @since 1.0.0
     *
     * @var string
     */
    public const VERSION = '1.1.0';

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
     * Timeout in seconds for a speech or video request whose caller set none.
     *
     * WordPress sets 30 seconds on a prompt it builds. A model that is called directly has no timeout of its
     * own, and the 5 seconds WordPress then allows are shorter than these requests can take.
     *
     * @since 1.1.0
     *
     * @var float
     */
    public const DEFAULT_REQUEST_TIMEOUT = 30.0;

    /**
     * The handler that reads generations that run in the background.
     *
     * @since 1.1.0
     *
     * @var EmpirioLabsOperationsHandler|null
     */
    private static ?EmpirioLabsOperationsHandler $operationsHandler = null;

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
     * Checks whether the AI Client in use defines text-to-speech conversion.
     *
     * @since 1.1.0
     *
     * @return bool True if text-to-speech models can be offered.
     */
    public static function supportsTextToSpeechConversion(): bool
    {
        return interface_exists(TextToSpeechConversionModelInterface::class);
    }

    /**
     * Checks whether the AI Client in use defines video generation, with operations for long generations.
     *
     * @since 1.1.0
     *
     * @return bool True if video generation models can be offered.
     */
    public static function supportsVideoGeneration(): bool
    {
        return interface_exists(VideoGenerationModelInterface::class)
            && interface_exists(VideoGenerationOperationModelInterface::class)
            && class_exists(GenerativeAiOperation::class);
    }

    /**
     * {@inheritDoc}
     *
     * Returns the handler that reads a generation started with a model's operation method, such as
     * `generateVideoOperation()`, by its operation ID.
     *
     * @since 1.1.0
     */
    public static function operationsHandler(): ProviderOperationsHandlerInterface
    {
        if (self::$operationsHandler === null) {
            self::$operationsHandler = new EmpirioLabsOperationsHandler();
        }

        return self::$operationsHandler;
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
            if ($capability->isTextToSpeechConversion() && self::supportsTextToSpeechConversion()) {
                return new EmpirioLabsTextToSpeechConversionModel($modelMetadata, $providerMetadata);
            }
            if ($capability->isVideoGeneration() && self::supportsVideoGeneration()) {
                return new EmpirioLabsVideoGenerationModel($modelMetadata, $providerMetadata);
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
        // Speech and video come with every AI Client version this plugin runs on; embeddings need 1.4.0 or later.
        $supportsEmbeddings = interface_exists(EmbeddingGenerationModelInterface::class);

        if (function_exists('__')) {
            $description = $supportsEmbeddings
                ? __(
                    'Text, image, speech, video, and embedding generation with the EmpirioLabs AI model catalog.',
                    'empiriolabs-ai-provider'
                )
                : __(
                    'Text, image, speech, and video generation with the EmpirioLabs AI model catalog.',
                    'empiriolabs-ai-provider'
                );
        } else {
            $description = $supportsEmbeddings
                ? 'Text, image, speech, video, and embedding generation with the EmpirioLabs AI model catalog.'
                : 'Text, image, speech, and video generation with the EmpirioLabs AI model catalog.';
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
