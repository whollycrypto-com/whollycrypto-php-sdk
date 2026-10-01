<?php
declare(strict_types=1);
// Server-side template, PHP 7.4+. Calls create real accounts: review before use.
require dirname(__DIR__) . '/autoload.php'; // Or vendor/autoload.php with Composer.
$operator = new WhollyCrypto\OperatorClient(getenv('WHOLLY_API_URL'), getenv('WHOLLY_OPERATOR_TOKEN'));
// Persist this key AND the exact body in your application before the first call.
$requestKey = getenv('WHOLLY_PROVISION_REQUEST_KEY');
if (!$requestKey) { throw new RuntimeException('Set your persisted provisioning request key'); }
$merchant = $operator->createMerchant([
    'name' => 'Example shop', 'email' => getenv('WHOLLY_NEW_ADMIN_EMAIL'),
    'external_id' => 'your-customer-1042', 'onboarding' => 'direct',
    'password' => getenv('WHOLLY_NEW_ADMIN_PASSWORD'), 'require_password_change' => true,
    'currency' => 'EUR', 'default_timezone' => 'Europe/Berlin', 'starting_credit' => '0',
], $requestKey);
echo $merchant['merchant_id'] . PHP_EOL; // Never log the response's private links or tokens.
// Invitation alternative: onboarding='invitation', omit password and optionally
// send_invitation_email=true (configured SMTP). Save access_link privately once.
// Replays omit secrets; inspect the account instead of making duplicate grants.

// In your invitation acceptance endpoint, after the recipient explicitly agrees:
// $onboarding = new WhollyCrypto\OperatorOnboardingClient(getenv('WHOLLY_API_URL'));
// $onboarding->acceptInvitation($privateToken, $chosenPassword, $custodyAcknowledged);
// Then use normal console sign-in. Existing Basic Auth and TOTP are preserved.
