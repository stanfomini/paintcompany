# Research: Stripe Connect & Increase OAuth Authentication
*Generated: 2026-02-06*

## Question
Can we avoid storing raw API keys in `.env` files by using OAuth-based authentication for Stripe Connect and Increase?

## TL;DR

| Platform | OAuth Available? | Recommended for Our Use Case? | What Gets Stored |
|----------|-----------------|-------------------------------|------------------|
| Stripe Connect | Yes (legacy), but deprecated for new platforms | **No** -- use Account Links + platform API key | Platform API key (`.env`) + connected account IDs (database) |
| Increase | Yes, full OAuth 2.0 | **Yes** -- ideal for multi-tenant platforms | OAuth app `client_id` + `client_secret` (`.env`) + per-user `access_token` (database) |

**Bottom line**: Neither platform fully eliminates `.env` credentials. Stripe requires your platform API keys in `.env` regardless. Increase's OAuth replaces per-user API keys with OAuth tokens but still requires app-level `client_id`/`client_secret` in `.env`. The real win is that OAuth avoids asking users to copy-paste API keys and provides scoped, revocable access.

---

## Stripe Connect

### How Authentication Works for Connect Platforms

Stripe Connect uses a **platform-centric** authentication model. Your platform (Apex Negotiations) has its own Stripe API keys, and all API calls to connected accounts go through your platform using the `Stripe-Account` header.

#### The Standard Approach (Recommended for New Platforms)

1. **Platform credentials**: Your Stripe secret key and publishable key are stored in `.env`
2. **Connect Onboarding**: You create an Account Link via the API, redirect the user to Stripe-hosted onboarding
3. **Connected account ID**: Stripe returns an `acct_XXXX` identifier you store in your database
4. **API calls**: All requests use your platform API key + `Stripe-Account: acct_XXXX` header

```php
// Creating a connected account
$account = $stripe->accounts->create(['type' => 'express']);

// Creating an onboarding link
$accountLink = $stripe->accountLinks->create([
    'account' => $account->id,
    'refresh_url' => 'https://example.com/reauth',
    'return_url' => 'https://example.com/return',
    'type' => 'account_onboarding',
]);

// Making API calls on behalf of connected account
$stripe->charges->create(
    ['amount' => 1000, 'currency' => 'usd'],
    ['stripe_account' => 'acct_XXXX']
);
```

#### The OAuth Approach (Legacy/Deprecated)

Stripe's OAuth flow for Connect is **not recommended for new platforms** as of 2024:

> "OAuth is not recommended for new Connect platforms. We recommend using Connect Onboarding for Standard accounts instead."

The legacy OAuth flow worked like this:
1. Redirect user to `https://connect.stripe.com/oauth/authorize?client_id=XXX`
2. User authorizes on Stripe
3. Stripe redirects back with authorization code
4. Exchange code for `stripe_user_id`, `access_token`, `refresh_token`

Even with OAuth, the `stripe_user_id` (connected account ID) is what you actually use for API calls -- the access token from OAuth was always secondary to the `Stripe-Account` header approach.

#### What Credentials Must Be Stored

| Credential | Where Stored | Purpose |
|-----------|-------------|---------|
| `STRIPE_KEY` (publishable) | `.env` | Client-side Stripe.js |
| `STRIPE_SECRET` | `.env` | Server-side API calls |
| `STRIPE_WEBHOOK_SECRET` | `.env` | Webhook signature verification |
| Connected account `acct_XXXX` | Database (per company) | Identifying connected accounts |

**There is no way to avoid storing your platform API keys in `.env`.** This is by design -- your platform is the authenticated party, not individual users.

### Account Types for Apex

For a painting company platform, **Express accounts** are the best fit:
- Stripe handles identity verification and compliance
- Platform controls payout settings
- Stripe provides a limited Express Dashboard for connected accounts
- Branded onboarding flow with your logo/colors

---

## Increase

### OAuth Is Available and Well-Suited for Multi-Tenant Use

Increase offers a **full OAuth 2.0 authorization code flow**, specifically designed for platforms that need to access Increase on behalf of other users/companies.

#### How It Works

1. **Register OAuth Application**: In the Increase Dashboard, create an app with a name and redirect URL. Receive `client_id` and `client_secret`.

2. **User Authorization**: Redirect users to Increase's authorization endpoint:
   ```
   https://increase.com/oauth/authorize?client_id=YOUR_CLIENT_ID&scope=read_write&state=RANDOM_STATE
   ```

3. **Authorization Code**: User grants access, Increase redirects to your app with a short-lived code (expires in 60 seconds).

4. **Token Exchange**: Exchange the code for a long-lived access token:
   ```bash
   POST /oauth/tokens
   {
     "client_id": "your_client_id",
     "client_secret": "your_client_secret",
     "code": "authorization_code_from_redirect",
     "grant_type": "authorization_code"
   }
   ```

5. **Response**:
   ```json
   {
     "access_token": "RI5vGMbBv8UPqUhk0YHZ45hG2XpEDzDp",
     "token_type": "bearer"
   }
   ```

6. **API Calls**: Use the access token as a Bearer token:
   ```
   Authorization: Bearer RI5vGMbBv8UPqUhk0YHZ45hG2XpEDzDp
   ```

#### Deauthorization

Users can revoke access at any time from their Increase Dashboard. When this happens:
- Increase sends an `oauth_connection.deactivated` webhook
- Subsequent API calls with that token return `401 invalid_api_key_error`

#### What Credentials Must Be Stored

| Credential | Where Stored | Purpose |
|-----------|-------------|---------|
| `INCREASE_OAUTH_CLIENT_ID` | `.env` | Identifies your OAuth app |
| `INCREASE_OAUTH_CLIENT_SECRET` | `.env` | Authenticates token exchange |
| Per-user `access_token` | Database (encrypted) | API calls on behalf of that user |

#### OAuth vs Direct API Key

| Aspect | OAuth | Direct API Key |
|--------|-------|---------------|
| User experience | User authorizes via Increase UI | User copies key from Increase Dashboard |
| Scope control | Platform requests specific scopes | Full access based on key permissions |
| Revocation | User revokes in their Dashboard, webhook notifies you | User must regenerate key manually |
| Multi-tenant | Designed for it | Awkward -- each company needs their own key |
| Credentials in `.env` | `client_id` + `client_secret` (platform-level) | Each company's API key (or stored in DB) |

**For a multi-tenant SaaS like Apex, OAuth is clearly the better path for Increase.** It avoids asking each painting company to copy-paste API keys and provides proper scoping and revocation.

---

## Comparison: What Changes With OAuth

### Without OAuth (API Keys Everywhere)

```env
# .env - grows with each integration
STRIPE_KEY=pk_live_xxx
STRIPE_SECRET=sk_live_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx
INCREASE_API_KEY=sand_xxx          # Single-tenant only
INCREASE_WEBHOOK_SECRET=xxx
```

For multi-tenant, you would need to store each company's Increase API key in the database -- effectively the same security concern as OAuth tokens, but without revocation/scoping benefits.

### With OAuth (Hybrid Approach)

```env
# .env - platform-level credentials only
STRIPE_KEY=pk_live_xxx
STRIPE_SECRET=sk_live_xxx
STRIPE_WEBHOOK_SECRET=whsec_xxx
INCREASE_OAUTH_CLIENT_ID=your_app_client_id
INCREASE_OAUTH_CLIENT_SECRET=your_app_client_secret
```

```php
// Database: per-company tokens (encrypted)
// company_settings table or dedicated oauth_tokens table
$companySetting->increase_access_token  // encrypted, per-company
$companySetting->stripe_account_id      // acct_XXXX, per-company
```

---

## Recommendation

### For Stripe: Use Connect with Account Links (No OAuth)

- **Do not use** Stripe's legacy OAuth flow -- it is deprecated for new platforms
- **Use** the standard Connect onboarding via Account Links API
- **Store** your platform API keys in `.env` (unavoidable)
- **Store** each connected account's `acct_XXXX` ID in the database
- **Account type**: Express (Stripe handles KYC, you control payouts)

### For Increase: Use OAuth

- **Use** the OAuth 2.0 authorization code flow
- **Store** `client_id` and `client_secret` in `.env` (platform-level, never changes)
- **Store** each company's `access_token` in the database (encrypted)
- **Handle** the `oauth_connection.deactivated` webhook for revocation
- **Benefit**: Users never need to copy-paste API keys; proper scoping and revocation

### Key Architectural Decision

You **cannot fully eliminate `.env` credentials** for either platform. The question is really about:

1. **Platform-level credentials** (always in `.env`): Your Stripe API keys, your Increase OAuth app credentials
2. **Per-company credentials** (always in database): Connected account IDs (Stripe), OAuth access tokens (Increase)

The OAuth approach for Increase is superior because it provides:
- Better user experience (no copy-paste of keys)
- Scoped access (request only what you need)
- User-controlled revocation
- Webhook-based deauthorization notifications
- Standard OAuth 2.0 flow that Laravel packages support well

---

## Sources

- [Stripe: Using OAuth with Standard accounts](https://docs.stripe.com/connect/oauth-standard-accounts)
- [Stripe: Connect OAuth reference](https://docs.stripe.com/connect/oauth-reference)
- [Stripe: Making API calls for connected accounts](https://docs.stripe.com/connect/authentication)
- [Stripe: Connect account types](https://docs.stripe.com/connect/accounts)
- [Stripe: OAuth changes for platform-controlled Standard accounts](https://docs.stripe.com/connect/oauth-changes-for-standard-platforms)
- [Stripe: Connect and the Accounts v2 API](https://docs.stripe.com/connect/accounts-v2)
- [Stripe: Account Links API](https://docs.stripe.com/api/account_links)
- [Increase: OAuth documentation](https://increase.com/documentation/oauth)
- [Increase: API Reference](https://increase.com/documentation/api)
- [Increase: OAuth Tokens API](https://increase.com/documentation/api/oauth-tokens)
- [Increase: OAuth Applications API](https://increase.com/documentation/api/oauth-applications)
