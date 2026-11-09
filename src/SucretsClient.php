<?php

declare(strict_types=1);

namespace SucretsClient;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Talks to the sucrets HTTP API. Returns ciphertext only; decryption is the Decryptor's job.
 */
final class SucretsClient
{
    public const DEFAULT_URL = 'https://sucrets.safehiring.io';

    public const MAX_TOKENS = 100;

    /**
     * Any PSR-18 client works; set a connect timeout of about 2s and a total timeout of about 5s on it.
     */
    public function __construct(
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface $requests,
        private readonly StreamFactoryInterface $streams,
        #[\SensitiveParameter]
        private readonly string $apiKey,
        private readonly string $baseUrl = self::DEFAULT_URL,
    ) {}

    /**
     * @param list<string> $tokens
     *
     * @throws SucretsException
     */
    public function fetch(array $tokens): FetchResult
    {
        if ($tokens === []) {
            throw new SucretsException('Ask for at least one token.');
        }
        if (count($tokens) > self::MAX_TOKENS) {
            throw new SucretsException(sprintf('At most %d tokens per request.', self::MAX_TOKENS));
        }

        try {
            $body = json_encode(['tokens' => $tokens], JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SucretsException('Token names could not be encoded as JSON.', previous: $e);
        }

        $request = $this->requests->createRequest('POST', rtrim($this->baseUrl, '/') . '/v1/secrets')
            ->withHeader('x-api-key', $this->apiKey)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Accept', 'application/json')
            ->withBody($this->streams->createStream($body));

        try {
            $response = $this->http->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new SucretsException(sprintf('Could not reach sucrets at %s.', rtrim($this->baseUrl, '/')), previous: $e);
        }

        $status = $response->getStatusCode();

        return match (true) {
            $status === 200 => $this->parseSecrets($response),
            $status === 400 => throw new SucretsException('sucrets rejected the request: ' . $this->errorMessage($response)),
            $status === 401 => throw new SucretsException('sucrets rejected the API key (401).'),
            $status === 403 => throw new SucretsException('Forbidden (403): this IP is probably not on the sucrets allowlist.'),
            $status === 503 => throw $this->unavailable($response),
            $status >= 500 => throw new SucretsException(sprintf('sucrets server error (%d): %s', $status, $this->errorMessage($response))),
            default => throw new SucretsException(sprintf('Unexpected response from sucrets (HTTP %d).', $status)),
        };
    }

    private function parseSecrets(ResponseInterface $response): FetchResult
    {
        try {
            $data = json_decode((string) $response->getBody(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Never put the body in the message: it holds ciphertext.
            throw new SucretsException('sucrets returned invalid JSON.', previous: $e);
        }

        if (!is_array($data)) {
            throw new SucretsException('sucrets returned an unexpected response shape.');
        }

        $secrets = $data['secrets'] ?? null;
        $missing = $data['missing'] ?? [];
        $denied = $data['denied'] ?? [];

        if (!is_array($secrets) || !is_array($missing) || !is_array($denied)) {
            throw new SucretsException('sucrets returned an unexpected response shape.');
        }

        $ciphertexts = [];
        foreach ($secrets as $token => $ciphertext) {
            if (!is_string($ciphertext)) {
                throw new SucretsException(sprintf('sucrets returned a non-string value for %s.', $token));
            }
            $ciphertexts[(string) $token] = $ciphertext;
        }

        return new FetchResult($ciphertexts, self::stringList($missing), self::stringList($denied));
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<string>
     */
    private static function stringList(array $values): array
    {
        $list = [];
        foreach ($values as $value) {
            if (!is_string($value)) {
                throw new SucretsException('sucrets returned an unexpected response shape.');
            }
            $list[] = $value;
        }

        return $list;
    }

    private function unavailable(ResponseInterface $response): SucretsException
    {
        $header = $response->getHeaderLine('Retry-After');
        $retryAfter = ctype_digit($header) ? (int) $header : null;

        return new SucretsException(
            $retryAfter === null
                ? 'sucrets credential store is unavailable (503); try again later.'
                : sprintf('sucrets credential store is unavailable (503); retry after %d s.', $retryAfter),
            $retryAfter,
        );
    }

    private function errorMessage(ResponseInterface $response): string
    {
        try {
            $data = json_decode((string) $response->getBody(), true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return 'no error message';
        }

        return is_array($data) && is_string($data['error'] ?? null) ? $data['error'] : 'no error message';
    }
}
