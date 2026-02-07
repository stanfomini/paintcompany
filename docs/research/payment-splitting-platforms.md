# Research: Payment Acceptance + Automatic Fund Distribution
*Generated: 2026-02-06*

## Requirements

A small painting business needs to:
1. **Accept payments** via credit card, ACH, and checks through their own web app (Laravel)
2. **Automatically split/distribute** incoming payments to multiple bank accounts based on configurable percentages (e.g., 30% owner, 20% crew, 50% operating)
3. **Fast fund availability** -- especially for checks (Relay currently holds checks for 2-3 weeks)
4. **API access** -- full programmatic control from a Laravel application
5. **Single integration** preferred -- one platform that handles accept + split + distribute

## Current Setup

- **Banking**: Relay Financial (Profit First accounts -- 20 checking accounts with auto-transfer rules)
- **Payment Processing**: Stripe (basic Checkout Sessions for card payments on invoices)
- **Pain Point**: Relay holds check deposits for 2-3 weeks before funds are available
- **Pain Point**: No programmatic check deposit -- manual process
- **Pain Point**: No automatic splitting of incoming Stripe payments to different bank accounts

## Platform-by-Platform Analysis

---

### Option 1: Stripe Connect (Separate Charges + Transfers)

**What It Is**: Stripe's marketplace/platform payments system that allows splitting a single charge across multiple connected accounts.

**How It Works**:
1. Customer pays via Stripe Checkout (card or ACH)
2. Funds land in your platform's Stripe balance
3. You create Transfer objects to move percentages to connected accounts
4. Each connected account has its own external bank account for payouts

**PHP Code Example**:
```php
// Customer pays $10,000 for a painting job
$charge = $stripe->paymentIntents->create([
    'amount' => 1000000, // $10,000 in cents
    'currency' => 'usd',
    'payment_method_types' => ['card', 'us_bank_account'],
    'transfer_group' => 'JOB_' . $job->id,
]);

// Split to Owner (30% = $3,000)
$stripe->transfers->create([
    'amount' => 300000,
    'currency' => 'usd',
    'destination' => 'acct_OWNER_ACCOUNT_ID',
    'transfer_group' => 'JOB_' . $job->id,
]);

// Split to Crew (20% = $2,000)
$stripe->transfers->create([
    'amount' => 200000,
    'currency' => 'usd',
    'destination' => 'acct_CREW_ACCOUNT_ID',
    'transfer_group' => 'JOB_' . $job->id,
]);

// Split to Operating (50% = $5,000)
$stripe->transfers->create([
    'amount' => 500000,
    'currency' => 'usd',
    'destination' => 'acct_OPERATING_ACCOUNT_ID',
    'transfer_group' => 'JOB_' . $job->id,
]);
```

**Payment Types Supported**:
- Credit/debit cards: YES (2.9% + $0.30)
- ACH Direct Debit: YES (0.8%, capped at $5.00)
- Paper check deposits: NO (pilot only, not generally available as of early 2026)

**Fees**:
| Item | Cost |
|------|------|
| Card payment | 2.9% + $0.30 |
| ACH payment | 0.8% (max $5) |
| Custom connected account | $2/month per active account |
| Payout to connected account | 0.25% + $0.25 per payout |
| Instant payout | 1% (funds in ~30 min) |
| Standard payout | Free (funds in 2 business days) |
| ACH dispute | $15 |

**Example: $10,000 job paid by card**:
- Stripe processing: $320 (2.9% + $0.30)
- 3 connected account fees: $6/month
- 3 payouts (standard): $0.75 + 0.25% of each transfer
- Total platform cost: ~$350

**Example: $10,000 job paid by ACH**:
- Stripe processing: $5.00 (capped)
- Connected account + payout fees: same as above
- Total platform cost: ~$32

**Pros**:
- Already integrated in this codebase (Stripe PHP SDK, webhook handler)
- Excellent PHP SDK, first-class Laravel support
- Transfer splitting is battle-tested and well-documented
- ACH acceptance with very reasonable fees ($5 cap)
- Handles KYC/compliance for connected accounts
- Transfer and charge amounts do not need to match
- Can link transfers to source charges for balance safety

**Cons**:
- Does NOT accept paper checks (pilot only)
- Connected accounts require KYC onboarding (even Custom type)
- $2/month per active connected account
- 0.25% + $0.25 per payout adds up
- Standard payouts take 2 business days
- Connected accounts are designed for third parties, not your own bank accounts (workaround required)

**Check Workaround**: Stripe cannot accept physical checks. You would need a separate banking platform (Increase, Mercury, Column) for check deposits, then manually or programmatically trigger the split.

**Laravel Compatibility**: Excellent -- `stripe/stripe-php` SDK is already in composer.json

---

### Option 2: Stripe Treasury (Financial Accounts)

**What It Is**: Banking-as-a-service from Stripe. Lets platforms create bank accounts, hold funds, and move money -- all via API.

**How It Works**:
1. Create multiple Financial Accounts (like sub-bank-accounts) via API
2. Receive payments into one account
3. Programmatically move funds between accounts via internal transfers
4. Each Financial Account gets its own routing/account number

**Key Capabilities**:
- FDIC-insured accounts (via Goldman Sachs / Evolve Bank & Trust)
- ACH credits and debits
- Wire transfers
- Internal (book) transfers between Financial Accounts (instant)
- Check deposit (available through the platform but details are limited)

**Fees**: Not publicly listed -- requires contacting Stripe sales. Available only to Connect platforms.

**Pros**:
- Native Stripe integration -- same SDK, same dashboard
- Instant internal transfers between your own Financial Accounts
- Real routing/account numbers for receiving external payments
- Could replace Relay entirely as your bank
- FDIC insured

**Cons**:
- Requires approval from Stripe (not self-serve)
- Primarily designed for platforms offering banking to their users (Shopify Balance model)
- Pricing is opaque / enterprise-level
- Not clear if check deposits are available or have faster availability than Relay
- May be overkill for a single-company painting business
- Limited public documentation on check deposit fund availability

**Verdict**: Treasury is powerful but designed for platforms building banking products for their customers. For a single painting company, it is likely overkill and hard to get approved for.

---

### Option 3: Increase

**What It Is**: Enterprise-grade banking APIs with direct Federal Reserve access. API-first bank accounts, ACH, wires, checks, real-time payments.

**How It Works**:
1. Open bank accounts via API
2. Deposit checks via API (photo upload) or Lockbox (mail-in)
3. Originate ACH, wires, and real-time payments via API
4. Programmatically move money between accounts

**Check Deposit Process**:
1. POST to `/check_deposits` with front/back images + amount
2. Status goes to `pending` (under review by Increase operator)
3. Status moves to `submitted` (sent to Federal Reserve)
4. A Transaction is created for the full amount immediately upon submission
5. Pending Transaction resolves when settled

**Check Deposit Fund Availability**: Documentation states a Transaction is "immediately created for the full amount" upon submission to the Fed. This suggests next-business-day or possibly same-day availability after operator review -- significantly faster than Relay's 2-3 week holds. (Exact timing depends on their bank partner's Reg CC policies.)

**Lockbox Feature**: Increase provides physical mailing addresses (lockboxes) where customers mail checks. Increase opens them, scans them, and deposits them via API. $10 per check.

**Fees**:
| Item | Cost |
|------|------|
| Same-day ACH origination | $2.00/transaction |
| Next-day ACH origination | $0.50/transaction |
| ACH returns | $5.00/transaction |
| Wire origination | $15.00/transaction |
| Check printing + mailing | $3.00/check |
| Lockbox check deposit | $10.00/check |
| RTP / FedNow origination | $2.50/transaction |
| Account numbers | First 10 free, then custom |
| Virtual card creation | $0.25/card |

**SDK/API Support**:
- Official SDKs: Python, TypeScript, Go, Java, Kotlin, Ruby
- **No official PHP SDK** -- would need to use Laravel HTTP client (Guzzle) directly
- REST API is well-documented and straightforward

**Pros**:
- True banking API with direct Fed access
- Check deposits via API with operator review (fast turnaround)
- Lockbox service for mail-in checks
- Multiple account numbers for fund segregation
- Same-day ACH (funds in hours)
- FedNow / RTP for instant payments
- Clean, well-documented REST API

**Cons**:
- No PHP SDK (requires custom HTTP client wrapper)
- Does NOT accept credit card payments (banking only, not payment processing)
- Lockbox checks are $10 each (expensive for small checks)
- Requires Increase account approval (fintech-focused)
- Pricing may be high for low-volume businesses
- Enterprise focus -- may not accept a small painting company directly

**Verdict**: Best-in-class for banking operations and check deposits. But it does NOT accept credit card payments, so you still need Stripe (or similar) for card/ACH acceptance from customers. The lack of a PHP SDK means building a custom integration layer.

---

### Option 4: Mercury

**What It Is**: Online business banking platform popular with startups. API access, multiple accounts, ACH/wire/check support.

**Check Deposit Fund Availability**: Checks deposited via Mercury dashboard are "manually reviewed and once approved can take up to 5 business days to post." This is better than Relay's 2-3 weeks but still slow.

**Transfer Speed**:
| Method | Speed |
|--------|-------|
| Domestic wire | 0-1 business days |
| RTP (Real-Time Payments) | Seconds to 30 minutes |
| ACH | 1-3 business days |
| Plaid transfers | 3-5 business days |
| Check deposits | Up to 5 business days |

**API Capabilities**:
- Make payments programmatically
- Create transfer rules (by time, balance, or fixed amount)
- Automate reconciliation
- Bulk payments
- Auto-transfers with percentage-based rules (new as of Nov 2025)

**Fees**: Free domestic wires, free ACH. No monthly fee for basic plan. $30/month for Grow plan, $90/month for Scale plan.

**Pros**:
- Free banking with good API
- Up to 20 checking accounts (Profit First compatible)
- Auto-transfer rules with percentage splits (similar to Relay)
- Good developer experience
- Free domestic wires
- New RTP receiving (instant)

**Cons**:
- Check deposits still take up to 5 business days
- Does NOT accept payments from customers (it is a bank, not a payment processor)
- API does not appear to support remote check deposit (dashboard only)
- No credit card processing
- Still relatively slow for checks compared to traditional banks

**Verdict**: Mercury is a better Relay with API access and percentage-based auto-transfers, but check deposit speed is only marginally better. It solves the "auto-distribute" problem but not the "accept all payment types" or "fast check availability" problems.

---

### Option 5: Column

**What It Is**: Nationally chartered bank built for developers. Direct API access to ACH, wire, FedNow, check processing, and programmable bank accounts.

**Key Features**:
- FDIC-insured bank accounts via API
- ACH with all 5 settlement windows
- Wire transfers via FedWire
- Real-time book transfers between Column accounts (instant)
- FedNow (24/7/365 instant payments)
- Check processing via API
- Card programs

**Fees**:
| Item | Cost |
|------|------|
| ACH transfer | $0.50/transaction |
| Same-day ACH | Additional surcharge |
| Incoming wires | First 10 free, then $5.00 |
| Check issuance | $1.00/check |
| Monthly minimum | Yes (amount not public) |

**Pros**:
- Nationally chartered bank (not a fintech wrapper)
- Direct Federal Reserve connection
- Real-time internal transfers
- Full check processing API
- FedNow support for instant payments
- Designed for developers

**Cons**:
- No PHP SDK (REST API only)
- Does NOT accept credit card payments
- Primarily targets fintech companies building products
- Monthly minimum fees
- May not approve a small painting company
- Check deposit availability details not publicly documented

**Verdict**: Similar to Increase -- excellent banking infrastructure, but designed for fintech platforms, not end-user businesses. No card payment acceptance. Would still need Stripe alongside it.

---

### Option 6: Moov

**What It Is**: All-in-one payment platform -- processor, issuer, PayFac, and technology provider. Accepts, stores, sends, and spends money.

**Key Capabilities**:
- Card payment acceptance (online + in-person)
- ACH transfers (same-day and next-day)
- RTP (real-time payments)
- Moov Wallets for holding funds
- Virtual cards
- Transfer groups for splitting funds between participants
- Bank account linking

**Fees**:
| Item | Cost |
|------|------|
| Online card (domestic) | Interchange+ + 0.60% + $0.15 |
| ACH same-day | $0.40/transaction |
| ACH next-day | $0.25/transaction |
| ACH returns | $5.00/return |
| RTP credit / push to card | 0.95% ($0.50 min, $5 cap) |
| Moov wallet | $0.50/month per active wallet |
| Virtual card creation | $0.15/card |
| Bank account validation | $0.75 instant, $1.50 third-party |
| Monthly minimum | $500 |
| Disputes | $15 |

**Pros**:
- True all-in-one: accept cards + ACH + distribute funds
- Transfer groups for splitting payments to multiple wallets
- Same-day ACH at $0.40 (cheaper than Increase)
- RTP for instant payouts
- No setup fees
- Developer-focused with good documentation

**Cons**:
- $500 monthly minimum (whether you use it or not)
- No official PHP SDK (REST API, TypeScript SDK available)
- Does NOT appear to support paper check deposits
- Moov wallets cost $0.50/month each (3 wallets = $1.50/month, but $500 minimum dominates)
- Interchange+ pricing is complex to predict
- Smaller company with less track record than Stripe
- Not clear how payout to external bank accounts works for non-marketplace use cases

**Verdict**: Closest to an all-in-one solution for cards + ACH + splitting. But no check deposits, $500/month minimum, and no PHP SDK make it a tough sell for a small painting company.

---

### Option 7: Relay Financial (Current -- with improvements)

**What It Is**: Business banking with Profit First methodology. Up to 20 checking accounts with automatic percentage-based transfers.

**Current Capabilities**:
- 20 checking accounts with custom names
- Auto-transfer rules: percentage-based splits triggered by balance thresholds or schedule
- Frequencies: instant, daily, weekly, twice monthly, monthly
- ACH transfers and checks (sending and receiving)
- Physical and virtual debit cards
- No API for check deposits

**Check Hold Problem**: Relay (through Thread Bank) applies extended holds on deposited checks -- reportedly 2-3 weeks. This is a bank policy issue, not a regulatory requirement.

**API Access**: Relay does have API access (documented at docs.relay.link) with support for:
- Account information
- Transaction data
- Potentially transfers (details limited in public docs)
- Plaid/Yodlee integration for external connections

**Pros**:
- Already set up and working
- Profit First auto-distribution built in
- Free for basic plan
- Up to 20 accounts
- Good for the "split to accounts" use case

**Cons**:
- 2-3 week check holds (the core problem)
- Limited API capabilities (no check deposit via API)
- No payment acceptance (not a processor)
- API documentation is limited/unclear

---

## Comparative Matrix

| Feature | Stripe Connect | Stripe Treasury | Increase | Mercury | Column | Moov | Relay |
|---------|---------------|-----------------|----------|---------|--------|------|-------|
| **Accept Cards** | YES | No | No | No | No | YES | No |
| **Accept ACH** | YES | YES | YES (originate) | No | YES | YES | No |
| **Accept Checks** | No | Unclear | YES (API + Lockbox) | YES (slow) | YES | No | YES (slow) |
| **Split to Accounts** | YES (transfers) | YES (internal) | YES (multiple accts) | YES (auto-transfer) | YES (book transfer) | YES (wallets) | YES (Profit First) |
| **Check Fund Speed** | N/A | Unknown | Fast (next-day est.) | Up to 5 days | Unknown | N/A | 2-3 weeks |
| **PHP SDK** | YES (official) | YES (Stripe SDK) | No | Limited | No | No | Limited |
| **Monthly Cost** | $6+ (accounts) | Enterprise | Custom | Free-$90 | Minimum | $500 min | Free-$90 |
| **Card Fees** | 2.9% + $0.30 | N/A | N/A | N/A | N/A | IC+ + 0.60% + $0.15 | N/A |
| **ACH Fees** | 0.8% (max $5) | Unknown | $0.50-$2.00 | Free | $0.50 | $0.25-$0.40 | Free |
| **Laravel Ready** | YES | YES | Build wrapper | Build wrapper | Build wrapper | Build wrapper | Build wrapper |

---

## Recommendation

### Winner: Stripe Connect + Increase (Two-Platform Architecture)

No single platform handles all three payment types (card, ACH, checks) with automatic splitting AND fast check availability. The best architecture is:

### Layer 1: Stripe Connect -- Payment Acceptance + Splitting (Cards + ACH)
- Accept credit card and ACH payments from customers through your web app
- Automatically split each payment to connected accounts using Transfers
- Each connected account pays out to a different bank account (Owner, Crew, Operating)
- Already partially built in your codebase

### Layer 2: Increase -- Check Deposits + Banking (Checks)
- Accept paper checks via API (photo deposit) or Lockbox (mail-in)
- Fast fund availability (next-business-day estimated, vs Relay's 2-3 weeks)
- Same-day ACH for moving funds between accounts
- Multiple account numbers for fund segregation
- Programmatic control over all money movement

### Architecture Diagram
```
Customer Pays
    |
    +-- Credit Card / ACH --> Stripe Connect
    |                           |
    |                           +-- Transfer 30% --> Connected Acct (Owner Bank)
    |                           +-- Transfer 20% --> Connected Acct (Crew Bank)
    |                           +-- Transfer 50% --> Connected Acct (Operating Bank)
    |
    +-- Paper Check --> Increase API (photo deposit)
                          |
                          +-- Funds available (~1 business day)
                          +-- ACH transfer 30% --> Owner Bank Account
                          +-- ACH transfer 20% --> Crew Bank Account
                          +-- ACH transfer 50% --> Operating Bank Account
```

### Implementation Plan

**Phase 1: Stripe Connect Split Payments** (use existing Stripe integration)
1. Create 3 Custom connected accounts (Owner, Crew, Operating)
2. Link each to its respective bank account
3. After each successful payment, create Transfer objects with configured percentages
4. Store split configuration in CompanySetting model

**Phase 2: Add ACH Payment Acceptance**
1. Add `us_bank_account` to `payment_method_types` in Checkout Session
2. ACH payments at 0.8% (max $5) vs 2.9% for cards -- significant savings on large jobs

**Phase 3: Increase Integration for Checks**
1. Build an IncreaseService wrapper using Laravel HTTP client
2. Implement check deposit via API (upload front/back images)
3. Configure webhook for deposit status updates
4. Auto-trigger percentage-based ACH transfers upon check clearance
5. Optionally set up a Lockbox for mail-in checks

**Phase 4: Migrate from Relay**
1. Once Increase accounts are operational, redirect check deposits there
2. Keep Relay for debit cards / day-to-day expenses if desired, or close accounts
3. All programmatic money movement happens through Stripe + Increase APIs

### Cost Estimate (Monthly, $50K revenue)

| Item | Cost |
|------|------|
| Stripe card processing (assume 60% of revenue by card) | ~$900 |
| Stripe ACH processing (assume 20% of revenue by ACH) | ~$25 |
| Stripe connected accounts (3 active) | $6 |
| Stripe payouts (3 per payment) | ~$15 |
| Increase check deposits (assume 20% of revenue, ~5 checks) | ~$50 (lockbox) or ~$0 (API deposit) |
| Increase ACH transfers (15 splits from check deposits) | ~$7.50 |
| **Total estimated** | **~$1,000/month** |

vs. current: Stripe card processing (~$900) + Relay check holds (2-3 weeks of lost float)

### Alternative Recommendation: Stripe Connect + Mercury

If Increase is too enterprise-focused or does not approve the account:
- Use Mercury as the banking layer instead
- Mercury has better auto-transfer rules (percentage-based, like Relay)
- Check deposits are 5 days (better than Relay's 2-3 weeks, worse than Increase)
- Mercury API can automate reconciliation and transfers
- Free domestic wires and ACH

### Alternative Recommendation: Moov (Single Platform)

If you want one platform and can tolerate no check deposits:
- Moov handles cards + ACH + splitting via wallets
- $500/month minimum is the main cost
- Would replace Stripe entirely
- No PHP SDK -- needs custom wrapper
- No check deposit capability

---

## Key Answers to Original Questions

**Q: Which platform handles ALL three payment types (CC, ACH, checks) with automatic splitting?**
A: None. No single platform does all three with splitting. Stripe Connect handles cards + ACH with splitting. Increase handles checks with fast availability. You need both.

**Q: What are the fees for each?**
A: See fee tables above. Stripe cards at 2.9% + $0.30, ACH at 0.8% (max $5). Increase ACH at $0.50-$2.00, check deposits free via API or $10 via lockbox.

**Q: Which has the best Laravel/PHP SDK or REST API?**
A: Stripe, by a wide margin. Official PHP SDK, already in your composer.json, extensive documentation. Increase has no PHP SDK but a clean REST API that works well with Laravel's HTTP client.

**Q: What is the fastest way to get check funds available?**
A: Increase (estimated next-business-day after operator review). Mercury is ~5 business days. Relay is 2-3 weeks. Traditional banks with instant mobile deposit programs (PNC, KeyBank) can be same-day for small amounts.

**Q: Can Stripe Connect alone handle the splitting to multiple bank accounts, or do you need a banking platform too?**
A: Stripe Connect CAN split to multiple bank accounts via connected accounts and transfers. You do NOT need a banking platform for card/ACH splits. You DO need a banking platform for check deposits (Stripe cannot accept paper checks).

**Q: Is there a single platform that does everything?**
A: No. The closest is Moov (cards + ACH + wallets), but it lacks check deposit. For a painting business that receives checks from customers, a two-platform approach (Stripe + banking API) is required.

---

## Sources

- Stripe Connect Documentation: https://docs.stripe.com/connect/separate-charges-and-transfers
- Stripe Pricing: https://stripe.com/pricing
- Stripe Connect Pricing: https://stripe.com/connect/pricing
- Stripe Treasury: https://docs.stripe.com/treasury
- Increase Homepage: https://increase.com/
- Increase Check Deposits: https://increase.com/documentation/check-deposits
- Increase Fees: https://increase.com/fees
- Increase GitHub (SDKs): https://github.com/increase
- Mercury API: https://mercury.com/api
- Mercury Check Deposits: https://support.mercury.com/hc/en-us/articles/28768119163156-Depositing-checks
- Column Homepage: https://column.com/
- Column ACH: https://column.com/ach/
- Moov Pricing: https://moov.io/pricing/
- Moov Platform: https://moov.io/platform/
- Relay Profit First: https://relayfi.com/profit-first/
- Relay Auto-Transfers: https://support.relayfi.com/hc/en-us/articles/13208655552532-Setting-up-auto-transfer-rules
