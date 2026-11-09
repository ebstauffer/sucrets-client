<?php

declare(strict_types=1);

namespace SucretsClient;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Exception\BadFormatException;
use Defuse\Crypto\Exception\WrongKeyOrModifiedCiphertextException;
use Defuse\Crypto\Key;

final class Decryptor
{
    private readonly Key $key;

    public function __construct(#[\SensitiveParameter] string $asciiKey)
    {
        try {
            $this->key = Key::loadFromAsciiSafeString($asciiKey);
        } catch (BadFormatException) {
            // Defuse's messages don't include key material, but don't chain anyway: keep the trace clean.
            throw new SucretsException('The encryption key is not a valid Defuse key.');
        }
    }

    public function decrypt(string $token, #[\SensitiveParameter] string $ciphertext): string
    {
        try {
            return Crypto::decrypt($ciphertext, $this->key);
        } catch (WrongKeyOrModifiedCiphertextException $e) {
            throw new SucretsException(sprintf('Could not decrypt %s: wrong key or modified ciphertext.', $token), previous: $e);
        }
    }
}
