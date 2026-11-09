# sucrets-client

A small **demo client** for sucrets, the internal credentials service. It shows the whole round trip: request tokens from the sucrets HTTP API, get Defuse ciphertext back, and decrypt it locally with the shared key. Keep it small and readable. It's an example for other teams, not a framework.

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
- The demo proves decryption worked **without revealing values**. It shows, per token, that it decrypted, plus the plaintext length and a masked preview at most (e.g. `ab…` / `•••`). Don't add a flag that prints full values.
- **Ciphertext** is Defuse `php-encryption` **v2** hex (starts with `def50200`). Decrypt with `Crypto::decrypt($ciphertext, Key::loadFromAsciiSafeString($asciiKey))`. Catch `WrongKeyOrModifiedCiphertextException` and report the *token name* only.
- **Key sources:** both keys come from a Docker secret file first, falling back to an env var. They never come from code, the repo, or CLI arguments.
  - API key: `SUCRETS_API_KEY_FILE` (default `/run/secrets/sucrets_api_key`), fallback `SUCRETS_API_KEY`.
  - Defuse key: `SUCRETS_ENCRYPTION_KEY_FILE` (default `/run/secrets/sucrets_encryption_key`), fallback `SUCRETS_ENCRYPTION_KEY`.
  - Trim one trailing newline from files.
- Local secret files live in `.secrets/` (git-ignored, `chmod 600`). Never commit `.env` or `.secrets/`.

## Tech stack
- **PHP 8.5**, strict types everywhere.
- **Guzzle 7 through PSR-18:** type-hint `Psr\Http\Client\ClientInterface` + PSR-17 `RequestFactoryInterface`, call `sendRequest()`.
  - Check `getStatusCode()` explicitly.
  - Catch `ClientExceptionInterface` (transport) and `\JsonException` (decode with `JSON_THROW_ON_ERROR`).
  - Timeouts: connect 2s, total 5s.
- **defuse/php-encryption ^2.4**
- **Symfony Console** for the demo command (`bin/demo`), output via `SymfonyStyle`.
- No framework, no DI container, no database. Wire the few objects by hand in `bin/demo`.
- **Docker & Compose:** service name `client`, image based on `php:8.5-cli`.

## Layout
```text
├── bin/demo                 # entry point: builds the objects, runs the command
├── src/                     # Namespace: SucretsClient\
│   ├── SucretsClient.php    # fetch(tokens) → FetchResult (ciphertexts, missing, denied); throws SucretsException
│   ├── Decryptor.php        # ciphertext → plaintext with the Defuse key
│   ├── KeyLoader.php        # reads a key from file, else env; throws if neither
│   └── Command/DemoCommand.php
├── tests/Unit/              # Guzzle MockHandler; no network
├── .secrets/                # api_key, encryption_key (git-ignored)
├── docker-compose.yml
└── composer.json
```

## Coding standards
- `declare(strict_types=1);` at the top of every PHP file. PER coding style.
- Explicit types on properties, arguments and returns. `private readonly` promoted constructor properties. `final` classes.
- Throw a domain exception (`SucretsException`) with a safe message, not `null`. Pass the underlying exception as `previous`.
- Never `echo`, `print`, `var_dump`, `die()` or `exit()`. Write output through `SymfonyStyle`. Exit codes: `Command::SUCCESS` / `FAILURE` / `INVALID`.
- Unit tests use PHPUnit attributes (`#[Test]`, `#[CoversClass]`) and must not touch the network.

## Commands
Compose lifecycle (`up`, `down`, `build`) runs on the host. **Everything else runs inside the container:**
```bash
docker compose exec client composer install
docker compose exec client bin/demo MEZMO_KEY OTHER_TOKEN
docker compose exec client composer check     # style + PHPStan (level 8) + unit tests
```

## Quality gates
- PHPUnit 11, PHPStan 2 at level 8, PHP-CS-Fixer (PER).
- Not done until `composer check` passes.
- Never lower the PHPStan level, add `@phpstan-ignore`, or create a baseline.

## Git
- Conventional Commits (`feat(client): …`), lowercase, ≤ 72 chars, no trailing period.
- Only commit or push when asked.

## AI instructions
- **Name:** your name is **Nigel**.
- Keep it a demo: prefer fewer files and plain code over abstractions. Ask before adding dependencies beyond Guzzle, Defuse, Symfony Console and the dev tools.
