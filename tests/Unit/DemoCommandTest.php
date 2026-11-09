<?php

declare(strict_types=1);

namespace SucretsClient\Tests\Unit;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use SucretsClient\Command\DemoCommand;
use SucretsClient\Decryptor;
use SucretsClient\FetchResult;
use SucretsClient\KeyLoader;
use SucretsClient\SucretsClient;
use SucretsClient\SucretsException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(DemoCommand::class)]
#[UsesClass(SucretsClient::class)]
#[UsesClass(Decryptor::class)]
#[UsesClass(KeyLoader::class)]
#[UsesClass(FetchResult::class)]
#[UsesClass(SucretsException::class)]
final class DemoCommandTest extends TestCase
{
    private const string SECRET = 'super-secret-mezmo-value';

    #[Test]
    public function itShowsDecryptionWorkedWithoutRevealingValues(): void
    {
        $key = Key::createNewRandomKey();
        $ciphertext = Crypto::encrypt(self::SECRET, $key);
        $body = json_encode(['secrets' => ['MEZMO_KEY' => $ciphertext], 'missing' => ['GONE'], 'denied' => []], JSON_THROW_ON_ERROR);

        $tester = $this->tester(new Response(200, [], $body), $key->saveToAsciiSafeString());
        $exitCode = $tester->execute(['tokens' => ['MEZMO_KEY', 'GONE']]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('MEZMO_KEY', $display);
        self::assertStringContainsString('decrypted', $display);
        self::assertStringContainsString((string) strlen(self::SECRET), $display);
        self::assertStringContainsString('su…', $display);
        self::assertStringContainsString('missing', $display);
        self::assertStringNotContainsString(self::SECRET, $display);
        self::assertStringNotContainsString($ciphertext, $display);
    }

    #[Test]
    public function itFailsWhenDecryptionFails(): void
    {
        $ciphertext = Crypto::encrypt(self::SECRET, Key::createNewRandomKey());
        $body = json_encode(['secrets' => ['MEZMO_KEY' => $ciphertext], 'missing' => [], 'denied' => []], JSON_THROW_ON_ERROR);

        $tester = $this->tester(new Response(200, [], $body), Key::createNewRandomKey()->saveToAsciiSafeString());

        self::assertSame(Command::FAILURE, $tester->execute(['tokens' => ['MEZMO_KEY']]));
        self::assertStringContainsString('decrypt failed', $tester->getDisplay());
    }

    #[Test]
    public function itReportsApiErrors(): void
    {
        $tester = $this->tester(new Response(401, [], '{"error":"unauthorized"}'), Key::createNewRandomKey()->saveToAsciiSafeString());

        self::assertSame(Command::FAILURE, $tester->execute(['tokens' => ['MEZMO_KEY']]));
        self::assertStringContainsString('API key', $tester->getDisplay());
        self::assertStringNotContainsString('test-api-key', $tester->getDisplay());
    }

    private function tester(Response $response, string $asciiKey): CommandTester
    {
        $http = new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]);
        $keys = new KeyLoader([
            'SUCRETS_API_KEY_FILE' => '/nonexistent',
            'SUCRETS_API_KEY' => 'test-api-key',
            'SUCRETS_ENCRYPTION_KEY_FILE' => '/nonexistent',
            'SUCRETS_ENCRYPTION_KEY' => $asciiKey,
        ]);

        return new CommandTester(new DemoCommand($keys, $http, new HttpFactory(), 'https://sucrets.test'));
    }
}
