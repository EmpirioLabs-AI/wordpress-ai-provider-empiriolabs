<?php

declare(strict_types=1);

namespace EmpirioLabs\AiProvider\Operations;

if (!defined('ABSPATH')) {
    exit;
}

use WordPress\AiClient\Operations\DTO\GenerativeAiOperation;
use WordPress\AiClient\Operations\Enums\OperationStateEnum;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;

/**
 * A generation that runs in the background on EmpirioLabs AI.
 *
 * Adds the reason to an operation that failed, which the AI Client's operation does not carry.
 *
 * @since 1.1.0
 */
class EmpirioLabsGenerativeAiOperation extends GenerativeAiOperation
{
    /**
     * Why the operation failed, or null if it did not fail.
     *
     * @since 1.1.0
     *
     * @var string|null
     */
    private ?string $errorMessage;

    /**
     * Constructor.
     *
     * @since 1.1.0
     *
     * @param string                  $id           The operation ID.
     * @param OperationStateEnum      $state        The current state of the operation.
     * @param GenerativeAiResult|null $result       The result once the operation has succeeded.
     * @param string|null             $errorMessage Why the operation failed.
     */
    public function __construct(
        string $id,
        OperationStateEnum $state,
        ?GenerativeAiResult $result = null,
        ?string $errorMessage = null
    ) {
        parent::__construct($id, $state, $result);

        $this->errorMessage = $errorMessage;
    }

    /**
     * Returns why the operation failed.
     *
     * @since 1.1.0
     *
     * @return string|null The reason, or null if the operation did not fail.
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }
}
