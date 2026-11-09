<?php

declare(strict_types=1);

namespace SucretsClient\Tests\Unit;

use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SucretsClient\Decryptor;
use SucretsClient\SucretsException;

#[CoversClass(Decryptor::class)]
#[CoversClass(SucretsException::class)]
final class DecryptorTest extends TestCase
{
    #[Test]
    public function itDecryptsDefuseCiphertext(): void
    {
        $key = Key::createNewRandomKey();
        $ciphertext = Crypto::encrypt('hunter2', $key);

        self::assertStringStartsWith('def50200', $ciphertext);
        self::assertSame('hunter2', (new Decryptor($key->saveToAsciiSafeString()))->decrypt('MEZMO_KEY', $ciphertext));
    }

    #[Test]
    public function itReportsOnlyTheTokenNameWhenTheKeyIsWrong(): void
    {
        $ciphertext = Crypto::encrypt('hunter2', Key::createNewRandomKey());
        $decryptor = new Decryptor(Key::createNewRandomKey()->saveToAsciiSafeString());

        try {
            $decryptor->decrypt('MEZMO_KEY', $ciphertext);
            self::fail('Expected a SucretsException');
        } catch (SucretsException $e) {
            self::assertStringContainsString('MEZMO_KEY', $e->getMessage());
            self::assertStringNotContainsString($ciphertext, $e->getMessage());
            self::assertStringNotContainsString('hunter2', $e->getMessage());
        }
    }

    #[Test]
    public function itRejectsAMalformedKeyWithoutEchoingIt(): void
    {
        try {
            new Decryptor('not-a-real-key');
            self::fail('Expected a SucretsException');
        } catch (SucretsException $e) {
            self::assertStringNotContainsString('not-a-real-key', $e->getMessage());
        }
    }
}
