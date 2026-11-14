<?php

declare(strict_types=1);

namespace EmpirioLabs\AiProvider\Operations;

if (!defined('ABSPATH')) {
    exit;
}

use EmpirioLabs\AiProvider\Files\EmpirioLabsFileFactory;
use EmpirioLabs\AiProvider\Provider\EmpirioLabsProvider;
use Exception;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Files\ValueObjects\MimeType;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Operations\Contracts\OperationInterface;
use WordPress\AiClient\Operations\Enums\OperationStateEnum;
use WordPress\AiClient\Providers\Contracts\ProviderOperationsHandlerInterface;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\RequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithHttpTransporterInterface;
use WordPress\AiClient\Providers\Http\Contracts\WithRequestAuthenticationInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Traits\WithHttpTransporterTrait;
use WordPress\AiClient\Providers\Http\Traits\WithRequestAuthenticationTrait;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Reads generations that run in the background on EmpirioLabs AI.
 *
 * A long generation, such as a video, is accepted as a job. The job ID is the operation ID: this handler reads
 * the job and returns it as an operation, with the generated files once the job has finished.
 *
 * @since 1.1.0
 *
 * @phpstan-type ItemData array{url?: string, b64_json?: string, content_type?: string}
 * @phpstan-type ResultData array{data?: list<ItemData>, usage?: array<string, mixed>}
 * @phpstan-type JobData array{
 *     job_id?: string,
 *     status?: string,
 *     endpoint?: string|null,
 *     model?: string|null,
 *     result?: ResultData|null,
 *     error?: array{message?: string}|string|null
 * }
 */
class EmpirioLabsOperationsHandler implements
    ProviderOperationsHandlerInterface,
    WithHttpTransporterInterface,
    WithRequestAuthenticationInterface
{
    use WithHttpTransporterTrait;
    use WithRequestAuthenticationTrait;

    /**
     * Job statuses, mapped to the operation states of the AI Client.
     *
     * @since 1.1.0
     *
     * @var array<string, string>
     */
    private const STATUS_STATES = [
        'queued' => OperationStateEnum::STARTING,
        'processing' => OperationStateEnum::PROCESSING,
        'running' => OperationStateEnum::PROCESSING,
        'completed' => OperationStateEnum::SUCCEEDED,
        'succeeded' => OperationStateEnum::SUCCEEDED,
        'failed' => OperationStateEnum::FAILED,
        'cancelled' => OperationStateEnum::CANCELED,
        'canceled' => OperationStateEnum::CANCELED,
    ];

    /**
     * MIME types of the files a job returns, by the kind of endpoint that accepted it, for a file that names
     * none.
     *
     * @since 1.1.0
     *
     * @var array<string, string>
     */
    private const ENDPOINT_MIME_TYPES = [
        '/v1/videos/' => 'video/mp4',
        '/v1/images/' => 'image/png',
        '/v1/audio/' => 'audio/mpeg',
    ];

    /**
     * {@inheritDoc}
     *
     * @since 1.1.0
     *
     * @param string $operationId The operation ID, as returned when the generation was started.
     * @return OperationInterface The operation, an instance of EmpirioLabsGenerativeAiOperation.
     *
     * @throws InvalidArgumentException If no operation with the ID exists for the account.
     */
    public function getOperation(string $operationId): OperationInterface
    {
        return self::readOperation(
            $operationId,
            $this->getHttpTransporter(),
            $this->getRequestAuthentication()
        );
    }

    /**
     * Reads a job and returns it as an operation.
     *
     * @since 1.1.0
     *
     * @param string                         $operationId           The operation ID.
     * @param HttpTransporterInterface       $httpTransporter       The HTTP transporter.
     * @param RequestAuthenticationInterface $requestAuthentication The request authentication.
     * @param ModelMetadata|null             $modelMetadata         The metadata of the model that runs the job,
     *                                                              if the caller has it.
     * @param float|null                     $timeout               Timeout in seconds for the request, if
     *                                                              shorter than the default.
     * @return EmpirioLabsGenerativeAiOperation The operation.
     *
     * @throws InvalidArgumentException If no operation with the ID exists for the account.
     * @throws ResponseException If the job cannot be read as an operation.
     */
    public static function readOperation(
        string $operationId,
        HttpTransporterInterface $httpTransporter,
        RequestAuthenticationInterface $requestAuthentication,
        ?ModelMetadata $modelMetadata = null,
        ?float $timeout = null
    ): EmpirioLabsGenerativeAiOperation {
        if ($operationId === '') {
            throw new InvalidArgumentException('The operation ID must not be empty.');
        }

        $options = new RequestOptions();
        $options->setTimeout(
            $timeout !== null && $timeout > 0
                ? min($timeout, EmpirioLabsProvider::DEFAULT_REQUEST_TIMEOUT)
                : EmpirioLabsProvider::DEFAULT_REQUEST_TIMEOUT
        );

        $request = new Request(
            HttpMethodEnum::GET(),
            EmpirioLabsProvider::url('jobs/' . rawurlencode($operationId)),
            [
                'Accept' => 'application/json',
                'User-Agent' => EmpirioLabsProvider::userAgent(),
            ],
            null,
            $options
        );
        $request = $requestAuthentication->authenticateRequest($request);
        $response = $httpTransporter->send($request);

        if ($response->getStatusCode() === 404) {
            throw new InvalidArgumentException(
                'No operation with the ID "' . esc_html($operationId) . '" was found.'
            );
        }
        ResponseUtil::throwIfNotSuccessful($response);

        $jobData = $response->getData();
        if (!is_array($jobData)) {
            throw ResponseException::fromMissingData('EmpirioLabs AI', 'status');
        }

        return self::createOperation($operationId, $jobData, $modelMetadata);
    }

    /**
     * Creates an operation from the data of a job.
     *
     * @since 1.1.0
     *
     * @param string               $operationId   The operation ID.
     * @param array<string, mixed> $jobData       The job, as the API returns it.
     * @param ModelMetadata|null   $modelMetadata The metadata of the model that runs the job, if the caller has
     *                                            it.
     * @return EmpirioLabsGenerativeAiOperation The operation.
     *
     * @throws ResponseException If the job has no known status, or has finished without a file.
     */
    public static function createOperation(
        string $operationId,
        array $jobData,
        ?ModelMetadata $modelMetadata = null
    ): EmpirioLabsGenerativeAiOperation {
        if (!isset($jobData['status']) || !is_string($jobData['status'])) {
            throw ResponseException::fromMissingData('EmpirioLabs AI', 'status');
        }

        // A status this plugin does not know is not treated as still running, so a caller never waits on it.
        $status = strtolower($jobData['status']);
        if (!isset(self::STATUS_STATES[$status])) {
            throw ResponseException::fromInvalidData(
                'EmpirioLabs AI',
                'status',
                'The job status is not known.'
            );
        }
        $state = OperationStateEnum::from(self::STATUS_STATES[$status]);

        if ($state->isSucceeded()) {
            return new EmpirioLabsGenerativeAiOperation(
                $operationId,
                $state,
                self::createResult($operationId, $jobData, $modelMetadata)
            );
        }

        if ($state->isFailed()) {
            return new EmpirioLabsGenerativeAiOperation(
                $operationId,
                $state,
                null,
                self::errorMessage($jobData)
            );
        }

        return new EmpirioLabsGenerativeAiOperation($operationId, $state);
    }

    /**
     * Creates the result of a finished job.
     *
     * @since 1.1.0
     *
     * @param string               $operationId   The operation ID.
     * @param array<string, mixed> $jobData       The job, as the API returns it.
     * @param ModelMetadata|null   $modelMetadata The metadata of the model that ran the job, if the caller has
     *                                            it.
     * @return GenerativeAiResult The result, with one candidate per generated file.
     *
     * @throws ResponseException If the job holds no file.
     */
    private static function createResult(
        string $operationId,
        array $jobData,
        ?ModelMetadata $modelMetadata
    ): GenerativeAiResult {
        /** @var ResultData $resultData */
        $resultData = isset($jobData['result']) && is_array($jobData['result']) ? $jobData['result'] : [];
        if (!isset($resultData['data']) || !is_array($resultData['data']) || !$resultData['data']) {
            throw ResponseException::fromMissingData('EmpirioLabs AI', 'result.data');
        }

        $defaultMimeType = self::defaultMimeType($jobData['endpoint'] ?? null);

        $candidates = [];
        foreach (array_values($resultData['data']) as $index => $itemData) {
            if (!is_array($itemData)) {
                throw ResponseException::fromInvalidData(
                    'EmpirioLabs AI',
                    'result.data[' . (int) $index . ']',
                    'The value must be an associative array.'
                );
            }
            $candidates[] = new Candidate(
                new Message(
                    MessageRoleEnum::model(),
                    [new MessagePart(self::createFile($itemData, (int) $index, $defaultMimeType))]
                ),
                FinishReasonEnum::stop()
            );
        }

        $usage = isset($resultData['usage']) && is_array($resultData['usage']) ? $resultData['usage'] : [];
        $promptTokens = isset($usage['prompt_tokens']) && is_int($usage['prompt_tokens'])
            ? $usage['prompt_tokens']
            : 0;
        $completionTokens = isset($usage['completion_tokens']) && is_int($usage['completion_tokens'])
            ? $usage['completion_tokens']
            : 0;

        // Token counts do not describe a generated file, so the job's own usage stays in the additional data.
        $additionalData = $resultData;
        unset($additionalData['data']);

        return new GenerativeAiResult(
            $operationId,
            $candidates,
            new TokenUsage(
                $promptTokens,
                $completionTokens,
                isset($usage['total_tokens']) && is_int($usage['total_tokens'])
                    ? $usage['total_tokens']
                    : $promptTokens + $completionTokens
            ),
            EmpirioLabsProvider::metadata(),
            $modelMetadata ?? self::resolveModelMetadata($jobData),
            $additionalData
        );
    }

    /**
     * Creates the file of one entry of a finished job.
     *
     * @since 1.1.0
     *
     * @param array<string, mixed> $itemData        The entry.
     * @param int                  $index           The position of the entry.
     * @param string               $defaultMimeType The MIME type to use if the entry and its link name none.
     * @return File The generated file, as a link or as inline data.
     *
     * @throws ResponseException If the entry holds neither a link nor inline data.
     */
    private static function createFile(array $itemData, int $index, string $defaultMimeType): File
    {
        $mimeType = null;
        if (isset($itemData['content_type']) && is_string($itemData['content_type'])) {
            $contentType = strtolower(trim(explode(';', $itemData['content_type'])[0]));
            if (preg_match('#^(?:audio|image|video)/[a-z0-9.+-]+$#', $contentType)) {
                $mimeType = $contentType;
            }
        }

        if (isset($itemData['url']) && is_string($itemData['url']) && $itemData['url'] !== '') {
            return EmpirioLabsFileFactory::fromLink(
                $itemData['url'],
                $mimeType ?? self::mimeTypeOfUrl($itemData['url']) ?? $defaultMimeType,
                'result.data[' . (int) $index . '].url'
            );
        }

        if (isset($itemData['b64_json']) && is_string($itemData['b64_json']) && $itemData['b64_json'] !== '') {
            return EmpirioLabsFileFactory::fromBase64(
                $itemData['b64_json'],
                $mimeType ?? $defaultMimeType,
                'result.data[' . (int) $index . '].b64_json'
            );
        }

        throw ResponseException::fromInvalidData(
            'EmpirioLabs AI',
            'result.data[' . (int) $index . ']',
            'The value must contain either a url or b64_json key with a string value.'
        );
    }

    /**
     * Returns the MIME type that the file extension of a link names.
     *
     * @since 1.1.0
     *
     * @param string $url The link.
     * @return string|null The MIME type, or null if the link has no known extension.
     */
    private static function mimeTypeOfUrl(string $url): ?string
    {
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if ($extension === '') {
            return null;
        }

        try {
            return (string) MimeType::fromExtension($extension);
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Returns the MIME type to assume for the files of a job, from the endpoint that accepted it.
     *
     * @since 1.1.0
     *
     * @param mixed $endpoint The job's `endpoint` field.
     * @return string The MIME type.
     */
    private static function defaultMimeType($endpoint): string
    {
        if (is_string($endpoint)) {
            foreach (self::ENDPOINT_MIME_TYPES as $prefix => $mimeType) {
                if (strpos($endpoint, $prefix) === 0) {
                    return $mimeType;
                }
            }
        }
        return 'application/octet-stream';
    }

    /**
     * Returns the metadata of the model that ran a job.
     *
     * @since 1.1.0
     *
     * @param array<string, mixed> $jobData The job, as the API returns it.
     * @return ModelMetadata The model's metadata from the catalog, or its ID alone if the catalog cannot be read.
     */
    private static function resolveModelMetadata(array $jobData): ModelMetadata
    {
        $modelId = isset($jobData['model']) && is_string($jobData['model']) ? $jobData['model'] : '';

        if ($modelId !== '') {
            try {
                $directory = EmpirioLabsProvider::modelMetadataDirectory();
                if ($directory->hasModelMetadata($modelId)) {
                    return $directory->getModelMetadata($modelId);
                }
            } catch (Exception $e) {
                // The files are returned even when the catalog cannot be read.
                unset($e);
            }
        }

        return new ModelMetadata($modelId, $modelId, [], []);
    }

    /**
     * Returns why a job failed.
     *
     * @since 1.1.0
     *
     * @param array<string, mixed> $jobData The job, as the API returns it.
     * @return string The reason.
     */
    private static function errorMessage(array $jobData): string
    {
        if (
            isset($jobData['error']) &&
            is_array($jobData['error']) &&
            isset($jobData['error']['message']) &&
            is_string($jobData['error']['message']) &&
            $jobData['error']['message'] !== ''
        ) {
            return $jobData['error']['message'];
        }
        if (isset($jobData['error']) && is_string($jobData['error']) && $jobData['error'] !== '') {
            return $jobData['error'];
        }
        return 'The generation did not finish.';
    }
}
