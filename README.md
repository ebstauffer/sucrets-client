# sucrets-client

A small demo client for **sucrets**, the internal credentials service. It covers the whole round trip:

1. Ask the sucrets HTTP API for some tokens.
2. Get Defuse ciphertext back.
3. Decrypt it locally with the shared key.

It's an example to copy from, not a library. The demo shows that decryption worked **without ever printing a value**.

## Two keys, two jobs

| Key | What it does | Where it lives |
|---|---|---|
| **API key** (`x-api-key`) | Authenticates this client to sucrets and decides which tokens it may fetch | `.secrets/api_key` |
| **Encryption key** (Defuse, `def000…`) | Decrypts the values locally | `.secrets/encryption_key` |

The API key is **not** the decryption key. The encryption key never travels through sucrets, so never send it over the network. You get it from a sucrets admin out of band.

## Setup

You need Docker with Compose, and your machine's IP has to be on the sucrets allowlist. Any other IP gets `403` from the proxy.

1. **Ask a sucrets admin for an API key** limited to the tokens you need. The admin generates it on the server:
   ```bash
   docker compose run --rm sucrets_admin bin/console api-keys:generate <your-app> --tokens=MEZMO_KEY
   ```
2. **Get the Defuse encryption key** from the admin, over a secure channel such as SCP. Don't send it by chat or email.
3. **Put both keys in `.secrets/`**, one key per file. A trailing newline is fine.
   ```bash
   mkdir -p .secrets && chmod 700 .secrets
   $EDITOR .secrets/api_key          # paste the API key
   $EDITOR .secrets/encryption_key   # paste the Defuse key
   chmod 600 .secrets/*
   ```
   `.secrets/` is git-ignored. Never commit it.
4. **Start the container and install dependencies:**
   ```bash
   docker compose up -d --build
   docker compose exec client composer install
   ```

## Run the demo

```bash
docker compose exec client bin/demo MEZMO_KEY OTHER_TOKEN
```

```
 ----------- ------------- -------- ---------
  Token       Status        Length   Preview
 ----------- ------------- -------- ---------
  MEZMO_KEY   decrypted     32       67…
  OTHER_TOKEN denied        -        -
 ----------- ------------- -------- ---------
```

| Status | Meaning |
|---|---|
| `decrypted` | Fetched and decrypted. The preview shows at most the first two characters, and only for values of 12 characters or more. |
| `decrypt failed` | Wrong encryption key or modified ciphertext. Exits with code 1. |
| `missing` | The token doesn't exist or has no value. |
| `denied` | The token isn't on this API key's allowlist, so it was never looked up. |

Run `bin/demo --help` for the options. To point the demo at another server, set `SUCRETS_URL` (the default is `https://sucrets.safehiring.io`).

## Using it in your own code

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use SucretsClient\Decryptor;
use SucretsClient\KeyLoader;
use SucretsClient\SucretsClient;

$keys = new KeyLoader(getenv());
$factory = new HttpFactory();
$sucrets = new SucretsClient(
    new Client(['connect_timeout' => 2, 'timeout' => 5]),
    $factory,
    $factory,
    'https://sucrets.safehiring.io',
    $keys->apiKey(),
);
$decryptor = new Decryptor($keys->encryptionKey());

$result = $sucrets->fetch(['MEZMO_KEY']);           // throws SucretsException on any error
$mezmoKey = $decryptor->decrypt('MEZMO_KEY', $result->ciphertexts['MEZMO_KEY']);
```

- **Token names:** sucrets ignores case in the names you request and returns them in uppercase.
- **Missing and denied tokens:** check `$result->missing` and `$result->denied`.
- **Retrying:** on a `503`, `SucretsException::$retryAfterSeconds` holds the server's `Retry-After` value.

All you really need is: one `POST /v1/secrets` with `{"tokens": [...]}` and the `x-api-key` header, then `Crypto::decrypt($ciphertext, Key::loadFromAsciiSafeString($asciiKey))` from `defuse/php-encryption` v2.

## Where the keys are read from

Each key comes from a Docker secret file first, falling back to an environment variable. Keys are never read from code or from command-line arguments.

| | Secret file (default) | Env var fallback |
|---|---|---|
| API key | `SUCRETS_API_KEY_FILE` (`/run/secrets/sucrets_api_key`) | `SUCRETS_API_KEY` |
| Encryption key | `SUCRETS_ENCRYPTION_KEY_FILE` (`/run/secrets/sucrets_encryption_key`) | `SUCRETS_ENCRYPTION_KEY` |

Prefer the file. Env vars show up in `docker inspect`, `/proc/<pid>/environ` and child processes, so `docker-compose.yml` blanks `SUCRETS_ENCRYPTION_KEY` on purpose.

## Handling secrets in your code

- **Never log or print:** decrypted values, ciphertext, the API key or the encryption key. Token names are fine.
- **On a decryption failure,** report the token name only.
- **Give the encryption key only to the process that decrypts.**
- **Keep stack traces clean:** the image sets `zend.exception_ignore_args=On`, and the key parameters are marked `#[\SensitiveParameter]`.

## Errors

| HTTP | What the client reports |
|---|---|
| `400` | The server's error message: a bad body or token name, or more than 100 tokens |
| `401` | Missing or invalid API key |
| `403` | Your IP isn't on the allowlist (blocked at the proxy) |
| `500` | sucrets is misconfigured |
| `503` | Credential store unavailable; retry after `Retry-After` seconds |

## Development

```bash
docker compose exec client composer check    # PHP-CS-Fixer (PER), PHPStan level 8, PHPUnit
docker compose exec client composer cs-fix   # fix code style
```

The unit tests use Guzzle's `MockHandler` and never touch the network.
