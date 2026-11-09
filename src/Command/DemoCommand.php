<?php

declare(strict_types=1);

namespace SucretsClient\Command;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use SucretsClient\Decryptor;
use SucretsClient\KeyLoader;
use SucretsClient\SucretsClient;
use SucretsClient\SucretsException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Fetches tokens from sucrets and proves they decrypt, without ever printing a value.
 */
#[AsCommand(name: 'demo', description: 'Fetch tokens from sucrets and decrypt them locally')]
final class DemoCommand extends Command
{
    public function __construct(
        private readonly KeyLoader $keys,
        private readonly ClientInterface $http,
        private readonly RequestFactoryInterface&StreamFactoryInterface $factory,
        private readonly string $baseUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('tokens', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'Token names, e.g. MEZMO_KEY')
            ->setHelp(<<<'HELP'
                Fetches the tokens' ciphertext from sucrets and decrypts it locally, showing only
                the status, length and a masked preview of each value.

                Two different keys are involved:
                  - the API key (x-api-key) authenticates this client to sucrets;
                  - the Defuse encryption key decrypts the values. It is NOT the API key, it never
                    travels through sucrets, and you should never send it over the network. You get
                    it out of band and mount it as the secret file SUCRETS_ENCRYPTION_KEY_FILE
                    (default /run/secrets/sucrets_encryption_key).
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var list<string> $tokens */
        $tokens = $input->getArgument('tokens');
        if (count($tokens) > SucretsClient::MAX_TOKENS) {
            $io->error(sprintf('At most %d tokens per request.', SucretsClient::MAX_TOKENS));

            return Command::INVALID;
        }

        try {
            $client = new SucretsClient($this->http, $this->factory, $this->factory, $this->baseUrl, $this->keys->apiKey());
            $decryptor = new Decryptor($this->keys->encryptionKey());
            $result = $client->fetch($tokens);
        } catch (SucretsException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $rows = [];
        $failed = 0;
        foreach ($result->ciphertexts as $token => $ciphertext) {
            try {
                $plaintext = $decryptor->decrypt($token, $ciphertext);
                $rows[] = [$token, '<info>decrypted</info>', (string) strlen($plaintext), self::mask($plaintext)];
            } catch (SucretsException) {
                $rows[] = [$token, '<error>decrypt failed</error>', '-', '-'];
                ++$failed;
            }
        }
        unset($plaintext);

        foreach ($result->missing as $token) {
            $rows[] = [$token, '<comment>missing</comment>', '-', '-'];
        }
        foreach ($result->denied as $token) {
            $rows[] = [$token, '<comment>denied</comment>', '-', '-'];
        }

        $io->table(['Token', 'Status', 'Length', 'Preview'], $rows);

        if ($failed > 0) {
            $io->error(sprintf('%d token(s) failed to decrypt: wrong encryption key or modified ciphertext.', $failed));

            return Command::FAILURE;
        }
        if ($result->missing !== [] || $result->denied !== []) {
            $io->warning('Some tokens were missing or not on this client\'s allowlist.');
        }
        $io->success(sprintf('Decrypted %d token(s).', count($result->ciphertexts)));

        return Command::SUCCESS;
    }

    /**
     * At most the first two characters, and only for values long enough that this reveals little.
     */
    private static function mask(#[\SensitiveParameter] string $plaintext): string
    {
        return mb_strlen($plaintext) >= 12 ? mb_substr($plaintext, 0, 2) . '…' : '•••';
    }
}
