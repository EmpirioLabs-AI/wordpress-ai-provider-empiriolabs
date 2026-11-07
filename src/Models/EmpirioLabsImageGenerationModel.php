<?php

declare(strict_types=1);

namespace EmpirioLabs\AiProvider\Models;

if (!defined('ABSPATH')) {
    exit;
}

use EmpirioLabs\AiProvider\Provider\EmpirioLabsProvider;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleImageGenerationModel;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * Class for an EmpirioLabs AI image generation model, using the images API.
 *
 * Requests are synchronous: the API holds the request until the images are ready and returns them in the
 * OpenAI images shape. A generation that outlasts the API's wait window comes back as a job, which this
 * class polls until it finishes.
 *
 * @since 1.0.0
 */
class EmpirioLabsImageGenerationModel extends AbstractOpenAiCompatibleImageGenerationModel
{
    /**
     * Seconds between two reads of a job that is still running.
     *
     * @since 1.0.0
     *
     * @var int
     */
    private const JOB_POLL_INTERVAL = 3;

    /**
     * Seconds to keep reading a running job when no request timeout is set.
     *
     * @since 1.0.0
     *
     * @var int
     */
    private const JOB_DEFAULT_WAIT = 120;

    /**
     * Landscape and portrait aspect ratios in order of preference, for a request that names only an orientation.
     *
     * @since 1.0.0
     *
     * @var array<string, list<string>>
     */
    private const ORIENTATION_ASPECT_RATIOS = [
        'landscape' => ['16:9', '3:2', '4:3', '7:4', '5:4', '21:9', '2:1'],
        'portrait' => ['9:16', '2:3', '3:4', '4:7', '4:5', '9:21', '1:2'],
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
            $data,
            $this->getRequestOptions()
        );
    }

    /**
     * {@inheritDoc}
     *
     * Asks the API to answer synchronously and sends the shape of the image as `aspect_ratio`, which every
     * image model accepts.
     *
     * @since 1.0.0
     */
    protected function prepareGenerateImageParams(array $prompt): array
    {
        $params = parent::prepareGenerateImageParams($prompt);

        // The parent stores the value of prepareSizeParam() as `size` when an orientation or aspect ratio is set.
        $config = $this->getConfig();
        $hasShape = $config->getOutputMediaOrientation() !== null || $config->getOutputMediaAspectRatio() !== null;
        if ($hasShape && isset($params['size']) && !isset($params['aspect_ratio'])) {
            $params['aspect_ratio'] = $params['size'];
            unset($params['size']);
        }

        if (!isset($params['sync'])) {
            $params['sync'] = true;
        }

        return $params;
    }

    /**
     * {@inheritDoc}
     *
     * Returns an aspect ratio, such as `16:9`, from the ratios the model accepts.
     *
     * @since 1.0.0
     */
    protected function prepareSizeParam(?MediaOrientationEnum $orientation, ?string $aspectRatio): string
    {
        $supportedAspectRatios = $this->getSupportedAspectRatios();

        if ($aspectRatio !== null) {
            if ($supportedAspectRatios && !in_array($aspectRatio, $supportedAspectRatios, true)) {
                throw new InvalidArgumentException(
                    'The aspect ratio "' . esc_html($aspectRatio) . '" is not supported by this model.'
                );
            }
            return $aspectRatio;
        }

        if ($orientation !== null && !$orientation->isSquare()) {
            $key = $orientation->isLandscape() ? 'landscape' : 'portrait';
            foreach (self::ORIENTATION_ASPECT_RATIOS[$key] as $candidate) {
                if (!$supportedAspectRatios || in_array($candidate, $supportedAspectRatios, true)) {
                    return $candidate;
                }
            }
        }

        return '1:1';
    }

    /**
     * {@inheritDoc}
     *
     * Reads a job to its end when the generation outlasted the API's synchronous wait.
     *
     * @since 1.0.0
     */
    protected function parseResponseToGenerativeAiResult(
        Response $response,
        string $expectedMimeType = 'image/png'
    ): GenerativeAiResult {
        $responseData = $response->getData();
        if (
            is_array($responseData) &&
            !isset($responseData['data']) &&
            isset($responseData['job_id']) &&
            is_string($responseData['job_id'])
        ) {
            $response = $this->waitForJob($responseData['job_id']);
        }

        return parent::parseResponseToGenerativeAiResult($response, $expectedMimeType);
    }

    /**
     * {@inheritDoc}
     *
     * Uses the MIME type the API reports for each image.
     *
     * @since 1.0.0
     */
    protected function parseResponseChoiceToCandidate(
        array $choiceData,
        int $index,
        string $expectedMimeType = 'image/png'
    ): Candidate {
        if (
            isset($choiceData['content_type']) &&
            is_string($choiceData['content_type']) &&
            preg_match('#^image/[a-z0-9.+-]+$#', $choiceData['content_type'])
        ) {
            $expectedMimeType = $choiceData['content_type'];
        }

        return parent::parseResponseChoiceToCandidate($choiceData, $index, $expectedMimeType);
    }

    /**
     * Reads a job until it finishes and returns its result as a response.
     *
     * @since 1.0.0
     *
     * @param string $jobId The job ID.
     * @return Response The response whose data is the job's result.
     *
     * @throws ResponseException If the job fails, is cancelled, or does not finish in time.
     */
    private function waitForJob(string $jobId): Response
    {
        $requestOptions = $this->getRequestOptions();
        $timeout = $requestOptions !== null ? $requestOptions->getTimeout() : null;
        $deadline = time() + (int) ceil($timeout !== null && $timeout > 0 ? $timeout : self::JOB_DEFAULT_WAIT);

        $pollOptions = new RequestOptions();
        $pollOptions->setTimeout(30.0);

        while (true) {
            $request = new Request(
                HttpMethodEnum::GET(),
                EmpirioLabsProvider::url('jobs/' . rawurlencode($jobId)),
                ['User-Agent' => EmpirioLabsProvider::userAgent()],
                null,
                $pollOptions
            );
            $request = $this->getRequestAuthentication()->authenticateRequest($request);
            $response = $this->getHttpTransporter()->send($request);
            $this->throwIfNotSuccessful($response);

            $jobData = $response->getData();
            $status = is_array($jobData) && isset($jobData['status']) && is_string($jobData['status'])
                ? $jobData['status']
                : '';

            if ($status === 'completed' && isset($jobData['result']) && is_array($jobData['result'])) {
                return new Response(
                    $response->getStatusCode(),
                    $response->getHeaders(),
                    (string) wp_json_encode($jobData['result'])
                );
            }

            if (in_array($status, ['failed', 'cancelled', 'canceled', 'expired'], true)) {
                $message = 'The image generation did not finish.';
                if (isset($jobData['error']['message']) && is_string($jobData['error']['message'])) {
                    $message = $jobData['error']['message'];
                } elseif (isset($jobData['error']) && is_string($jobData['error'])) {
                    $message = $jobData['error'];
                }
                throw ResponseException::fromInvalidData('EmpirioLabs AI', 'status', esc_html($message));
            }

            if (time() + self::JOB_POLL_INTERVAL > $deadline) {
                throw ResponseException::fromInvalidData(
                    'EmpirioLabs AI',
                    'status',
                    'The image is still being generated. Job ID: ' . esc_html($jobId)
                );
            }

            sleep(self::JOB_POLL_INTERVAL);
        }
    }

    /**
     * Returns the aspect ratios the model accepts, as listed in its metadata.
     *
     * @since 1.0.0
     *
     * @return list<string> The aspect ratios, or an empty list if the model lists none.
     */
    private function getSupportedAspectRatios(): array
    {
        foreach ($this->metadata()->getSupportedOptions() as $supportedOption) {
            if ($supportedOption->getName()->value !== OptionEnum::outputMediaAspectRatio()->value) {
                continue;
            }
            $values = $supportedOption->getSupportedValues();
            return is_array($values) ? array_values(array_filter($values, 'is_string')) : [];
        }
        return [];
    }
}
