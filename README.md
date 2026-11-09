# sucrets-client

A small PHP client library for **sucrets**, the internal credentials service. It covers the whole round trip:

1. Ask the sucrets HTTP API for some tokens.
2. Get Defuse ciphertext back.
3. Decrypt it locally with the shared key.

It needs **PHP 8.2+**. Its only runtime dependencies are `defuse/php-encryption` and the PSR HTTP interfaces, so you bring your own PSR-18 HTTP client. Guzzle works.

## Two keys, two jobs

| Key | What it does |
|---|---|
| **API key** (`x-api-key`) | Authenticates your app to sucrets and decides which tokens it may fetch |
| **Encryption key** (Defuse, `def000…`) | Decrypts the values locally |

The API key is **not** the decryption key. The encryption key never travels through sucrets, so never send it over the network.

## Before you start

- **An API key** limited to the tokens you need. A sucrets admin generates it on the server:
  ```bash
  docker compose run --rm sucrets_admin bin/console api-keys:generate <your-app> --tokens=MEZMO_KEY
  ```
- **The Defuse encryption key,** from the admin over a secure channel such as SCP. Don't send it by chat or email.
- **Allowlisting:** your server's IP must be on the sucrets allowlist. Any other IP gets `403` from the proxy.

## Install

Your machine (and CI) needs read access to this repo over SSH.

```bash
composer config repositories.sucrets-client vcs git@github.com:ebstauffer/sucrets-client.git
composer require safehiring/sucrets-client:^1.0 guzzlehttp/guzzle:^7.9
```

That adds this to your `composer.json`:

```json
"repositories": {
    "sucrets-client": { "type": "vcs", "url": "git@github.com:ebstauffer/sucrets-client.git" }
},
"require": {
    "safehiring/sucrets-client": "^1.0"
}
```

## Usage

Mount the two keys as secret files at the default paths (see [Where the keys are read from](#where-the-keys-are-read-from)), then:

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use SucretsClient\Decryptor;
use SucretsClient\KeyLoader;
use SucretsClient\SucretsClient;

$keys = new KeyLoader(getenv());
$factory = new HttpFactory();                       // PSR-17 request + stream factory
$sucrets = new SucretsClient(
    new Client(['connect_timeout' => 2, 'timeout' => 5]),
    $factory,
    $factory,
    $keys->apiKey(),
    // optional 5th argument: base URL, defaults to SucretsClient::DEFAULT_URL
);
$decryptor = new Decryptor($keys->encryptionKey());

$result = $sucrets->fetch(['MEZMO_KEY']);           // throws SucretsException on any error
$mezmoKey = $decryptor->decrypt('MEZMO_KEY', $result->ciphertexts['MEZMO_KEY']);
```

- **Token names:** sucrets ignores case in the names you request and returns them in uppercase.
- **What comes back:** `$result->ciphertexts` maps each found token to its ciphertext. `$result->missing` lists tokens that don't exist or have no value. `$result->denied` lists tokens outside your API key's allowlist; those were never looked up.
- **Errors:** every failure throws `SucretsException`, and its message is always safe to log. A failed decryption names only the token.
- **Retrying:** on a `503`, `SucretsException::$retryAfterSeconds` holds the server's `Retry-After` value.

| HTTP | What the client reports |
|---|---|
| `400` | The server's error message: a bad body or token name |
| `401` | Missing or invalid API key |
| `403` | Your IP isn't on the allowlist (blocked at the proxy) |
| `500` | sucrets is misconfigured |
| `503` | Credential store unavailable; retry after `Retry-After` seconds |

The client checks for an empty list and for more than 100 tokens itself, before sending anything.

## Where the keys are read from

`KeyLoader` reads each key from a Docker secret file first, falling back to an environment variable. It never takes keys from code or command-line arguments. One trailing newline in a file is trimmed.

| | Secret file (default) | Env var fallback |
|---|---|---|
| API key | `SUCRETS_API_KEY_FILE` (`/run/secrets/sucrets_api_key`) | `SUCRETS_API_KEY` |
| Encryption key | `SUCRETS_ENCRYPTION_KEY_FILE` (`/run/secrets/sucrets_encryption_key`) | `SUCRETS_ENCRYPTION_KEY` |

Prefer the file. Env vars show up in `docker inspect`, `/proc/<pid>/environ` and child processes. In your own `docker-compose.yml`, mount the encryption key as a secret and set `SUCRETS_ENCRYPTION_KEY=` to blank, so a stray value can't leak in.

## Handling secrets in your code

- **Never log or print:** decrypted values, ciphertext, the API key or the encryption key. Token names are fine.
- **Give the encryption key only to the process that decrypts.**
- **Keep stack traces clean:** set `zend.exception_ignore_args=On` in production. The library already marks its key and ciphertext parameters `#[\SensitiveParameter]`.

## Without the library

The protocol is small: one `POST /v1/secrets` with `{"tokens": [...]}` and the `x-api-key` header, then `Crypto::decrypt($ciphertext, Key::loadFromAsciiSafeString($asciiKey))` from `defuse/php-encryption` v2.

## Development

```bash
docker compose up -d --build
docker compose exec client composer install
docker compose exec client composer check    # PHP-CS-Fixer (PER), PHPStan level 8, PHPUnit
docker compose exec client composer cs-fix   # fix code style
```

The unit tests use Guzzle's `MockHandler` and never touch the network. PHPStan checks the code against PHP 8.2–8.5.
