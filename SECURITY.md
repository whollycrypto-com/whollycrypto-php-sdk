# Security

Report suspected vulnerabilities privately through
[GitHub private vulnerability reporting](https://github.com/whollycrypto-com/whollycrypto-php-sdk/security/advisories/new)
or the [Wholly Crypto contact form](https://www.whollycrypto.com/contact/).
Do not put credentials, signing secrets, wallet keys or customer details in public issues.

Use the current 1.x SDK release and a supported PHP version. The SDK only talks
to the merchant API you configure. It does not hold wallet keys, send funds,
configure node credentials or implement the merchant application's billing system.

Keep API and notification secrets on the server. Use the least project/access
scope needed and IP restrictions where appropriate. Never disable TLS checks,
derive API origins from untrusted requests or use untrusted custom transports.
Avoid logging remote error bodies and `getApiMessage()` without redaction.

A successful callback signature is not an exactly-once delivery guarantee.
Persist deduplication and monotonic invoice sequence/state, then reconcile the
invoice and stored order through the authenticated merchant API before fulfilment.
Return 2xx only after durable processing/queueing. See the README for the signed
fields and clock-window behavior.
