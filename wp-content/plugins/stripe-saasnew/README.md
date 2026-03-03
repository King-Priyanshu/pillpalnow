# Stripe SaaS - WordPress Subscription Plugin

A **self-contained**, **theme-independent** WordPress plugin that implements Stripe Hosted Checkout subscriptions with webhook-driven access control, advanced free trial management, and multi-tier pricing.

## Features

✅ **Stripe Hosted Checkout Only** - No client-side Stripe.js, no Elements  
✅ **Webhook-Driven Access** - All subscription changes controlled by Stripe webhooks  
✅ **Admin Configurable** - Change prices, trials, intervals without code changes  
✅ **No Hardcoded IDs** - Uses `price_data` and metadata for everything  
✅ **Access Modes** - Subscribe First or Free First with automatic trial management  
✅ **Server-Side Free Trials** - NO cron jobs, NO JavaScript timers  
✅ **Multi-Tier Pricing** - Monthly, Yearly, 3-Year subscriptions + One-Time enterprise  
✅ **One-Time Payments** - Lifetime access with single payment  
✅ **Theme Independent** - Works with any WordPress theme  
✅ **Update Safe** - Survives WordPress, theme, and plugin updates (2026-2030+)  
✅ **Domain Locked** - Metadata prevents cross-site subscription hijacking

## Installation

1. **Upload Plugin**
   ```bash
   cd wp-content/plugins
   # Extract stripe-saas.zip or clone repository
   ```

2. **Install Dependencies**
   ```bash
   cd stripe-saas
   composer install --no-dev
   ```

3. **Configure API Keys** (add to `wp-config.php`)
   ```php
   define('STRIPE_SECRET_KEY', 'sk_test_...');
   define('STRIPE_WEBHOOK_SECRET', 'whsec_...');
   ```

4. **Activate Plugin** via WordPress admin (`Plugins > Installed Plugins`)

## Configuration

### 1. Global Settings

Navigate to **Settings > Stripe SaaS**

#### Access Mode

Choose how users get access:

**Subscribe First (Default)**
- User MUST subscribe before getting any access
- No access until subscription is complete
- 14-day free trial applies AFTER subscription checkout
- Best for: Paid products with optional trials

**Free First**
- User gets 14-day full access WITHOUT subscribing
- Trial starts automatically on first login
-Subscription required ONLY after trial expires
- Best for: Freemium products, trial-first experiences

**Trial Duration**: Set global trial length (default: 14 days)

### 2. Subscription Plans

**Default Plans (A1-D1 Structure)**:

| Tier | Name | Price | Interval | Type |
|------|------|-------|----------|------|
| A1 | Monthly - Individual | $29.00 | 1 month | Subscription |
| A2 | Monthly - Group | $23.20 | 1 month | Subscription |
| B1 | Yearly - Individual | $295.00 | 1 year | Subscription |
| B2 | Yearly - Group | $236.00 | 1 year | Subscription |
| C1 | 3-Year - Individual | $835.00 | 3 years | Subscription |
| C2 | 3-Year - Group | $668.00 | 3 years | Subscription |
| D1 | Enterprise - Custom | $5,000.00 | N/A | One-Time |

**Load Default Plans**: Use the "Load Default Plans (A1-D1)" button to reset to this structure.

**Per-Plan Configuration**:
- **Display Name** - Customer-facing name
- **Plan Type** - Subscription (recurring) or One-Time (lifetime)
- **Access Level** - Individual, Group, or Enterprise
- **Price (cents)** - e.g., 2900 = $29.00
- **Billing Interval** - day, week, month, year
- **Interval Count** - e.g., 3 for "every 3 years"
- **Trial Days** - Free trial period (0 for none, subscription-only)
- **WordPress Role** - Role to assign (e.g., `subscriber`)
- **Access Meta Key** - User meta key for access checks

### 3. Stripe Webhook

Configure this URL in your [Stripe Dashboard](https://dashboard.stripe.com/webhooks):

```
https://yoursite.com/wp-json/stripe-saas/v1/webhook
```

**Events to enable:**
- `checkout.session.completed`
- `invoice.paid`
- `customer.subscription.created`
- `customer.subscription.updated`
- `customer.subscription.deleted`
- `payment_intent.succeeded` (for one-time payments)
- `payment_intent.payment_failed` (for OneSignal notifications)

### 4. OneSignal Integration (Optional)

The plugin includes **webhook-driven push notifications** via OneSignal for critical subscription events.

**Configuration** (add to `wp-config.php`):
```php
// OneSignal credentials
define('ONESIGNAL_APP_ID', 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx');
define('ONESIGNAL_REST_KEY', 'YOUR_REST_API_KEY');

// Enable integration (optional - auto-detects if OneSignal is configured)
define('STRIPE_SAAS_ONESIGNAL_ENABLED', true);

// Days before expiration to send warning (optional - default: 3)
define('STRIPE_SAAS_EXPIRY_WARNING_DAYS', 3);
```

**Notifications Sent**:

| Event | Trigger | Message |
|-------|---------|---------|
| **Subscription Expiring** | 3 days before renewal | "Your subscription expires in X days! Renew now..." |
| **Subscription Cancelled** | User cancels subscription | "We miss you! Get 25% off for 1 year with code [CODE]" |
| **Payment Failed** | Payment declined | "Payment failed! Please update your card details..." |

**Features**:
- ✅ Fully automated (100% webhook-driven)
- ✅ Idempotent (no duplicate notifications)
- ✅ One-time 25% win-back discount for cancelled users
- ✅ Automatic coupon creation in Stripe
- ✅ Discount automatically reverts to standard pricing after 12 months

**Requirements**:
- Users must have `onesignal_player_id` stored in user meta
- `Dosecast_OneSignal_Service` class must be available

See [Setup Guide](../../../.gemini/antigravity/brain/97df3c50-7629-4dad-b9d9-c66eb874d595/setup_guide.md) for detailed configuration and testing instructions.

### 5. Frontend Usage

**Display All Plans** (recommended):
```
[choose_plan]
```

**Display Specific Plan**:
```
[stripe_saas_subscribe tier="a1_monthly_individual"]
[stripe_saas_subscribe tier="b1_yearly_individual" button_text="Get Started"]
```

## How It Works

### Access Modes

#### Subscribe First Flow
1. User registers/logs in → **NO ACCESS**
2. User navigates to `[choose_plan]` page
3. Clicks "Start Free Trial" (or "Subscribe Now")
4. Completes Stripe Hosted Checkout
5. Webhook `checkout.session.completed` → **ACCESS GRANTED**
6. 14-day trial starts (if plan has trial_days > 0)
7. After trial → Billing begins

#### Free First Flow
1. User registers/logs in → **TRIAL AUTO-STARTS** (14 days)
2. Full access granted immediately
3. User uses product for 14 days
4. Trial expires → **ACCESS BLOCKED**
5. User sees `[choose_plan]` page with "Upgrade Now" buttons
6. Subscribes → **ACCESS RESTORED**

### One-Time Payments

**Enterprise Plans**:
- Plan Type: `one_time`
- Checkout Mode: `payment` (not `subscription`)
- Grant Method: `grant_permanent($user_id)`
- Access: Never expires
- No renewals, no trials

### Security

- ✅ **Signature Verification** - All webhooks verified using `STRIPE_WEBHOOK_SECRET`
- ✅ **Domain Validation** - Metadata ensures requests match your site
- ✅ **Nonce Protection** - AJAX endpoints secured with WordPress nonces
- ✅ **Server-Side Secrets** - No API keys exposed to frontend

## Access Control

### Checking Access (Code Examples)

**Basic Check**:
```php
$user_id = get_current_user_id();
$has_access = Stripe_SaaS_Access::user_has_access($user_id);

if (!$has_access) {
    // Show upgrade message
    echo do_shortcode('[choose_plan]');
    return;
}
```

**Trial Status**:
```php
$trial_status = Stripe_SaaS_Access::get_trial_status($user_id);
// Returns: 'active', 'expired', or 'none'

if ($trial_status === 'active') {
    $days_remaining = Stripe_SaaS_Access::get_trial_days_remaining($user_id);
    echo "Trial: {$days_remaining} days remaining";
}
```

**Subscription Details**:
```php
$tier = Stripe_SaaS_Access::get_tier($user_id); // e.g., 'a1_monthly_individual'
$status = get_user_meta($user_id, '_stripe_saas_status', true); // 'active', 'trial', 'permanent', 'cancelled'
$expiry = Stripe_SaaS_Access::get_expiry($user_id); // Unix timestamp
```

### Front-End Blocking (Optional)

To enforce access control site-wide, uncomment this line in `class-stripe-saas-core.php`:

```php
// Line ~86
add_action('template_redirect', [$this, 'check_saas_access']);
```

**Customize allowed pages**:
```php
$allowed_pages = ['login', 'register', 'choose-plan', 'pricing'];
```

Users without access will be redirected to `/choose-plan/`.

## Testing

### Test Cards

Use [Stripe test cards](https://stripe.com/docs/testing):
- **Success**: `4242 4242 4242 4242`
- **Decline**: `4000 0000 0000 0002`
- **3D Secure**: `4000 0025 0000 3155`

### Local Webhook Testing

1. Install [Stripe CLI](https://stripe.com/docs/stripe-cli)

2. Forward webhooks to local:
   ```bash
   stripe listen --forward-to localhost:8000/wp-json/stripe-saas/v1/webhook
   ```

3. Trigger test events:
   ```bash
   stripe trigger checkout.session.completed
   stripe trigger invoice.paid
   stripe trigger customer.subscription.deleted
   stripe trigger payment_intent.succeeded
   ```

### Test Scenarios

**Subscribe First Mode**:
1. Set Access Mode to "Subscribe First"
2. Log in as test user
3. Verify NO access to content
4. Subscribe via `[choose_plan]`
5. Complete checkout → Access granted
6. Cancel subscription in Stripe Dashboard → Access revoked

**Free First Mode**:
1. Set Access Mode to "Free First"
2. Create new test user account
3. Log in → Trial starts automatically
4. Verify access granted for 14 days
5. Manually set trial expiry to past date:
   ```php
   update_user_meta($user_id, '_stripe_saas_trial_end', time() - DAY_IN_SECONDS);
   ```
6. Refresh page → Access blocked
7. Subscribe → Access restored

**One-Time Payment**:
1. Enable D1 (Enterprise) plan in admin
2. Complete checkout for D1
3. Webhook grants permanent access
4. Verify `_stripe_saas_is_permanent` = 1
5. Confirm access persists indefinitely

## Troubleshooting

### Free Trials Not Starting

1. Check Access Mode is set to "Free First"
2. Verify user doesn't already have `_stripe_saas_trial_granted` meta
3. Check error logs for `wp_login` action execution
4. Manually trigger:
   ```php
   Stripe_SaaS_Access::start_free_trial($user_id);
   ```

### Webhooks Not Working

1. Check `STRIPE_WEBHOOK_SECRET` in `wp-config.php`
2. Verify endpoint URL in Stripe Dashboard
3. Enable `WP_DEBUG_LOG` and check `/wp-content/debug.log`
4. Test signature: `stripe trigger checkout.session.completed`
5. Check domain metadata matches your site URL

### Access Not Granted

1. Check webhook logs in WordPress debug.log
2. Verify metadata domain matches your site
3. Check `wp_usermeta` table for:
   - `_stripe_customer_id`
   - `_stripe_subscription_id` (subscriptions)
   - `_stripe_payment_intent_id` (one-time)
   - `has_saas_access` = 1
4. Ensure plan is enabled in admin settings

### One-Time Payments Not Working

1. Verify plan `plan_type` = `one_time` in database
2. Check checkout mode is `payment` (not `subscription`)
3. Look for `payment_intent.succeeded` webhook event
4. Verify `grant_permanent()` was called in logs
5. Check `_stripe_saas_status` = `permanent`

## Architecture Compliance

### ✅ Single Plugin
- All code in `wp-content/plugins/stripe-saas/`
- NO MU-plugins required
- NO theme files needed

### ✅ No Hardcoded IDs
- Products/Prices created dynamically using `price_data`
- Metadata used for resolution (`wp_domain` + `tier_slug` + `plan_type`)
- Prices stored in WordPress options, not code

### ✅ Hosted Checkout Only
- Creates `\Stripe\Checkout\Session` server-side
- Returns URL for redirect
- NO client-side Stripe.js beyond redirect
- NO embedded payment forms

### ✅ Webhook Authority
- ALL access grants/revokes happen in webhook handlers
- NO cron jobs for trials (server-side timestamps)
- NO frontend access checks for payment status
- Fail-safe: No webhook = no access

### ✅ Update Safe (2026-2030+)
- Uses WordPress core APIs only
- Bundled Stripe SDK (no external dependencies)
- Settings-driven (no code changes for price updates)
- Theme-independent (works with any theme)
- No scheduled tasks

## API Reference

### Stripe_SaaS_Access

**Access Control**:
- `user_has_access($user_id)` - Check if user has any access (trial OR subscription OR permanent)
- `has_access($user_id, $meta_key)` - Check specific meta key
- `grant($user_id, $tier_slug)` - Grant subscription access
- `grant_permanent($user_id, $tier_slug)` - Grant permanent access (one-time)
- `revoke($user_id)` - Remove all access

**Trial Management**:
- `start_free_trial($user_id)` - Start 14-day trial
- `get_trial_status($user_id)` - Returns 'active', 'expired', 'none'
- `get_trial_days_remaining($user_id)` - Integer days left
- `is_trial_expired($user_id)` - Boolean check

**Subscription Info**:
- `get_tier($user_id)` - Returns tier slug (e.g., 'a1_monthly_individual')
- `get_status($user_id)` - Returns status ('active', 'trial', 'permanent', 'cancelled')
- `get_expiry($user_id)` - Returns Unix timestamp
- `update_expiry($user_id, $timestamp)` - Set new expiry

**Global Settings**:
- `get_access_mode()` - Returns 'subscribe_first' or 'free_first'

## License

GPL v2 or later

## Support

For issues, please check:
1. WordPress debug logs (`/wp-content/debug.log`)
2. Stripe webhook logs in Dashboard
3. Browser console errors
4. User meta in `wp_usermeta` table

---

**Plugin Version:** 1.0.0  
**WordPress Required:** 6.0+  
**PHP Required:** 7.4+  
**Stripe API Version:** 2024-11-20.acacia  
**Production Ready:** ✅ 2026-2030+
