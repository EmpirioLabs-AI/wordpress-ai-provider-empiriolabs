<?php

declare(strict_types=1);

namespace EmpirioLabs\AiProvider\Metadata;

if (!defined('ABSPATH')) {
    exit;
}

use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

/**
 * Model metadata that also holds how a model's catalog entry names its output format.
 *
 * The catalog lists the audio formats of a speech model under a parameter whose name and whose spelling of
 * each format differ between models. The directory reads both from the catalog entry and keeps them here,
 * next to the MIME types it offers to the AI Client, so the model class sends exactly what the entry lists.
 *
 * @since 1.1.0
 */
class EmpirioLabsModelMetadata extends ModelMetadata
{
    /**
     * The name of the request parameter that sets the output format, or null if the model lists none.
     *
     * @since 1.1.0
     *
     * @var string|null
     */
    protected ?string $outputFormatParameter;

    /**
     * Map of output MIME type to the value the catalog entry lists for it.
     *
     * @since 1.1.0
     *
     * @var array<string, string>
     */
    protected array $outputFormatValues;

    /**
     * Constructor.
     *
     * @since 1.1.0
     *
     * @param string                $id                    The model's unique identifier.
     * @param string                $name                  The model's display name.
     * @param list<CapabilityEnum>  $supportedCapabilities The model's supported capabilities.
     * @param list<SupportedOption> $supportedOptions      The model's supported configuration options.
     * @param string|null           $outputFormatParameter The request parameter that sets the output format.
     * @param array<string, string> $outputFormatValues    Map of output MIME type to the catalog's value for it.
     */
    public function __construct(
        string $id,
        string $name,
        array $supportedCapabilities,
        array $supportedOptions,
        ?string $outputFormatParameter = null,
        array $outputFormatValues = []
    ) {
        parent::__construct($id, $name, $supportedCapabilities, $supportedOptions);

        $this->outputFormatParameter = $outputFormatParameter;
        $this->outputFormatValues = $outputFormatValues;
    }

    /**
     * Returns the name of the request parameter that sets the output format.
     *
     * @since 1.1.0
     *
     * @return string|null The parameter name, or null if the model lists no output formats.
     */
    public function getOutputFormatParameter(): ?string
    {
        return $this->outputFormatParameter;
    }

    /**
     * Returns the value the catalog entry lists for an output MIME type.
     *
     * @since 1.1.0
     *
     * @param string $mimeType The output MIME type, such as `audio/mpeg`.
     * @return string|null The value to send, or null if the model does not offer the MIME type.
     */
    public function getOutputFormatValue(string $mimeType): ?string
    {
        return $this->outputFormatValues[strtolower($mimeType)] ?? null;
    }
}
