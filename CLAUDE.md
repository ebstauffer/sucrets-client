# sucrets-client

A small **PHP client library** for sucrets, the internal credentials service. It covers the whole round trip: request tokens from the sucrets HTTP API, get Defuse ciphertext back, and decrypt it locally with the shared key. Other PHP projects install it with Composer from this git repo (`safehiring/sucrets-client`, tagged semver releases). Keep it small and readable. It's a thin client, not a framework.

- The library is `src/` only (namespace `SucretsClient\`).
- Its public API (`SucretsClient`, `Decryptor`, `KeyLoader`, `FetchResult`, `SucretsException`) is a semver contract: breaking changes need a major version.

## The sucrets API (the contract this client codes against)
- `POST https://sucrets.safehiring.io/v1/secrets`, header `x-api-key: <key>`, `Content-Type: application/json`
- Body: `{"tokens": ["MEZMO_KEY", "OTHER"]}`. Token names are case-insensitive; at most 100 per request.
- `200` → `{"secrets": {"TOKEN": "<ciphertext>", ...}, "missing": [...], "denied": [...]}`
  - `secrets` is always an object (empty → `{}`).
  - `missing`: tokens that don't exist or have no value.
  - `denied`: tokens outside this client's allowlist. They were never queried.
- Errors are `{"error": "<message>"}`:
  - `400`: bad body or token name.
  - `401`: missing or invalid API key.
  - `500`: server misconfigured.
  - `503`: credential store unavailable; honour `Retry-After`.
- `GET /v1/health` → `{"status":"ok"}` (no key needed; doesn't touch the database).
- Access is limited by IP at the proxy. Anything not on the allowlist gets `403` from Caddy before reaching sucrets.

## Secrets handling (non-negotiable)
- **Never log, print, or put in exception messages:** decrypted values, ciphertext, the API key, or the Defuse key. Token *names* are fine.
- **Ciphertext** is Defuse `php-encryption` **v2** hex (starts with `def50200`). Decrypt with `Crypto::decrypt($ciphertext, Key::loadFromAsciiSafeString($asciiKey))`. Catch `WrongKeyOrModifiedCiphertextException` and report the *token name* only.
- Mark parameters that carry keys or ciphertext `#[\SensitiveParameter]`.
- **Key sources (`KeyLoader`):** both keys come from a Docker secret file first, falling back to an env var. They never come from code, the repo, or CLI arguments.
  - API key: `SUCRETS_API_KEY_FILE` (default `/run/secrets/sucrets_api_key`), fallback `SUCRETS_API_KEY`.
  - Defuse key: `SUCRETS_ENCRYPTION_KEY_FILE` (default `/run/secrets/sucrets_encryption_key`), fallback `SUCRETS_ENCRYPTION_KEY`.
  - Trim one trailing newline from files.
  - The `x-api-key` is **not** the decryption key. The Defuse key is distributed out of band and never sent over the network.
  - Exception: when `Key::loadFromAsciiSafeString()` fails, throw `SucretsException` **without** `previous`. This matches the server's `EncryptionKeyProvider` and keeps the trace clean.
- Never commit `.env`, `.secrets/` or any key material. Tests generate throwaway keys with `Key::createNewRandomKey()`.

## Tech stack
- **PHP:** the library supports **PHP ≥ 8.2** (`composer.json`, enforced by PHPStan `phpVersion` 8.2–8.5). The dev container runs **8.5**.
  - Don't use syntax newer than 8.2 in `src/` or `tests/`: no typed class constants, no `#[\Override]`.
  - Strict types everywhere.
- **Library runtime deps, and nothing else:** `defuse/php-encryption ^2.4`, `psr/http-client`, `psr/http-factory`, `psr/http-message`.
- **HTTP through PSR-18 / PSR-17 interfaces only:** type-hint `Psr\Http\Client\ClientInterface`, `RequestFactoryInterface` and `StreamFactoryInterface`, call `sendRequest()`. The library never references Guzzle.
  - Check `getStatusCode()` explicitly.
  - Catch `ClientExceptionInterface` (transport) and `\JsonException` (decode with `JSON_THROW_ON_ERROR`).
  - Timeouts are the caller's HTTP client's job. The README recommends connect 2s, total 5s.
- **Dev only (`require-dev`):** Guzzle 7 (the tests' `MockHandler` and PSR-17 factories), PHPUnit, PHPStan, PHP-CS-Fixer.
- No framework, no DI container, no database.
- **Docker & Compose:** dev environment only. Service name `client`, image based on `php:8.5-cli`.
- **Distribution:**
  - Consumers add `{"type": "vcs", "url": "git@github.com:ebstauffer/sucrets-client.git"}` to `repositories` and require a tag.
  - `.gitattributes` `export-ignore`s everything but `src/`, `composer.json` and `README.md`.
  - `composer.lock` is git-ignored, as is usual for a library.

## Layout
```text
├── src/                     # Namespace: SucretsClient\
│   ├── SucretsClient.php    # fetch(tokens) → FetchResult (ciphertexts, missing, denied); throws SucretsException
│   ├── Decryptor.php        # ciphertext → plaintext with the Defuse key
│   ├── KeyLoader.php        # reads a key from file, else env; throws if neither
│   ├── FetchResult.php
│   └── SucretsException.php
├── tests/Unit/              # Guzzle MockHandler; no network
├── Dockerfile, docker-compose.yml   # dev environment
└── composer.json
```

## Coding standards
- `declare(strict_types=1);` at the top of every PHP file. PER coding style.
- Explicit types on properties, arguments and returns. `private readonly` promoted constructor properties. `final` classes.
- Throw a domain exception (`SucretsException`) with a safe message, not `null`. Pass the underlying exception as `previous`, except for the Defuse key-load case above.
- The library never writes output: no `echo`, `print`, `var_dump`, `die()` or `exit()`, and no logging. Callers decide what to show.
- Unit tests use PHPUnit attributes (`#[Test]`, `#[CoversClass]`) and must not touch the network.

## Commands
Compose lifecycle (`up`, `down`, `build`) runs on the host. **Everything else runs inside the container:**
```bash
docker compose exec client composer install
docker compose exec client composer check     # style + PHPStan (level 8) + unit tests
docker compose exec client composer cs-fix
```

## Quality gates
- PHPUnit 11, PHPStan 2 at level 8, PHP-CS-Fixer (PER).
- Not done until `composer check` passes.
- Never lower the PHPStan level, add `@phpstan-ignore`, or create a baseline.

## Git
- Conventional Commits (`feat(client): …`), lowercase, ≤ 72 chars, no trailing period.
- Only commit or push when asked.
- Releases are semver tags (`v1.0.0`). Consumers pin to them.

## AI instructions
- **Name:** your name is **Nigel**.
- Keep it small: prefer fewer files and plain code over abstractions.
- Ask before adding dependencies. Runtime deps are Defuse and the PSR interfaces only. Guzzle and the quality tools stay in `require-dev`.
