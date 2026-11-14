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
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
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
 * @since 1.1.0 Maps text-to-speech and video generation models.
 *
 * @phpstan-type ParameterData array{name?: string, options?: list<mixed>, required?: bool}
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
     * Catalog endpoint that marks a model as a text-to-speech model.
     *
     * @since 1.1.0
     *
     * @var string
     */
    private const ENDPOINT_SPEECH = 'POST /v1/audio/speech';

    /**
     * Catalog endpoint that marks a model as a video generation model.
     *
     * @since 1.1.0
     *
     * @var string
     */
    private const ENDPOINT_VIDEOS = 'POST /v1/videos/generations';

    /**
     * Catalog categories whose chat models produce general text.
     *
     * @since 1.0.0
     *
     * @var list<string>
     */
    private const TEXT_CATEGORIES = ['text', 'research'];

    /**
     * Timeout in seconds for the request that lists the models.
     *
     * @since 1.0.0
     *
     * @var float
     */
    private const LIST_MODELS_TIMEOUT = 15.0;

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
     * Audio file formats, mapped to their MIME types.
     *
     * Raw sample encodings a model may also list have no file type of their own and are left to custom options.
     *
     * @since 1.1.0
     *
     * @var array<string, string>
     */
    public const AUDIO_FORMAT_MIME_TYPES = [
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'opus' => 'audio/ogg',
        'flac' => 'audio/flac',
        'aac' => 'audio/aac',
    ];

    /**
     * {@inheritDoc}
     *
     * A plugin update can change how catalog entries map to capabilities and options, so each plugin version
     * keeps its own cached model list.
     *
     * @since 1.1.0
     */
    protected function getBaseCacheKey(): string
    {
        return parent::getBaseCacheKey() . '_' . EmpirioLabsProvider::VERSION;
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     */
    protected function createRequest(HttpMethodEnum $method, string $path, array $headers = [], $data = null): Request
    {
        $headers['User-Agent'] = EmpirioLabsProvider::userAgent();

        // The full catalog is a large response, so allow more than the 5-second WordPress default.
        $options = new RequestOptions();
        $options->setTimeout(self::LIST_MODELS_TIMEOUT);

        return new Request(
            $method,
            EmpirioLabsProvider::url($path),
            $headers,
            $data,
            $options
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
            throw ResponseException::fromMissingData('EmpirioLabs AI', 'data');
        }
        if (!is_array($responseData['data'])) {
            throw ResponseException::fromInvalidData(
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
            ? sanitize_text_field($modelData['display_name'])
            : '';
        if ($name === '') {
            $name = $modelId;
        }

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

        $inputModalities = self::stringList($modelData['input_modalities'] ?? null);
        $requiredParameters = self::requiredParameterNames($modelData['supported_parameters'] ?? null);

        // A text-to-speech model takes the text in `input`, and nothing else the AI Client cannot provide.
        if (
            EmpirioLabsProvider::supportsTextToSpeechConversion() &&
            in_array(self::ENDPOINT_SPEECH, $endpoints, true) &&
            $category === 'audio' &&
            in_array('audio', $outputModalities, true) &&
            isset($parameters['input']) &&
            !array_diff($requiredParameters, ['input'])
        ) {
            // The audio format is a parameter of its own on some models and `response_format` on others.
            [$formatParameter, $formatValues] = self::outputFormats(
                $parameters,
                ['output_format', 'response_format'],
                self::AUDIO_FORMAT_MIME_TYPES
            );

            return new EmpirioLabsModelMetadata(
                $modelId,
                $name,
                [CapabilityEnum::textToSpeechConversion()],
                self::speechOptions($parameters, array_keys($formatValues)),
                $formatParameter,
                $formatValues
            );
        }

        /*
         * A model that generates a video from a description requires `prompt`. Models that transform an existing
         * clip, or that need an input the AI Client cannot provide, do not and are left out.
         */
        if (
            EmpirioLabsProvider::supportsVideoGeneration() &&
            in_array(self::ENDPOINT_VIDEOS, $endpoints, true) &&
            $category === 'video' &&
            in_array('video', $outputModalities, true) &&
            in_array('prompt', $requiredParameters, true) &&
            !array_diff($requiredParameters, ['prompt', 'image'])
        ) {
            $inputCombinations = self::videoInputModalityCombinations(
                isset($parameters['image']) && in_array('image', $inputModalities, true),
                in_array('image', $requiredParameters, true)
            );
            if (!$inputCombinations) {
                return null;
            }

            return new ModelMetadata(
                $modelId,
                $name,
                [CapabilityEnum::videoGeneration()],
                self::videoOptions($parameters, $inputCombinations)
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
            $orientations = self::mediaOrientations($aspectRatios);
            if ($orientations) {
                $options[] = new SupportedOption(OptionEnum::outputMediaOrientation(), $orientations);
            }
        }

        $options[] = new SupportedOption(OptionEnum::customOptions());

        return $options;
    }

    /**
     * Returns the supported options of a text-to-speech model.
     *
     * @since 1.1.0
     *
     * @param array<string, list<mixed>> $parameters Map of the model's parameter names to their options.
     * @param list<string> $mimeTypes The audio MIME types the model can return.
     * @return list<SupportedOption> The supported options.
     */
    private static function speechOptions(array $parameters, array $mimeTypes): array
    {
        $options = [
            new SupportedOption(OptionEnum::inputModalities(), [[ModalityEnum::text()]]),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::audio()]]),
            new SupportedOption(OptionEnum::outputFileType(), [FileTypeEnum::inline(), FileTypeEnum::remote()]),
        ];

        if ($mimeTypes) {
            $options[] = new SupportedOption(OptionEnum::outputMimeType(), $mimeTypes);
        }

        // The voices differ per model and an account can add its own, so any voice name is passed on.
        if (isset($parameters['voice'])) {
            $options[] = new SupportedOption(OptionEnum::outputSpeechVoice());
        }

        $options[] = new SupportedOption(OptionEnum::customOptions());

        return $options;
    }

    /**
     * Returns the supported options of a video generation model.
     *
     * A finished video is returned as a link to the file. The output MIME type is not an option: few models
     * let a request choose the file format, and the AI Client would run one of those in place of the preferred
     * model for any request that names a MIME type. A model's own format parameter is a custom option.
     *
     * @since 1.1.0
     *
     * @param array<string, list<mixed>> $parameters Map of the model's parameter names to their options.
     * @param list<list<ModalityEnum>> $inputCombinations The input modality combinations the model accepts.
     * @return list<SupportedOption> The supported options.
     */
    private static function videoOptions(array $parameters, array $inputCombinations): array
    {
        $options = [
            new SupportedOption(OptionEnum::inputModalities(), $inputCombinations),
            new SupportedOption(OptionEnum::outputModalities(), [[ModalityEnum::video()]]),
            new SupportedOption(OptionEnum::outputFileType(), [FileTypeEnum::remote()]),
        ];

        $aspectRatios = self::listedAspectRatios($parameters);
        if ($aspectRatios) {
            $options[] = new SupportedOption(OptionEnum::outputMediaAspectRatio(), $aspectRatios);
            $orientations = self::mediaOrientations($aspectRatios);
            if ($orientations) {
                $options[] = new SupportedOption(OptionEnum::outputMediaOrientation(), $orientations);
            }
        }

        $options[] = new SupportedOption(OptionEnum::customOptions());

        return $options;
    }

    /**
     * Returns the input modality combinations of a video generation model.
     *
     * @since 1.1.0
     *
     * @param bool $takesImage Whether the catalog lists image input and an `image` parameter for the model.
     * @param bool $requiresImage Whether the model requires the image.
     * @return list<list<ModalityEnum>> The supported combinations, empty if the AI Client cannot use the model.
     */
    private static function videoInputModalityCombinations(bool $takesImage, bool $requiresImage): array
    {
        $combinations = [];
        if (!$requiresImage) {
            $combinations[] = [ModalityEnum::text()];
        }
        if ($takesImage) {
            $combinations[] = [ModalityEnum::text(), ModalityEnum::image()];
        }
        return $combinations;
    }

    /**
     * Returns the parameter that sets a model's output format, and the values it lists by MIME type.
     *
     * @since 1.1.0
     *
     * @param array<string, list<mixed>> $parameters Map of the model's parameter names to their options.
     * @param list<string> $parameterNames The parameter names that can hold the output format, in order.
     * @param array<string, string> $formatMimeTypes Map of file format to MIME type.
     * @return array{0: string|null, 1: array<string, string>} The parameter name (null if the model lists no
     *                                                         known format) and the map of MIME type to the
     *                                                         value the catalog lists for it.
     */
    private static function outputFormats(array $parameters, array $parameterNames, array $formatMimeTypes): array
    {
        foreach ($parameterNames as $parameterName) {
            $values = [];
            foreach ($parameters[$parameterName] ?? [] as $format) {
                if (!is_string($format)) {
                    continue;
                }
                $mimeType = $formatMimeTypes[strtolower($format)] ?? null;
                if ($mimeType !== null && !isset($values[$mimeType])) {
                    $values[$mimeType] = $format;
                }
            }
            if ($values) {
                return [$parameterName, $values];
            }
        }

        return [null, []];
    }

    /**
     * Returns the aspect ratios a model lists in its `aspect_ratio` options.
     *
     * @since 1.1.0
     *
     * @param array<string, list<mixed>> $parameters Map of the model's parameter names to their options.
     * @return list<string> The aspect ratios, such as `16:9`.
     */
    private static function listedAspectRatios(array $parameters): array
    {
        $aspectRatios = [];
        foreach ($parameters['aspect_ratio'] ?? [] as $value) {
            if (is_string($value) && preg_match('/^[1-9][0-9]*:[1-9][0-9]*$/', $value)) {
                $aspectRatios[] = $value;
            }
        }

        return array_values(array_unique($aspectRatios));
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
        $aspectRatios = self::listedAspectRatios($parameters);

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
    private static function mediaOrientations(array $aspectRatios): array
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
     * Returns the names of the parameters a model requires in every request.
     *
     * @since 1.1.0
     *
     * @param mixed $value The `supported_parameters` field.
     * @return list<string> The names of the required parameters.
     */
    private static function requiredParameterNames($value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $names = [];
        foreach ($value as $parameter) {
            if (
                is_array($parameter) &&
                isset($parameter['name'], $parameter['required']) &&
                is_string($parameter['name']) &&
                $parameter['required'] === true
            ) {
                $names[] = $parameter['name'];
            }
        }
        return $names;
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
