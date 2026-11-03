<?php
/**
 * English UI strings. To add Afrikaans: copy this file to af.php, translate the values,
 * and set the setting `site_language` to `af` (Admin > Settings).
 */
declare(strict_types=1);

return [
    // Navigation
    'nav.home'          => 'Home',
    'nav.feeds'         => 'Feeds',
    'nav.ranch'         => 'Ranch',
    'nav.shop'          => 'Shop',
    'nav.specials'      => 'Specials',
    'nav.calculator'    => 'Feed calculator',
    'nav.livestock'     => 'Available stock',
    'nav.model'         => 'Our model',
    'nav.contact'       => 'Contact',
    'nav.faq'           => 'FAQ',
    'nav.account'       => 'My account',
    'nav.login'         => 'Log in',
    'nav.logout'        => 'Log out',
    'nav.cart'          => 'Cart',
    'nav.menu'          => 'Menu',
    'nav.close'         => 'Close',
    'nav.skip'          => 'Skip to content',

    // Generic
    'common.save'        => 'Save',
    'common.cancel'      => 'Cancel',
    'common.continue'    => 'Continue',
    'common.back'        => 'Back',
    'common.search'      => 'Search',
    'common.loading'     => 'Loading…',
    'common.view_all'    => 'View all',
    'common.incl_vat'    => 'incl. VAT',
    'common.in_stock'    => 'In stock',
    'common.low_stock'   => 'Only {qty} left',
    'common.out_of_stock'=> 'Out of stock',
    'common.whatsapp'    => 'WhatsApp us',
    'common.directions'  => 'Get directions',
    'common.call'        => 'Call us',
    'common.email'       => 'Email',
    'common.required'    => 'Required',
    'common.optional'    => 'Optional',

    // Errors
    'error.generic'      => 'Something went wrong. Please try again.',
    'error.validation'   => 'Please check the highlighted fields.',
    'error.csrf'         => 'Your session expired. Please refresh the page and try again.',
    'error.rate_limited' => 'Too many attempts. Please wait a few minutes and try again.',
    'error.login_required' => 'Please log in to continue.',
    'error.not_found'    => 'We could not find that page.',
    'error.forbidden'    => 'You do not have access to this page.',

    // Footer
    'footer.rights'      => 'All rights reserved.',
    'footer.divisions'   => 'Ranch · Feeds · Livestock',
    'footer.hours'       => 'Hours',
    'footer.visit'       => 'Visit us',
    'footer.info'        => 'Information',
    'footer.shop'        => 'Shop',

    // Gateway
    'gateway.enter_feeds'   => 'Shop Feeds',
    'gateway.enter_ranch'   => 'Enter the Ranch',
    'gateway.lots_available'=> 'lots available now',
    'gateway.products'      => 'feed products in stock',
    'gateway.specials'      => 'specials this week',
    'gateway.explore_ranch' => 'Explore Ranch',
    'gateway.shop_feeds'    => 'Shop Feeds',

    // Setup
    'setup.title'        => 'Set up GrazeX',

    // Push notifications in the app (title + body per email template; a template without these sends no push)
    'push.payment_received.title'       => 'Payment received — {order_no}',
    'push.payment_received.body'        => 'Thank you! We received {amount}.',
    'push.order_ready.title'            => 'Order {order_no} is ready',
    'push.order_ready.body'             => 'Your order is ready for collection.',
    'push.order_out_for_delivery.title' => 'Order {order_no} is on its way',
    'push.order_out_for_delivery.body'  => 'Your order is out for delivery.',
    'push.order_completed.title'        => 'Order {order_no} completed',
    'push.order_completed.body'         => 'Thank you! Your documents are under My orders.',
    'push.order_cancelled.title'        => 'Order {order_no} cancelled',
    'push.order_cancelled.body'         => 'Tap to see the details.',
    'push.order_expired.title'          => 'Order {order_no} expired',
    'push.order_expired.body'           => 'It was not paid in time, so the stock was released.',
    'push.order_refunded.title'         => 'Order {order_no} refunded',
    'push.order_refunded.body'          => 'Your refund has been processed.',
    'push.order_finalised.title'        => 'Final invoice for {order_no}',
    'push.order_finalised.body'         => 'The weights are confirmed. Balance due: {balance_due}.',
    'push.payment_reminder.title'       => 'Payment due for {order_no}',
    'push.payment_reminder.body'        => 'Pay {amount_due} before {expires_at} to keep your order.',
    'push.pop_rejected.title'           => 'Proof of payment not accepted',
    'push.pop_rejected.body'            => 'Please upload a new proof of payment for order {order_no}.',
    'push.livestock_alert.title'        => 'New livestock available',
    'push.livestock_alert.body'         => '{lot_title} matches your alert.',
];
