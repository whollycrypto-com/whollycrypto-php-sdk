# Changelog

## 1.0.0 - 2026-09-13

- Initial SDK for all 17 public merchant API endpoints, tested against Wholly Crypto 3.5.0.
- Invoice creation with explicit idempotency keys, deterministic JSON and exact decimal strings.
- Search and pagination, asset/token configuration, wallet balances and reconciliation reads.
- IPN/webhook HMAC verification, bounded clock tolerance and a durable receiver example.
- Separate unauthenticated checkout reader, including Lightning-aware response preservation.
- Verified HTTPS, no redirects, bounded response sizes, typed errors, quota headers and opt-in safe retries.
- PHP 8.2+ syntax, tested on PHP 8.3; optional PHP 8.2–8.5 CI template.
- MIT license; no third-party runtime dependencies.
