# SDK maintenance

SDK semantic versions are independent of merchant versions. State the merchant
version/API contract tested in the changelog and fixture. Do not copy merchant
server implementation, private documentation, credentials or wallet data into
this repository. This repository is only the public client and its inert examples.

When a public merchant route, request, response or permission changes:

1. Review the current public API reference.
2. Update client methods, documentation and `tests/fixtures/api-v1.json` together.
3. Add wire-level, error, precision and callback tests as relevant.
4. Run `composer validate --strict`, `composer lint`, `composer test` and the PHP matrix when CI is enabled.
5. Review the diff and archive contents, update `Client::VERSION` and changelog,
   then create an immutable semantic-version Git tag and GitHub release.
6. Verify Packagist sees the version and test a clean `composer require`.

The cURL tests use disposable loopback HTTP/TLS services and local SQLite only.
Development tests require OpenSSL CLI/PHP and PDO SQLite in addition to cURL/JSON.
Never substitute real credentials or a live payment API into these fixtures.

If the public documentation sources are available locally, check drift with:

```bash
node tools/check-api-coverage.mjs /path/to/api-docs.js /path/to/api-examples.js
```

Packagist indexes Git tags. Configure its GitHub webhook for this repository's
push events so future SDK tags are indexed automatically. API tokens belong in
private operator credential storage, never in Git, Composer metadata or workflow
files. The template in `ci/github-actions.yml` needs only read access at runtime
and contains no publishing secret. Enabling it requires an operator with GitHub
workflow-write permission to copy it into `.github/workflows/tests.yml`.

Changes to request canonicalization need special care: invoice idempotency uses
exact body bytes. Do not silently change a published release's encoding behavior.
