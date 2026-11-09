<?php

declare(strict_types=1);

namespace SucretsClient;

final readonly class FetchResult
{
    /**
     * @param array<string, string> $ciphertexts token name => Defuse ciphertext
     * @param list<string>          $missing     tokens that don't exist or have no value
     * @param list<string>          $denied      tokens outside this client's allowlist
     */
    public function __construct(
        public array $ciphertexts,
        public array $missing,
        public array $denied,
    ) {}
}
