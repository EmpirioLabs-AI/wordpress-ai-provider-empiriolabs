<?php

declare(strict_types=1);

namespace EmpirioLabs\AiProvider\Models;

if (!defined('ABSPATH')) {
    exit;
}

use EmpirioLabs\AiProvider\Metadata\EmpirioLabsModelMetadata;
use EmpirioLabs\AiProvider\Provider\EmpirioLabsProvider;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * Request helpers shared by the EmpirioLabs AI speech and video models.
 *
 * @since 1.1.0
 */
trait EmpirioLabsMediaModelTrait
{
    /**
     * Creates a request object for the API.
     *
     * A request whose caller set no timeout gets the provider's default, so that a request that starts a
     * generation is not given up on while the API is still accepting it.
     *
     * @since 1.1.0
     *
     * @param HttpMethodEnum                     $method  The HTTP method.
     * @param string                             $path    The request path.
     * @param array<string, list<string>|string> $headers The request headers.
     * @param array<string, mixed>|string|null   $data    The request body data.
     * @return Request The request.
     */
    protected function createRequest(
        HttpMethodEnum $method,
        string $path,
        array $headers = [],
        $data = null
    ): Request {
        $headers['User-Agent'] = EmpirioLabsProvider::userAgent();

        $options = $this->getRequestOptions();
        if ($options === null || $options->getTimeout() === null) {
            $options = $options !== null ? clone $options : new RequestOptions();
            $options->setTimeout(EmpirioLabsProvider::DEFAULT_REQUEST_TIMEOUT);
        }

        return new Request(
            $method,
            EmpirioLabsProvider::url($path),
            $headers,
            $data,
            $options
        );
    }

    /**
     * Sends a request with the account's credentials and returns the successful response.
     *
     * @since 1.1.0
     *
     * @param Request $request The request.
     * @return Response The response.
     */
    protected function sendRequest(Request $request): Response
    {
        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $this->getHttpTransporter()->send($request);
        ResponseUtil::throwIfNotSuccessful($response);

        return $response;
    }

    /**
     * Checks whether the model lists the given option as supported.
     *
     * @since 1.1.0
     *
     * @param OptionEnum $option The option.
     * @return bool True if the model supports the option.
     */
    protected function supportsOption(OptionEnum $option): bool
    {
        foreach ($this->metadata()->getSupportedOptions() as $supportedOption) {
            if ($supportedOption->getName()->value === $option->value) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns the request parameter and value that select an output MIME type, as the model's catalog entry
     * names them.
     *
     * @since 1.1.0
     *
     * @param string $mimeType The output MIME type.
     * @return array{0: string, 1: string} The parameter name and its value.
     *
     * @throws InvalidArgumentException If the model does not offer the MIME type.
     */
    protected function prepareOutputFormatParam(string $mimeType): array
    {
        $metadata = $this->metadata();
        if ($metadata instanceof EmpirioLabsModelMetadata) {
            $parameter = $metadata->getOutputFormatParameter();
            $value = $metadata->getOutputFormatValue($mimeType);
            if ($parameter !== null && $value !== null) {
                return [$parameter, $value];
            }
        }

        throw new InvalidArgumentException(
            'The output MIME type "' . esc_html($mimeType) . '" is not supported by this model.'
        );
    }

    /**
     * Adds the custom options of the model configuration to the request parameters.
     *
     * Custom options carry the parameters of a model that the AI Client has no option for.
     *
     * @since 1.1.0
     *
     * @param array<string, mixed> $params The request parameters.
     * @return array<string, mixed> The request parameters with the custom options.
     *
     * @throws InvalidArgumentException If a custom option names a parameter that is already set.
     */
    protected function addCustomOptions(array $params): array
    {
        foreach ($this->getConfig()->getCustomOptions() as $key => $value) {
            if (isset($params[$key])) {
                throw new InvalidArgumentException(
                    sprintf(
                        'The custom option "%s" conflicts with an existing parameter.',
                        esc_html((string) $key)
                    )
                );
            }
            $params[$key] = $value;
        }

        return $params;
    }
}
