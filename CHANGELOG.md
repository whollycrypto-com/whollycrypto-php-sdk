# Changelog

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
