<?php

declare(strict_types=1);

namespace SucretsClient\Tests\Unit;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SucretsClient\FetchResult;
use SucretsClient\SucretsClient;
use SucretsClient\SucretsException;

#[CoversClass(SucretsClient::class)]
#[CoversClass(FetchResult::class)]
#[CoversClass(SucretsException::class)]
final class SucretsClientTest extends TestCase
{
    private const API_KEY = 'test-api-key-123';

    private MockHandler $mock;

    #[Test]
    public function itSendsTheTokensAndApiKeyAndParsesTheResponse(): void
    {
        $client = $this->client(new Response(200, [], json_encode([
            'secrets' => ['MEZMO_KEY' => 'def50200aaaa'],
            'missing' => ['GONE'],
            'denied' => ['NOPE'],
        ], JSON_THROW_ON_ERROR)));

        $result = $client->fetch(['MEZMO_KEY', 'GONE', 'NOPE']);

        self::assertSame(['MEZMO_KEY' => 'def50200aaaa'], $result->ciphertexts);
        self::assertSame(['GONE'], $result->missing);
        self::assertSame(['NOPE'], $result->denied);

        $request = $this->mock->getLastRequest();
        self::assertNotNull($request);
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://sucrets.test/v1/secrets', (string) $request->getUri());
        self::assertSame(self::API_KEY, $request->getHeaderLine('x-api-key'));
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame('{"tokens":["MEZMO_KEY","GONE","NOPE"]}', (string) $request->getBody());
    }

    #[Test]
    public function itAcceptsAnEmptySecretsObject(): void
    {
        $result = $this->client(new Response(200, [], '{"secrets":{},"missing":["A"],"denied":[]}'))->fetch(['A']);

        self::assertSame([], $result->ciphertexts);
        self::assertSame(['A'], $result->missing);
    }

    /**
     * @return iterable<string, array{Response, string}>
     */
    public static function errorResponses(): iterable
    {
        yield '400' => [new Response(400, [], '{"error":"invalid token name: bad name"}'), 'invalid token name: bad name'];
        yield '401' => [new Response(401, [], '{"error":"unauthorized"}'), 'API key (401)'];
        yield '403 from proxy' => [new Response(403, [], '<html>Forbidden</html>'), 'allowlist'];
        yield '500' => [new Response(500, [], '{"error":"server misconfigured"}'), 'server misconfigured'];
        yield '503 without Retry-After' => [new Response(503, [], '{"error":"unavailable"}'), 'try again later'];
        yield 'unexpected' => [new Response(302), 'HTTP 302'];
        yield 'invalid JSON' => [new Response(200, [], 'def50200 not json'), 'invalid JSON'];
        yield 'wrong shape' => [new Response(200, [], '{"secrets":[1]}'), 'non-string value'];
        yield 'secrets missing' => [new Response(200, [], '{"missing":[]}'), 'unexpected response shape'];
    }

    #[Test]
    #[DataProvider('errorResponses')]
    public function itTurnsErrorsIntoSafeExceptions(Response $response, string $expectedMessage): void
    {
        try {
            $this->client($response)->fetch(['A']);
            self::fail('Expected a SucretsException');
        } catch (SucretsException $e) {
            self::assertStringContainsString($expectedMessage, $e->getMessage());
            self::assertStringNotContainsString(self::API_KEY, $e->getMessage());
            self::assertStringNotContainsString('def50200', $e->getMessage());
        }
    }

    #[Test]
    public function itExposesRetryAfterOn503(): void
    {
        try {
            $this->client(new Response(503, ['Retry-After' => '30'], '{"error":"unavailable"}'))->fetch(['A']);
            self::fail('Expected a SucretsException');
        } catch (SucretsException $e) {
            self::assertSame(30, $e->retryAfterSeconds);
            self::assertStringContainsString('retry after 30 s', $e->getMessage());
        }
    }

    #[Test]
    public function itWrapsTransportErrors(): void
    {
        $client = $this->client(new ConnectException('Connection refused', new Request('POST', 'https://sucrets.test')));

        $this->expectException(SucretsException::class);
        $this->expectExceptionMessage('Could not reach sucrets at https://sucrets.test.');

        $client->fetch(['A']);
    }

    #[Test]
    public function itRejectsTooManyTokensWithoutCallingTheApi(): void
    {
        $client = $this->client();

        try {
            $client->fetch(array_map(static fn(int $i): string => 'T' . $i, range(1, 101)));
            self::fail('Expected a SucretsException');
        } catch (SucretsException $e) {
            self::assertStringContainsString('At most 100', $e->getMessage());
        }
        self::assertNull($this->mock->getLastRequest());
    }

    #[Test]
    public function itRejectsAnEmptyTokenList(): void
    {
        $this->expectException(SucretsException::class);

        $this->client()->fetch([]);
    }

    #[Test]
    public function itDefaultsToTheProductionUrl(): void
    {
        $this->mock = new MockHandler([new Response(200, [], '{"secrets":{},"missing":[],"denied":[]}')]);
        $factory = new HttpFactory();
        $client = new SucretsClient(new Client(['handler' => HandlerStack::create($this->mock)]), $factory, $factory, self::API_KEY);

        $client->fetch(['A']);

        self::assertSame('https://sucrets.safehiring.io/v1/secrets', (string) $this->mock->getLastRequest()?->getUri());
    }

    private function client(Response|\Throwable ...$queue): SucretsClient
    {
        $this->mock = new MockHandler(array_values($queue));
        $stack = HandlerStack::create($this->mock);
        $factory = new HttpFactory();

        return new SucretsClient(new Client(['handler' => $stack]), $factory, $factory, self::API_KEY, 'https://sucrets.test/');
    }
}
