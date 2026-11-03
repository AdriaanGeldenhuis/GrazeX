<?php
/**
 * Push notifications in the GrazeX app for staff (new orders, proofs of payment, refunds — per permission).
 *   POST {action: register, token, app_version?, platform?}   — link this phone to the logged-in staff member
 *   POST {action: unregister, token}
 *   POST {action: test}                                        — send a test notification to your own phones
 */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

api_post();
$admin = require_admin_api();
$aid = (int) $admin['id'];
$action = (string) input('action');

if ($action === 'test') {
    rate_limit_or_fail('push_test', 'a' . $aid, 5, 3600);
    if (!PushService::configured()) {
        json_error('Push notifications are not set up yet: add the Firebase service-account file (see appfiles/README.md).', 409);
    }
    $n = PushService::toAdminUser($aid, 'GrazeX test notification', 'Push notifications work on this phone.', '/admin/');
    if (!$n) {
        json_error('None of your phones is linked yet. Open the admin in the GrazeX app and allow notifications.', 409);
    }
    json_ok(['queued' => $n], 'Test notification sent to ' . $n . ' phone' . ($n > 1 ? 's' : '') . '.');
}

rate_limit_or_fail('push_device', 'a' . $aid, 30, 3600);
$token = trim((string) input('token', ''));
if (!PushService::validToken($token)) {
    json_error('This is not a valid device token.', 422, ['token' => 'This is not a valid device token.']);
}
switch ($action) {
    case 'register':
        $platform = (string) input('platform', 'android');
        $id = PushService::register($token, 'admin', $aid, (int) $admin['session_version'],
            trim((string) input('app_version', '')) ?: null, in_array($platform, ['android', 'ios', 'web'], true) ? $platform : 'android');
        $_SESSION['push_device_admin'] = $id;
        json_ok(['push' => PushService::configured()], 'Staff notifications are on for this phone.');

    case 'unregister':
        PushService::detachToken($token, 'admin', $aid);
        unset($_SESSION['push_device_admin'], $_SESSION['push_device_admin_day']);
        json_ok([], 'Staff notifications are off for this phone.');

    default:
        json_error('Unknown action.', 400);
}
