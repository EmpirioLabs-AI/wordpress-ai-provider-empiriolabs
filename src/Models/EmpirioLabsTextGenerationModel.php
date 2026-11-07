<?php

declare(strict_types=1);

namespace EmpirioLabs\AiProvider\Models;

if (!defined('ABSPATH')) {
    exit;
}

use EmpirioLabs\AiProvider\Provider\EmpirioLabsProvider;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;

/**
 * Class for an EmpirioLabs AI text generation model, using the chat completions API.
 *
 * @since 1.0.0
 */
class EmpirioLabsTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel
{
    /**
     * Finish reasons that some models report, mapped to the ones the AI Client knows.
     *
     * @since 1.0.0
     *
     * @var array<string, string>
     */
    private const FINISH_REASON_ALIASES = [
        'end_turn' => 'stop',
        'stop_sequence' => 'stop',
        'eos' => 'stop',
        'max_tokens' => 'length',
        'model_length' => 'length',
        'function_call' => 'tool_calls',
        'tool_use' => 'tool_calls',
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
     * Adds `top_k`, which the shared chat completions implementation does not send.
     *
     * @since 1.0.0
     */
    protected function prepareGenerateTextParams(array $prompt): array
    {
        $params = parent::prepareGenerateTextParams($prompt);

        $topK = $this->getConfig()->getTopK();
        if ($topK !== null && !isset($params['top_k'])) {
            $params['top_k'] = $topK;
        }

        return $params;
    }

    /**
     * {@inheritDoc}
     *
     * Returns the reasoning of earlier assistant turns in `reasoning_content`, which models that think before a
     * tool call need on the next turn, and sends an assistant turn without text as `null` content.
     *
     * @since 1.0.0
     */
    protected function prepareMessagesParam(array $messages, ?string $systemInstruction = null): array
    {
        $messagesParam = parent::prepareMessagesParam($messages, $systemInstruction);

        // The parent prepends the system instruction, which shifts every message by one.
        $offset = $systemInstruction ? 1 : 0;

        foreach (array_values($messages) as $index => $message) {
            if (!$message instanceof Message || !$message->getRole()->isModel()) {
                continue;
            }
            $paramIndex = $index + $offset;
            if (!isset($messagesParam[$paramIndex]) || $messagesParam[$paramIndex]['role'] !== 'assistant') {
                continue;
            }

            $reasoning = '';
            foreach ($message->getParts() as $part) {
                if ($part->getType()->isText() && $part->getChannel()->isThought()) {
                    $reasoning .= (string) $part->getText();
                }
            }
            if ($reasoning !== '') {
                $messagesParam[$paramIndex]['reasoning_content'] = $reasoning;
            }

            if (
                isset($messagesParam[$paramIndex]['content']) &&
                $messagesParam[$paramIndex]['content'] === [] &&
                !empty($messagesParam[$paramIndex]['tool_calls'])
            ) {
                $messagesParam[$paramIndex]['content'] = null;
            }
        }

        return $messagesParam;
    }

    /**
     * {@inheritDoc}
     *
     * Sends an output schema in the OpenAI `json_schema` shape, with the `name` and `schema` keys. A model
     * that offers JSON mode without schemas receives `json_object`.
     *
     * @since 1.0.0
     */
    protected function prepareResponseFormatParam(?array $outputSchema): array
    {
        if (!is_array($outputSchema) || !$this->supportsOption(OptionEnum::outputSchema())) {
            return [
                'type' => 'json_object',
            ];
        }

        if (
            isset($outputSchema['schema']) &&
            is_array($outputSchema['schema']) &&
            !isset($outputSchema['type'])
        ) {
            // The schema already uses the OpenAI wrapper shape.
            $jsonSchema = $outputSchema;
        } else {
            $jsonSchema = [
                'schema' => $outputSchema,
            ];
        }
        if (!isset($jsonSchema['name']) || !is_string($jsonSchema['name']) || $jsonSchema['name'] === '') {
            $jsonSchema = ['name' => 'response'] + $jsonSchema;
        }

        return [
            'type' => 'json_schema',
            'json_schema' => $jsonSchema,
        ];
    }

    /**
     * {@inheritDoc}
     *
     * Reports the reasoning tokens of the response as thought tokens.
     *
     * @since 1.0.0
     */
    protected function parseResponseToGenerativeAiResult(Response $response): GenerativeAiResult
    {
        $result = parent::parseResponseToGenerativeAiResult($response);

        $responseData = $response->getData();
        $reasoningTokens = $responseData['usage']['completion_tokens_details']['reasoning_tokens'] ?? null;
        if (!is_int($reasoningTokens) || $reasoningTokens <= 0) {
            return $result;
        }

        $tokenUsage = $result->getTokenUsage();

        return new GenerativeAiResult(
            $result->getId(),
            $result->getCandidates(),
            new TokenUsage(
                $tokenUsage->getPromptTokens(),
                $tokenUsage->getCompletionTokens(),
                $tokenUsage->getTotalTokens(),
                $reasoningTokens
            ),
            $result->getProviderMetadata(),
            $result->getModelMetadata(),
            $result->getAdditionalData()
        );
    }

    /**
     * {@inheritDoc}
     *
     * Maps finish reasons that some models report to the ones the AI Client knows, and treats a missing
     * finish reason as a completed response.
     *
     * @since 1.0.0
     */
    protected function parseResponseChoiceToCandidate(array $choiceData, int $index): Candidate
    {
        $finishReason = $choiceData['finish_reason'] ?? null;
        if (!is_string($finishReason) || $finishReason === '') {
            $choiceData['finish_reason'] = 'stop';
        } elseif (isset(self::FINISH_REASON_ALIASES[$finishReason])) {
            $choiceData['finish_reason'] = self::FINISH_REASON_ALIASES[$finishReason];
        }

        return parent::parseResponseChoiceToCandidate($choiceData, $index);
    }

    /**
     * Checks whether the model lists the given option as supported.
     *
     * @since 1.0.0
     *
     * @param OptionEnum $option The option.
     * @return bool True if the model supports the option.
     */
    private function supportsOption(OptionEnum $option): bool
    {
        foreach ($this->metadata()->getSupportedOptions() as $supportedOption) {
            if ($supportedOption->getName()->value === $option->value) {
                return true;
            }
        }
        return false;
    }
}
