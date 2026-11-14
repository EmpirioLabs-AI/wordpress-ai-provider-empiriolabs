<?php

declare(strict_types=1);

namespace EmpirioLabs\AiProvider\Files;

if (!defined('ABSPATH')) {
    exit;
}

use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;

/**
 * Creates the AI Client's files from what the EmpirioLabs AI API returns.
 *
 * The AI Client's file takes one string and works out what it is: a link, base64 data, or the path of a
 * local file. A value from an API response is therefore checked here first, so that it can only ever be read
 * as what the response said it was.
 *
 * @since 1.1.0
 */
final class EmpirioLabsFileFactory
{
    /**
     * Creates a remote file from a link in an API response.
     *
     * @since 1.1.0
     *
     * @param string $url      The link.
     * @param string $mimeType The MIME type of the file.
     * @param string $field    The response field the link came from, for the error message.
     * @return File The remote file.
     *
     * @throws ResponseException If the value is not an http or https link.
     */
    public static function fromLink(string $url, string $mimeType, string $field): File
    {
        if (!preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw ResponseException::fromInvalidData(
                'EmpirioLabs AI',
                esc_html($field),
                'The link must be an http or https URL.'
            );
        }

        return new File($url, $mimeType);
    }

    /**
     * Creates an inline file from base64 data in an API response.
     *
     * @since 1.1.0
     *
     * @param string $base64   The file as base64.
     * @param string $mimeType The MIME type of the file.
     * @param string $field    The response field the data came from, for the error message.
     * @return File The inline file.
     *
     * @throws ResponseException If the value is not base64 data.
     */
    public static function fromBase64(string $base64, string $mimeType, string $field): File
    {
        if (!preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $base64)) {
            // Some encoders break the data into lines.
            $base64 = (string) preg_replace('/\s+/', '', $base64);
            if (!preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $base64)) {
                throw ResponseException::fromInvalidData(
                    'EmpirioLabs AI',
                    esc_html($field),
                    'The value is not base64 data.'
                );
            }
        }

        // A data URI is read as inline data and never as the path of a local file.
        return new File('data:' . $mimeType . ';base64,' . $base64, $mimeType);
    }
}
