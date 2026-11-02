<?php
/**
 * Livestock alerts ("email me when new animals are listed").
 *   POST {email, breed?, category?}        — sign up. A guest gets a confirmation email first (double opt-in);
 *                                            a logged-in customer's own verified email is live at once.
 *   POST {action: confirm, token}          — confirm a sign-up (button on /ranch/alerts.php?confirm=…)
 *   POST {action: unsubscribe, token}      — stop all alerts for that address (button on /ranch/alerts.php?unsubscribe=…)
 *   POST ?unsubscribe=TOKEN, body "List-Unsubscribe=One-Click"
 *                                          — one-click unsubscribe sent by the email provider (RFC 8058). It carries no
 *                                            session or CSRF token; the secret token in the URL is the proof.
 *   GET  ?unsubscribe=TOKEN                — links in older emails: shows the confirmation page (GET never changes anything,
 *                                            so link scanners cannot unsubscribe anyone).
 */
declare(strict_types=1);

define('GX_IS_API', true);
define('GX_SKIP_MAINTENANCE', true); // unsubscribing must work even while the shop is in maintenance mode
require __DIR__ . '/../../includes/bootstrap.php';

$token = query_str('unsubscribe');
if (request_method() === 'GET') {
    redirect('/ranch/alerts.php' . (preg_match('/^[a-f0-9]{64}$/', $token) ? '?unsubscribe=' . $token : ''));
}
if (request_method() === 'POST' && $token !== '' && trim((string) ($_POST['List-Unsubscribe'] ?? '')) === 'One-Click') {
    // No IP limit: these come from the mail providers' servers (many people share a few addresses), and the
    // 256-bit token can't be guessed — at worst a request switches alerts off
    LivestockService::unsubscribe($token);
    json_ok([], 'You have been unsubscribed from livestock alerts.');
}

api_post();

switch ((string) input('action', 'subscribe')) {
    case 'confirm':
        rate_limit_or_fail('alert_token', rate_limit_ip(), 30, 3600);
        if (LivestockService::confirmAlert((string) input('token', '')) === null) {
            json_error('This confirmation link is no longer valid. Sign up again on the livestock page.', 410);
        }
        json_ok(['redirect' => '/ranch/alerts.php?done=confirmed'], "Confirmed — we'll email you when matching livestock is available.");

    case 'unsubscribe':
        rate_limit_or_fail('alert_token', rate_limit_ip(), 30, 3600);
        LivestockService::unsubscribe((string) input('token', ''));
        // Same answer whether or not the token matched (nothing to learn from it)
        json_ok(['redirect' => '/ranch/alerts.php?done=unsubscribed'], 'You have been unsubscribed from livestock alerts.');

    case 'subscribe':
        rate_limit_or_fail('livestock_alert', rate_limit_ip(), 10, 3600);
        $data = validate_or_fail(input_all(), [
            'email'    => 'required|email|max:191',
            'breed'    => 'nullable|max:80',
            'category' => 'nullable|max:80',
        ]);
        $breedId = $data['breed'] ? db_val('SELECT id FROM breeds WHERE slug = ?', [$data['breed']]) : null;
        $catId = $data['category'] ? db_val('SELECT id FROM livestock_categories WHERE slug = ?', [$data['category']]) : null;
        $customer = current_customer();
        // Live at once only for the logged-in customer's own, verified address
        $proven = $customer && !empty($customer['email_verified_at']) && mb_strtolower($customer['email']) === mb_strtolower($data['email']);
        LivestockService::subscribe($data['email'], $proven ? (int) $customer['id'] : null,
            $breedId !== null ? (int) $breedId : null, $catId !== null ? (int) $catId : null, $proven);
        // A guest always gets the same answer, so the form can't reveal who is already signed up
        json_ok(['confirm_sent' => !$proven], $proven ? "Done! We'll email you when matching livestock is available."
            : 'Almost done — check your inbox and click the link to confirm your alert.');

    default:
        json_error('Unknown action.', 400);
}
