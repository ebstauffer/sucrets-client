<?php

declare(strict_types=1);

namespace SucretsClient\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SucretsClient\KeyLoader;
use SucretsClient\SucretsException;

#[CoversClass(KeyLoader::class)]
#[CoversClass(SucretsException::class)]
final class KeyLoaderTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/sucrets-keyloader-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    #[Test]
    public function itPrefersTheFileAndTrimsOneTrailingNewline(): void
    {
        file_put_contents($this->file, "from-file\n\n");

        $loader = new KeyLoader(['SUCRETS_API_KEY_FILE' => $this->file, 'SUCRETS_API_KEY' => 'from-env']);

        self::assertSame("from-file\n", $loader->apiKey());
    }

    #[Test]
    public function itFallsBackToTheEnvWhenTheFileIsMissingOrEmpty(): void
    {
        $env = ['SUCRETS_ENCRYPTION_KEY_FILE' => $this->file, 'SUCRETS_ENCRYPTION_KEY' => 'from-env'];
        self::assertSame('from-env', (new KeyLoader($env))->encryptionKey(), 'file missing');

        file_put_contents($this->file, "\n");
        self::assertSame('from-env', (new KeyLoader($env))->encryptionKey(), 'file empty');
    }

    #[Test]
    public function itThrowsWhenNeitherSourceIsSet(): void
    {
        $this->expectException(SucretsException::class);
        $this->expectExceptionMessage('SUCRETS_API_KEY');

        (new KeyLoader(['SUCRETS_API_KEY_FILE' => $this->file]))->apiKey();
    }
}
