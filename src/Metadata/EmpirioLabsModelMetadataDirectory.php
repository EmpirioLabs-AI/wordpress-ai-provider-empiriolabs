<?php

declare(strict_types=1);

namespace EmpirioLabs\AiProvider\Metadata;

if (!defined('ABSPATH')) {
    exit;
}

use EmpirioLabs\AiProvider\Provider\EmpirioLabsProvider;
use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\EmbeddingGeneration\Contracts\EmbeddingGenerationModelInterface;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;

/**
 * Class for the EmpirioLabs AI model metadata directory.
 *
 * Reads the live model catalog from `GET /v1/models` and maps each entry to the AI Client's capabilities and
 * options from the fields the catalog publishes (`supported_endpoints`, `category`, the modality lists,
 * `features`, `structured_output` and `supported_parameters`), so models are never hard-coded here.
 *
 * @since 1.0.0
 *
 * @phpstan-type ParameterData array{name?: string, options?: list<mixed>}
 * @phpstan-type ModelData array{
 *     id?: string,
 *     display_name?: string,
 *     category?: string,
 *     status?: string,
 *     deprecated?: bool|null,
 *     input_modalities?: list<string>,
 *     output_modalities?: list<string>,
 *     features?: list<string>,
 *     structured_output?: string|null,
 *     capabilities?: array<string, mixed>,
 *     supported_endpoints?: list<string>,
 *     supported_parameters?: list<ParameterData>
 * }
 * @phpstan-type ModelsResponseData array{data?: list<ModelData>}
 */
class EmpirioLabsModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory
{
    /**
     * Catalog endpoint that marks a model as a text generation model.
     *
     * @since 1.0.0
     *
     * @var string
     */
    private const ENDPOINT_CHAT = 'POST /v1/chat/completions';

    /**
     * Catalog endpoint that marks a model as an image generation model.
     *
     * @since 1.0.0
     *
     * @var string
     */
    private const ENDPOINT_IMAGES = 'POST /v1/images/generations';

    /**
     * Catalog endpoint that marks a model as an embedding model.
     *
     * @since 1.0.0
     *
     * @var string
     */
    private const ENDPOINT_EMBEDDINGS = 'POST /v1/embeddings';

    /**
     * Catalog categories whose chat models produce general text.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    private const TEXT_CATEGORIES = ['text', 'research'];

    /**
     * Image output formats the API accepts, mapped to their MIME types.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    private const IMAGE_FORMAT_MIME_TYPES = [
        'png' => 'image/png',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'webp' => 'image/webp',
    ];

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = [], $data = null): Request
    {
        $headers['User-Agent'] = EmpirioLabsProvider::userAgent();

        return new Request(
            $method,
            EmpirioLabsProvider::url($path),
            $headers,
            $data
        );
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected function parseResponseToModelMetadataList(Response $response): array
    {
        /** @var ModelsResponseData $responseData */
        $responseData = $response->getData();
        if (!isset($responseData['data']) || !$responseData['data']) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw ResponseException::fromMissingData('EmpirioLabs AI', 'data');
        }
        if (!is_array($responseData['data'])) {
            throw ResponseException::fromInvalidData(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                'EmpirioLabs AI',
                'data',
                'The value must be an array.'
            );
        }

        $current = [];
        $deprecated = [];
        foreach ($responseData['data'] as $modelData) {
            if (!is_array($modelData)) {
                continue;
            }
            $metadata = self::createModelMetadata($modelData);
            if ($metadata === null) {
                continue;
            }
            // Keep the catalog's own order; models scheduled for retirement go last.
            if (!empty($modelData['deprecated'])) {
                $deprecated[] = $metadata;
            } else {
                $current[] = $metadata;
            }
        }

        return array_merge($current, $deprecated);
    }

    /**
     * Creates the model metadata for one catalog entry.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $modelData The catalog entry.
     * @return ModelMetadata|null The model metadata, or null if the AI Client cannot use the model.
     */
    public static function createModelMetadata(array $modelData): ?ModelMetadata
    {
        if (!isset($modelData['id']) || !is_string($modelData['id']) || $modelData['id'] === '') {
            return null;
        }
        if (isset($modelData['status']) && $modelData['status'] !== 'available') {
            return null;
        }

        $modelId = $modelData['id'];
        $name = isset($modelData['display_name']) && is_string($modelData['display_name'])
            && $modelData['display_name'] !== ''
            ? $modelData['display_name']
            : $modelId;

        $endpoints = self::stringList($modelData['supported_endpoints'] ?? null);
        $category = isset($modelData['category']) && is_string($modelData['category']) ? $modelData['category'] : '';
        $outputModalities = self::stringList($modelData['output_modalities'] ?? null);
        $parameters = self::parameterMap($modelData['supported_parameters'] ?? null);

        if (
            in_array(self::ENDPOINT_CHAT, $endpoints, true) &&
            in_array($category, self::TEXT_CATEGORIES, true) &&
            in_array('text', $outputModalities, true)
        ) {
            return new ModelMetadata(
                $modelId,
                $name,
                [CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory()],
                self::textOptions($modelData, $parameters)
            );
        }

        if (
            in_array(self::ENDPOINT_IMAGES, $endpoints, true) &&
            $category === 'image' &&
            in_array('image', $outputModalities, true) &&
            isset($parameters['response_format'])
        ) {
            return new ModelMetadata(
                $modelId,
                $name,
                [CapabilityEnum::imageGeneration()],
                self::imageOptions($parameters)
            );
        }

        // Embedding generation needs version 1.4.0 or later of the AI Client.
        if (
            in_array(self::ENDPOINT_EMBEDDINGS, $endpoints, true) &&
            $category === 'embedding' &&
            interface_exists(EmbeddingGenerationModelInterface::class)
        ) {
            return new ModelMetadata(
                $modelId,
                $name,
                [CapabilityEnum::embeddingGeneration()],
                self::embeddingOptions($parameters)
            );
        }

        return null;
    }

    /**
     * Returns the supported options of a text generation model.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $modelData The catalog entry.
     * @param array<string, list<mixed>> $parameters Map of the model's parameter names to their options.
     * @return list<SupportedOption> The supported options.
     */
    private static function textOptions(array $modelData, array $parameters): array
    {
        $features = self::stringList($modelData['features'] ?? null);
        $capabilities = isset($modelData['capabilities']) && is_array($modelData['capabilities'])
            ? $modelData['capabilities']
            : [];
        $structuredOutput = isset($modelData['structured_output']) && is_string($modelData['structured_output'])
            ? $modelData['structured_output']
            : '';

        $options = [];
        if (!isset($capabilities['system_prompt']) || $capabilities['system_prompt'] !== false) {
            $options[] = new SupportedOption(OptionEnum::systemInstruction());
        }
        if (
            isset($parameters['max_tokens']) ||
            isset($parameters['max_completion_tokens']) ||
            isset($parameters['max_output_tokens'])
        ) {
            $options[] = new SupportedOption(OptionEnum::maxTokens());
        }

        $parameterOptions = [
            'n' => OptionEnum::candidateCount(),
            'temperature' => OptionEnum::temperature(),
            'top_p' => OptionEnum::topP(),
            'top_k' => OptionEnum::topK(),
            'stop' => OptionEnum::stopSequences(),
            'presence_penalty' => OptionEnum::presencePenalty(),
            'frequency_penalty' => OptionEnum::frequencyPenalty(),
            'logprobs' => OptionEnum::logprobs(),
            'top_logprobs' => OptionEnum::topLogprobs(),
        ];
        foreach ($parameterOptions as $parameterName => $option) {
            if (isset($parameters[$parameterName])) {
                $options[] = new SupportedOption($option);
            }
        }

        if (in_array('function_calling', $features, true)) {
            $options[] = new SupportedOption(OptionEnum::functionDeclarations());
        }

        if ($structuredOutput === 'json_schema' || $structuredOutput === 'json_object') {
            $options[] = new SupportedOption(OptionEnum::outputMimeType(), ['text/plain', 'application/json']);
        } else {
            $options[] = new SupportedOption(OptionEnum::outputMimeType(), ['text/plain']);
        }
        if ($structuredOutput === 'json_schema') {
            $options[] = new SupportedOption(OptionEnum::outputSchema());
        }

        $options[] = new SupportedOption(OptionEnum::customOptions());
        $options[] = new SupportedOption(
            OptionEnum::inputModalities(),
            self::textInputModalityCombinations(self::stringList($modelData['input_modalities'] ?? null))
        );
        $options[] = new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::text()]]);

        return $options;
    }

    /**
     * Returns the input modality combinations of a text generation model.
     *
     * Every combination includes text. Images and audio are added when the catalog lists them, as those are the
     * inputs the chat completions request carries for the AI Client.
     *
     * @since 1.0.0
     *
     * @param list<string> $inputModalities The catalog's input modalities for the model.
     * @return list<list<ModalityEnum>> The supported combinations.
     */
    private static function textInputModalityCombinations(array $inputModalities): array
    {
        $combinations = [[ModalityEnum::text()]];
        $hasImage = in_array('image', $inputModalities, true);
        $hasAudio = in_array('audio', $inputModalities, true);
        if ($hasImage) {
            $combinations[] = [ModalityEnum::text(), ModalityEnum::image()];
        }
        if ($hasAudio) {
            $combinations[] = [ModalityEnum::text(), ModalityEnum::audio()];
        }
        if ($hasImage && $hasAudio) {
            $combinations[] = [ModalityEnum::text(), ModalityEnum::image(), ModalityEnum::audio()];
        }
        return $combinations;
    }

    /**
     * Returns the supported options of an image generation model.
     *
     * @since 1.0.0
     *
     * @param array<string, list<mixed>> $parameters Map of the model's parameter names to their options.
     * @return list<SupportedOption> The supported options.
     */
    private static function imageOptions(array $parameters): array
    {
        $options = [
            new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::image()]]),
            new SupportedOption(OptionEnum::outputFileType(), [FileTypeEnum::inline(), FileTypeEnum::remote()]),
        ];

        if (isset($parameters['num_images']) || isset($parameters['n'])) {
            $options[] = new SupportedOption(OptionEnum::candidateCount());
        }

        $mimeTypes = [];
        foreach (['format', 'output_format'] as $parameterName) {
            foreach ($parameters[$parameterName] ?? [] as $format) {
                if (is_string($format) && isset(self::IMAGE_FORMAT_MIME_TYPES[strtolower($format)])) {
                    $mimeTypes[] = self::IMAGE_FORMAT_MIME_TYPES[strtolower($format)];
                }
            }
        }
        if ($mimeTypes) {
            $options[] = new SupportedOption(OptionEnum::outputMimeType(), array_values(array_unique($mimeTypes)));
        }

        $aspectRatios = self::imageAspectRatios($parameters);
        if ($aspectRatios) {
            $options[] = new SupportedOption(OptionEnum::outputMediaAspectRatio(), $aspectRatios);
            $orientations = self::imageOrientations($aspectRatios);
            if ($orientations) {
                $options[] = new SupportedOption(OptionEnum::outputMediaOrientation(), $orientations);
            }
        }

        $options[] = new SupportedOption(OptionEnum::customOptions());

        return $options;
    }

    /**
     * Returns the aspect ratios an image generation model accepts.
     *
     * Reads the model's `aspect_ratio` options, or derives the ratios from its fixed `size` options.
     *
     * @since 1.0.0
     *
     * @param array<string, list<mixed>> $parameters Map of the model's parameter names to their options.
     * @return list<string> The aspect ratios, such as `16:9`.
     */
    private static function imageAspectRatios(array $parameters): array
    {
        $aspectRatios = [];
        foreach ($parameters['aspect_ratio'] ?? [] as $value) {
            if (is_string($value) && preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/', $value)) {
                $aspectRatios[] = $value;
            }
        }

        if (!$aspectRatios) {
            foreach ($parameters['size'] ?? [] as $value) {
                if (!is_string($value) || !preg_match('/^([1-9][0-9]*)x([1-9][0-9]*)$/', $value, $matches)) {
                    continue;
                }
                $width = (int) $matches[1];
                $height = (int) $matches[2];
                $divisor = self::greatestCommonDivisor($width, $height);
                $aspectRatios[] = ($width / $divisor) . ':' . ($height / $divisor);
            }
        }

        return array_values(array_unique($aspectRatios));
    }

    /**
     * Returns the media orientations that the given aspect ratios cover.
     *
     * @since 1.0.0
     *
     * @param list<string> $aspectRatios The aspect ratios.
     * @return list<MediaOrientationEnum> The orientations.
     */
    private static function imageOrientations(array $aspectRatios): array
    {
        $square = false;
        $landscape = false;
        $portrait = false;
        foreach ($aspectRatios as $aspectRatio) {
            [$width, $height] = array_map('intval', explode(':', $aspectRatio));
            if ($width === $height) {
                $square = true;
            } elseif ($width > $height) {
                $landscape = true;
            } else {
                $portrait = true;
            }
        }

        $orientations = [];
        if ($square) {
            $orientations[] = MediaOrientationEnum::square();
        }
        if ($landscape) {
            $orientations[] = MediaOrientationEnum::landscape();
        }
        if ($portrait) {
            $orientations[] = MediaOrientationEnum::portrait();
        }
        return $orientations;
    }

    /**
     * Returns the supported options of an embedding model.
     *
     * @since 1.0.0
     *
     * @param array<string, list<mixed>> $parameters Map of the model's parameter names to their options.
     * @return list<SupportedOption> The supported options.
     */
    private static function embeddingOptions(array $parameters): array
    {
        $options = [
            new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
        ];
        // The dimensions option arrived with embedding support in version 1.4.0 of the AI Client.
        if (isset($parameters['dimensions']) && OptionEnum::isValidValue('dimensions')) {
            $options[] = new SupportedOption(OptionEnum::dimensions());
        }
        $options[] = new SupportedOption(OptionEnum::customOptions());

        return $options;
    }

    /**
     * Returns the strings of a catalog list field.
     *
     * @since 1.0.0
     *
     * @param mixed $value The field value.
     * @return list<string> The strings in the list.
     */
    private static function stringList($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter($value, 'is_string'));
    }

    /**
     * Returns a map of a model's parameter names to their listed options.
     *
     * @since 1.0.0
     *
     * @param mixed $value The `supported_parameters` field.
     * @return array<string, list<mixed>> Map of parameter name to its options (empty when none are listed).
     */
    private static function parameterMap($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $parameters = [];
        foreach ($value as $parameter) {
            if (!is_array($parameter) || !isset($parameter['name']) || !is_string($parameter['name'])) {
                continue;
            }
            $parameters[$parameter['name']] = isset($parameter['options']) && is_array($parameter['options'])
                ? array_values($parameter['options'])
                : [];
        }
        return $parameters;
    }

    /**
     * Returns the greatest common divisor of two positive integers.
     *
     * @since 1.0.0
     *
     * @param int $a The first integer.
     * @param int $b The second integer.
     * @return int The greatest common divisor.
     */
    private static function greatestCommonDivisor(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }
        return $a;
    }
}
