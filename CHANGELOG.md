# Changelog

## 1.1.0 - 2026-09-14

- Support PHP 7.4+ with Composer or the standalone loader, without third-party runtime dependencies.
- Replace PHP 8-only syntax/helpers and accept both PHP 7 cURL resources and PHP 8 cURL objects.
- Preserve read-only value access through private storage/getters, reject reinitialization and retain public JSON shapes. Native readonly reflection and get_object_vars are not the property interface.
- Keep existing PHP 8 named arguments and SensitiveParameter protection; document safe trace settings on older runtimes.
- Make all examples and tests PHP 7.4-compatible, expand the optional CI matrix and note PHP 7.4's upstream end of security support.
- No API, amount encoding, idempotency or callback-signature changes. Still targets merchant API v1, tested against merchant 3.5.0.

## 1.0.2 - 2026-09-14

- Add a standalone `autoload.php` for manual ZIP/Git installations without Composer or a `vendor/` directory.
- Document manual installation and direct `new \WhollyCrypto\Client(...)` usage without `use` imports.
- Make the runnable invoice and IPN/webhook examples work directly from an extracted SDK ZIP.
- Exclude generated dependencies, local lockfiles and credential files from Composer archives.
- Test standalone loading, namespaced applications and Composer coexistence on PHP 8.1 and 8.3.
- No API, amount encoding, idempotency or callback-signature changes. Still targets merchant API v1, tested against merchant 3.5.0.

## 1.0.1 - 2026-09-13

- Support PHP 8.1+ with a matching Composer requirement and PHP 8.1–8.5 CI template.
- Replace PHP 8.2-only readonly classes with readonly properties while preserving immutable values and rejecting dynamic properties.
- Add compatibility regressions and document safe exception logging on PHP 8.1.
- All 13 test suites and syntax checks pass on PHP 8.1.34 and PHP 8.3.6.
- No API, amount encoding, idempotency or callback-signature changes.

## 1.0.0 - 2026-09-13

- Initial SDK for all 17 public merchant API endpoints, tested against Wholly Crypto 3.5.0.
- Invoice creation with explicit idempotency keys, deterministic JSON and exact decimal strings.
- Search and pagination, asset/token configuration, wallet balances and reconciliation reads.
- IPN/webhook HMAC verification, bounded clock tolerance and a durable receiver example.
- Separate unauthenticated checkout reader, including Lightning-aware response preservation.
- Verified HTTPS, no redirects, bounded response sizes, typed errors, quota headers and opt-in safe retries.
- PHP 8.2+ syntax, tested on PHP 8.3; optional PHP 8.2–8.5 CI template.
- MIT license; no third-party runtime dependencies.
