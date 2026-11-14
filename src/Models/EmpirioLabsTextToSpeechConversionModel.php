<?php

declare(strict_types=1);

namespace EmpirioLabs\AiProvider\Models;

if (!defined('ABSPATH')) {
    exit;
}

use EmpirioLabs\AiProvider\Files\EmpirioLabsFileFactory;
use EmpirioLabs\AiProvider\Metadata\EmpirioLabsModelMetadataDirectory;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\Models\TextToSpeechConversion\Contracts\TextToSpeechConversionModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Class for an EmpirioLabs AI text-to-speech model, using the speech API.
 *
 * The API answers with JSON that links the audio or holds it inline, or with the audio file itself. This class
 * asks for the form that fits the requested output and reads all three.
 *
 * @since 1.1.0
 *
 * @phpstan-type ClipData array{url?: string, b64_json?: string, content_type?: string, format?: string}
 * @phpstan-type ResponseData array{id?: string, data?: list<ClipData>, usage?: array<string, mixed>}
 */
class EmpirioLabsTextToSpeechConversionModel extends AbstractApiBasedModel implements
    TextToSpeechConversionModelInterface
{
    use EmpirioLabsMediaModelTrait;

    /**
     * MIME type of audio that names no type of its own.
     *
     * @since 1.1.0
     *
     * @var string
     */
    private const DEFAULT_MIME_TYPE = 'audio/mpeg';

    /**
     * {@inheritDoc}
     *
     * @since 1.1.0
     */
    public function convertTextToSpeechResult(array $prompt): GenerativeAiResult
    {
        $config = $this->getConfig();
        $params = $this->prepareConvertParams($prompt);

        $accept = 'application/json';
        $outputFileType = $config->getOutputFileType();
        if ($outputFileType === null || !$outputFileType->isRemote()) {
            if (!isset($params['response_format'])) {
                $params['response_format'] = 'b64_json';
            } else {
                // `response_format` names the audio format of this request, so the audio file itself is requested.
                $accept = 'application/octet-stream';
            }
        }

        $request = $this->createRequest(
            HttpMethodEnum::POST(),
            'audio/speech',
            [
                'Content-Type' => 'application/json',
                'Accept' => $accept,
            ],
            $params
        );

        return $this->parseResponseToGenerativeAiResult(
            $this->sendRequest($request),
            $config->getOutputMimeType(),
            $outputFileType !== null && $outputFileType->isRemote()
        );
    }

    /**
     * Prepares the given prompt and the model configuration into parameters for the API request.
     *
     * @since 1.1.0
     *
     * @param list<Message> $prompt The prompt messages containing the text to speak.
     * @return array<string, mixed> The parameters for the API request.
     */
    protected function prepareConvertParams(array $prompt): array
    {
        $config = $this->getConfig();

        $params = [
            'model' => $this->metadata()->getId(),
            'input' => $this->preparePromptText($prompt),
        ];

        // Without a voice the model speaks with its default voice.
        $voice = $config->getOutputSpeechVoice();
        if ($voice !== null && $voice !== '') {
            if (!$this->supportsOption(OptionEnum::outputSpeechVoice())) {
                throw new InvalidArgumentException('This model does not take a voice.');
            }
            $params['voice'] = $voice;
        }

        // Without an output MIME type the model returns its default audio format.
        $mimeType = $config->getOutputMimeType();
        if ($mimeType !== null && $mimeType !== '') {
            [$parameter, $value] = $this->prepareOutputFormatParam($mimeType);
            $params[$parameter] = $value;
        }

        return $this->addCustomOptions($params);
    }

    /**
     * Extracts the text to speak from the prompt.
     *
     * The speech API takes one block of text, so the text parts of the prompt are joined.
     *
     * @since 1.1.0
     *
     * @param list<Message> $prompt The prompt messages.
     * @return string The text to convert to speech.
     *
     * @throws InvalidArgumentException If the prompt contains no text.
     */
    protected function preparePromptText(array $prompt): string
    {
        $text = '';
        foreach ($prompt as $message) {
            foreach ($message->getParts() as $part) {
                $partText = $part->getText();
                if ($partText !== null) {
                    $text .= ($text === '' ? '' : "\n") . $partText;
                }
            }
        }

        if (trim($text) === '') {
            throw new InvalidArgumentException('The prompt must contain text to convert to speech.');
        }

        return $text;
    }

    /**
     * Parses the response from the API endpoint to a generative AI result.
     *
     * @since 1.1.0
     *
     * @param Response    $response         The response from the API endpoint.
     * @param string|null $expectedMimeType The requested output MIME type, if any.
     * @param bool        $preferLink       Whether the request asked for a link to the audio.
     * @return GenerativeAiResult The result, with one candidate per audio clip.
     *
     * @throws ResponseException If the response holds no audio.
     */
    protected function parseResponseToGenerativeAiResult(
        Response $response,
        ?string $expectedMimeType,
        bool $preferLink = false
    ): GenerativeAiResult {
        $body = $response->getBody();
        $contentType = strtolower(trim(explode(';', (string) $response->getHeaderAsString('Content-Type'))[0]));

        // The audio file itself.
        if (
            $body !== null &&
            $body !== '' &&
            (strpos($contentType, 'audio/') === 0 || $contentType === 'application/octet-stream')
        ) {
            $mimeType = self::audioMimeType($contentType) ?? $expectedMimeType ?? self::DEFAULT_MIME_TYPE;

            return new GenerativeAiResult(
                '',
                [$this->createCandidate(EmpirioLabsFileFactory::fromBase64(base64_encode($body), $mimeType, 'body'))],
                new TokenUsage(0, 0, 0),
                $this->providerMetadata(),
                $this->metadata()
            );
        }

        /** @var ResponseData|null $responseData */
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

        $candidates = [];
        foreach (array_values($responseData['data']) as $index => $clipData) {
            if (!is_array($clipData)) {
                throw ResponseException::fromInvalidData(
                    'EmpirioLabs AI',
                    'data[' . (int) $index . ']',
                    'The value must be an associative array.'
                );
            }
            $candidates[] = $this->createCandidate(
                $this->parseClipToFile($clipData, (int) $index, $expectedMimeType, $preferLink)
            );
        }

        $usage = isset($responseData['usage']) && is_array($responseData['usage']) ? $responseData['usage'] : [];
        $promptTokens = isset($usage['prompt_tokens']) && is_int($usage['prompt_tokens'])
            ? $usage['prompt_tokens']
            : 0;
        $completionTokens = isset($usage['completion_tokens']) && is_int($usage['completion_tokens'])
            ? $usage['completion_tokens']
            : 0;

        // Token counts do not describe a speech request, so its own usage stays in the additional data.
        $additionalData = $responseData;
        unset($additionalData['id'], $additionalData['data']);

        return new GenerativeAiResult(
            isset($responseData['id']) && is_string($responseData['id']) ? $responseData['id'] : '',
            $candidates,
            new TokenUsage(
                $promptTokens,
                $completionTokens,
                isset($usage['total_tokens']) && is_int($usage['total_tokens'])
                    ? $usage['total_tokens']
                    : $promptTokens + $completionTokens
            ),
            $this->providerMetadata(),
            $this->metadata(),
            $additionalData
        );
    }

    /**
     * Parses one audio clip of the JSON response into a file.
     *
     * @since 1.1.0
     *
     * @param array<string, mixed> $clipData         The clip data from the API response.
     * @param int                  $index            The position of the clip.
     * @param string|null          $expectedMimeType The requested output MIME type, if any.
     * @param bool                 $preferLink       Whether the request asked for a link to the audio.
     * @return File The audio, inline or as a link.
     *
     * @throws ResponseException If the clip holds neither inline audio nor a link.
     */
    protected function parseClipToFile(
        array $clipData,
        int $index,
        ?string $expectedMimeType,
        bool $preferLink = false
    ): File {
        // The clip's own content type, else the type its format names, else the requested type.
        $mimeType = null;
        if (isset($clipData['content_type']) && is_string($clipData['content_type'])) {
            $mimeType = self::audioMimeType(strtolower(trim(explode(';', $clipData['content_type'])[0])));
        }
        if ($mimeType === null && isset($clipData['format']) && is_string($clipData['format'])) {
            $format = strtolower($clipData['format']);
            $mimeType = EmpirioLabsModelMetadataDirectory::AUDIO_FORMAT_MIME_TYPES[$format]
                ?? self::audioMimeType('audio/' . $format);
        }
        $mimeType = $mimeType ?? $expectedMimeType ?? self::DEFAULT_MIME_TYPE;

        $inline = isset($clipData['b64_json']) && is_string($clipData['b64_json']) && $clipData['b64_json'] !== ''
            ? $clipData['b64_json']
            : null;
        $link = isset($clipData['url']) && is_string($clipData['url']) && $clipData['url'] !== ''
            ? $clipData['url']
            : null;

        // A clip can carry both forms: the one the request asked for is returned.
        if ($link !== null && ($preferLink || $inline === null)) {
            return EmpirioLabsFileFactory::fromLink($link, $mimeType, 'data[' . (int) $index . '].url');
        }

        if ($inline !== null) {
            return EmpirioLabsFileFactory::fromBase64($inline, $mimeType, 'data[' . (int) $index . '].b64_json');
        }

        throw ResponseException::fromInvalidData(
            'EmpirioLabs AI',
            'data[' . (int) $index . ']',
            'The value must contain either a url or b64_json key with a string value.'
        );
    }

    /**
     * Creates a candidate that holds one audio file.
     *
     * @since 1.1.0
     *
     * @param File $file The audio file.
     * @return Candidate The candidate.
     */
    private function createCandidate(File $file): Candidate
    {
        return new Candidate(
            new Message(MessageRoleEnum::model(), [new MessagePart($file)]),
            FinishReasonEnum::stop()
        );
    }

    /**
     * Returns the given content type if it is an audio MIME type.
     *
     * @since 1.1.0
     *
     * @param string $contentType The content type, in lower case and without parameters.
     * @return string|null The audio MIME type, or null.
     */
    private static function audioMimeType(string $contentType): ?string
    {
        return preg_match('#^audio/[a-z0-9.+-]+$#', $contentType) ? $contentType : null;
    }
}
