<?php

declare(strict_types=1);

namespace SucretsClient;

/**
 * Reads a key from a Docker secret file, falling back to an environment variable.
 * An empty file or empty variable counts as "not set".
 */
final class KeyLoader
{
    /**
     * @param array<string, string> $env usually getenv()
     */
    public function __construct(private readonly array $env) {}

    public function apiKey(): string
    {
        return $this->load('API key', 'SUCRETS_API_KEY_FILE', '/run/secrets/sucrets_api_key', 'SUCRETS_API_KEY');
    }

    public function encryptionKey(): string
    {
        return $this->load('encryption key', 'SUCRETS_ENCRYPTION_KEY_FILE', '/run/secrets/sucrets_encryption_key', 'SUCRETS_ENCRYPTION_KEY');
    }

    private function load(string $label, string $fileVar, string $defaultFile, string $envVar): string
    {
        $path = ($this->env[$fileVar] ?? '') !== '' ? $this->env[$fileVar] : $defaultFile;

        if (is_file($path)) {
            $contents = @file_get_contents($path);
            if ($contents === false) {
                throw new SucretsException(sprintf('Could not read the %s file %s.', $label, $path));
            }

            $key = self::trimOneNewline($contents);
            if ($key !== '') {
                return $key;
            }
        }

        $key = $this->env[$envVar] ?? '';
        if ($key !== '') {
            return $key;
        }

        throw new SucretsException(sprintf('No %s found: put it in %s (or set %s), or set %s.', $label, $path, $fileVar, $envVar));
    }

    private static function trimOneNewline(string $value): string
    {
        if (str_ends_with($value, "\r\n")) {
            return substr($value, 0, -2);
        }

        return str_ends_with($value, "\n") ? substr($value, 0, -1) : $value;
    }
}
