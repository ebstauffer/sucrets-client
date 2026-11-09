<?php

declare(strict_types=1);

namespace SucretsClient;

/**
 * The only exception this client throws. Messages are always safe to show:
 * they never contain keys, ciphertext or decrypted values.
 */
final class SucretsException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
