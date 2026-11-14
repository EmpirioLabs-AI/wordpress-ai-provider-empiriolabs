<?php

declare(strict_types=1);

namespace EmpirioLabs\AiProvider\Models;

if (!defined('ABSPATH')) {
    exit;
}

use EmpirioLabs\AiProvider\Operations\EmpirioLabsGenerativeAiOperation;
use EmpirioLabs\AiProvider\Operations\EmpirioLabsOperationsHandler;
use Exception;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Operations\DTO\GenerativeAiOperation;
use WordPress\AiClient\Operations\Enums\OperationStateEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ClientException;
use WordPress\AiClient\Providers\Http\Exception\NetworkException;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Exception\ServerException;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\Models\VideoGeneration\Contracts\VideoGenerationModelInterface;
use WordPress\AiClient\Providers\Models\VideoGeneration\Contracts\VideoGenerationOperationModelInterface;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * Class for an EmpirioLabs AI video generation model, using the videos API.
 *
 * A video takes minutes, so the API accepts the request as a job. `generateVideoOperation()` returns that job
 * as an operation at once; read it later with the provider's operations handler. `generateVideoResult()` waits
 * for the job instead, for as long as the request timeout allows.
 *
 * @since 1.1.0
 */
class EmpirioLabsVideoGenerationModel extends AbstractApiBasedModel implements
    VideoGenerationModelInterface,
    VideoGenerationOperationModelInterface
{
    use EmpirioLabsMediaModelTrait;

    /**
     * Seconds between two reads of a job that is still running.
     *
     * @since 1.1.0
     *
     * @var int
     */
    private const JOB_POLL_INTERVAL = 5;

    /**
     * Seconds to wait for a video when no request timeout is set.
     *
     * @since 1.1.0
     *
     * @var int
     */
    private const JOB_DEFAULT_WAIT = 900;

    /**
     * Aspect ratios in order of preference, for a request that names only an orientation.
     *
     * @since 1.1.0
     *
     * @var array<string, list<string>>
     */
    private const ORIENTATION_ASPECT_RATIOS = [
        'square' => ['1:1'],
        'landscape' => ['16:9', '3:2', '4:3', '21:9'],
        'portrait' => ['9:16', '2:3', '3:4', '9:21'],
    ];

    /**
     * {@inheritDoc}
     *
     * Starts the video and returns without waiting for it. The operation ID stays valid across requests: pass it
     * to `EmpirioLabsProvider::operationsHandler()->getOperation()` to read the state and, once the video is
     * ready, the result.
     *
     * @since 1.1.0
     */
    public function generateVideoOperation(array $prompt): GenerativeAiOperation
    {
        $request = $this->createRequest(
            HttpMethodEnum::POST(),
            'videos/generations',
            [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            $this->prepareGenerateVideoParams($prompt)
        );
        $responseData = $this->sendRequest($request)->getData();

        if (
            !isset($responseData['job_id']) ||
            !is_string($responseData['job_id']) ||
            $responseData['job_id'] === ''
        ) {
            throw ResponseException::fromMissingData('EmpirioLabs AI', 'job_id');
        }

        // The API accepts a video as a running job. Any other answer is read from the job itself.
        if (!isset($responseData['status']) || $responseData['status'] !== 'processing') {
            try {
                return $this->readOperation($responseData['job_id']);
            } catch (Exception $e) {
                // The video was accepted, so its operation ID is returned in any case; the next read reports it.
                unset($e);
            }
        }

        return new EmpirioLabsGenerativeAiOperation($responseData['job_id'], OperationStateEnum::processing());
    }

    /**
     * {@inheritDoc}
     *
     * Starts the video and waits until it is ready, for as long as the request timeout allows. A video that
     * needs longer keeps running: the error names its operation ID, which reads the result later.
     *
     * @since 1.1.0
     */
    public function generateVideoResult(array $prompt): GenerativeAiResult
    {
        // The wait covers the whole call, from the request that starts the video.
        $requestOptions = $this->getRequestOptions();
        $timeout = $requestOptions !== null ? $requestOptions->getTimeout() : null;
        $deadline = microtime(true) + ($timeout !== null && $timeout > 0 ? $timeout : self::JOB_DEFAULT_WAIT);

        $operation = $this->generateVideoOperation($prompt);

        while (true) {
            $state = $operation->getState();

            if ($state->isSucceeded()) {
                $result = $operation->getResult();
                if ($result === null) {
                    throw ResponseException::fromMissingData('EmpirioLabs AI', 'result');
                }
                return $result;
            }

            if ($state->isFailed()) {
                $message = $operation instanceof EmpirioLabsGenerativeAiOperation
                    ? $operation->getErrorMessage()
                    : null;
                throw new ResponseException(esc_html($message ?? 'The video generation did not finish.'));
            }

            if ($state->isCanceled()) {
                throw new ResponseException('The video generation was cancelled.');
            }

            if (microtime(true) + self::JOB_POLL_INTERVAL > $deadline) {
                throw new ResponseException(
                    'The video is still being generated. Read its result later with the operation ID "'
                    . esc_html($operation->getId())
                    . '", or raise the request timeout to wait longer.'
                );
            }

            sleep(self::JOB_POLL_INTERVAL);

            try {
                // A read is given no longer than the time that is left.
                $operation = $this->readOperation($operation->getId(), max(1.0, $deadline - microtime(true)));
            } catch (Exception $e) {
                // The video keeps running. A read that may work the next time is tried again until the wait ends.
                if (!self::isTemporaryFailure($e)) {
                    throw new ResponseException(
                        'The video was started with the operation ID "' . esc_html($operation->getId())
                        . '", but its state could not be read: ' . esc_html($e->getMessage())
                    );
                }
            }
        }
    }

    /**
     * Checks whether reading a running video failed in a way that may not happen again.
     *
     * @since 1.1.0
     *
     * @param Exception $exception The exception that the read raised.
     * @return bool True for a network failure, a server error, or a rate limit.
     */
    private static function isTemporaryFailure(Exception $exception): bool
    {
        return $exception instanceof NetworkException
            || $exception instanceof ServerException
            || ($exception instanceof ClientException && $exception->getCode() === 429);
    }

    /**
     * Prepares the given prompt and the model configuration into parameters for the API request.
     *
     * @since 1.1.0
     *
     * @param list<Message> $prompt The prompt to generate a video for.
     * @return array<string, mixed> The parameters for the API request.
     */
    protected function prepareGenerateVideoParams(array $prompt): array
    {
        $config = $this->getConfig();
        [$text, $image] = $this->preparePromptParts($prompt);

        $params = [
            'model' => $this->metadata()->getId(),
            'prompt' => $text,
        ];
        if ($image !== null) {
            $params['image'] = $image;
        }

        $orientation = $config->getOutputMediaOrientation();
        $aspectRatio = $config->getOutputMediaAspectRatio();
        if ($orientation !== null || $aspectRatio !== null) {
            $params['aspect_ratio'] = $this->prepareAspectRatioParam($orientation, $aspectRatio);
        }

        // The file format of a video is a parameter of the model, where it has one, and goes in a custom option.
        $mimeType = $config->getOutputMimeType();
        if ($mimeType !== null && $mimeType !== '') {
            throw new InvalidArgumentException(
                'The output MIME type of a video cannot be set. Pass the format parameter of the model as a custom '
                . 'option instead.'
            );
        }

        return $this->addCustomOptions($params);
    }

    /**
     * Extracts the description and the optional source image from the prompt.
     *
     * @since 1.1.0
     *
     * @param list<Message> $prompt The prompt messages.
     * @return array{0: string, 1: string|null} The description, and the link of the image.
     *
     * @throws InvalidArgumentException If the prompt is not one user message with text and at most one linked
     *                                  image.
     */
    protected function preparePromptParts(array $prompt): array
    {
        if (count($prompt) !== 1) {
            throw new InvalidArgumentException('The API requires a single user message as prompt.');
        }
        $message = $prompt[0];
        if (!$message->getRole()->isUser()) {
            throw new InvalidArgumentException('The API requires a user message as prompt.');
        }

        $text = '';
        $image = null;
        foreach ($message->getParts() as $part) {
            $partText = $part->getText();
            if ($partText !== null) {
                $text .= ($text === '' ? '' : "\n") . $partText;
                continue;
            }

            $file = $part->getFile();
            if ($file === null) {
                continue;
            }
            if (!$file->isImage() || $image !== null) {
                throw new InvalidArgumentException('Video generation takes a description and at most one image.');
            }
            if (!$this->supportsImageInput()) {
                throw new InvalidArgumentException('This model does not take an image.');
            }
            // The videos API takes the source image as a link.
            if (!$file->isRemote()) {
                throw new InvalidArgumentException(
                    'Video generation takes the source image as a link. Pass the URL of the image.'
                );
            }
            $image = (string) $file->getUrl();
        }

        if (trim($text) === '') {
            throw new InvalidArgumentException('The prompt must contain a description of the video.');
        }

        return [$text, $image];
    }

    /**
     * Returns the aspect ratio to request, from the ratios the model accepts.
     *
     * @since 1.1.0
     *
     * @param MediaOrientationEnum|null $orientation The desired media orientation.
     * @param string|null               $aspectRatio The desired media aspect ratio.
     * @return string The aspect ratio, such as `16:9`.
     *
     * @throws InvalidArgumentException If the model does not accept the aspect ratio or orientation.
     */
    protected function prepareAspectRatioParam(?MediaOrientationEnum $orientation, ?string $aspectRatio): string
    {
        $supportedAspectRatios = $this->getSupportedAspectRatios();
        if (!$supportedAspectRatios) {
            throw new InvalidArgumentException('This model does not take an aspect ratio.');
        }

        // The aspect ratio is the more specific setting.
        if ($aspectRatio !== null) {
            if (!in_array($aspectRatio, $supportedAspectRatios, true)) {
                throw new InvalidArgumentException(
                    'The aspect ratio "' . esc_html($aspectRatio) . '" is not supported by this model.'
                );
            }
            return $aspectRatio;
        }

        $key = 'portrait';
        if ($orientation === null || $orientation->isSquare()) {
            $key = 'square';
        } elseif ($orientation->isLandscape()) {
            $key = 'landscape';
        }

        foreach (self::ORIENTATION_ASPECT_RATIOS[$key] as $candidate) {
            if (in_array($candidate, $supportedAspectRatios, true)) {
                return $candidate;
            }
        }
        foreach ($supportedAspectRatios as $candidate) {
            [$width, $height] = array_map('intval', explode(':', $candidate));
            if (
                ($key === 'square' && $width === $height) ||
                ($key === 'landscape' && $width > $height) ||
                ($key === 'portrait' && $width < $height)
            ) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException(
            'The ' . esc_html($key) . ' orientation is not supported by this model.'
        );
    }

    /**
     * Reads the state of a video that was started.
     *
     * @since 1.1.0
     *
     * @param string     $operationId The operation ID.
     * @param float|null $timeout     Timeout in seconds for the request, if shorter than the default.
     * @return EmpirioLabsGenerativeAiOperation The operation.
     */
    private function readOperation(string $operationId, ?float $timeout = null): EmpirioLabsGenerativeAiOperation
    {
        return EmpirioLabsOperationsHandler::readOperation(
            $operationId,
            $this->getHttpTransporter(),
            $this->getRequestAuthentication(),
            $this->metadata(),
            $timeout
        );
    }

    /**
     * Returns the aspect ratios the model accepts, as listed in its metadata.
     *
     * @since 1.1.0
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

    /**
     * Checks whether the model lists an image as input.
     *
     * @since 1.1.0
     *
     * @return bool True if the model takes a source image.
     */
    private function supportsImageInput(): bool
    {
        foreach ($this->metadata()->getSupportedOptions() as $supportedOption) {
            if ($supportedOption->getName()->value !== OptionEnum::inputModalities()->value) {
                continue;
            }
            foreach ($supportedOption->getSupportedValues() ?? [] as $combination) {
                foreach (is_array($combination) ? $combination : [] as $modality) {
                    if ($modality instanceof ModalityEnum && $modality->isImage()) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
