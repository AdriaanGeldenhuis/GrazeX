<?php
/**
 * Push notifications in the GrazeX app: link this phone to the logged-in customer (called by assets/js/app-bridge.js).
 *   POST {action: register, token, app_version?, platform?}
 *   POST {action: unregister, token}
 */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

api_post();
$customer = require_customer_api();
rate_limit_or_fail('push_device', 'c' . $customer['id'], 30, 3600);
$token = trim((string) input('token', ''));
if (!PushService::validToken($token)) {
    json_error('This is not a valid device token.', 422, ['token' => 'This is not a valid device token.']);
}

switch ((string) input('action')) {
    case 'register':
        // Only a login this phone keeps ("Keep me logged in", on by default in the app). A session-only login on a
        // borrowed phone must not keep putting order news on its lock screen after it ends.
        if (remember_current_customer_id() !== (int) $customer['id']) {
            $_SESSION['push_device_customer'] = 0; // asked, not linked: the page does not ask again this session
            json_ok(['push' => false], 'Tick "Keep me logged in" when you log in to get notifications on this phone.');
        }
        $platform = (string) input('platform', 'android');
        $id = PushService::register($token, 'customer', (int) $customer['id'], (int) $customer['session_version'],
            trim((string) input('app_version', '')) ?: null, in_array($platform, ['android', 'ios', 'web'], true) ? $platform : 'android');
        $_SESSION['push_device_customer'] = $id;
        json_ok(['push' => PushService::configured()], 'Notifications are on for this phone.');

    case 'unregister':
        PushService::detachToken($token, 'customer', (int) $customer['id']);
        unset($_SESSION['push_device_customer'], $_SESSION['push_device_customer_day']);
        json_ok([], 'Notifications are off for this phone.');

    default:
        json_error('Unknown action.', 400);
}
