# Tiering Model

> Six-tier access system controlling data granularity and features.

## Tier Hierarchy

| Tier | Value | Access | Data Granularity |
|---|---|---|---|
| Public | 0 | No account | Composite score + 8 free preview indicators (2 per category) |
| Free Account | 1 | Registered, no payment | Same as Public + report purchase enabled |
| Unlocked | 2 | One-time report purchase | Full indicator breakdown, schools, POIs, proximity factors |
| Subscriber | 3 | Monthly/annual plan | Exact percentiles, exact values, full trend history |
| Enterprise | 4 | Custom contract | API access, custom weights, tenant isolation |
| Admin | 99 | Internal | Everything + weight, contribution, rank, coverage, API paths |

## Tier Resolution Logic

Evaluated top-down — first match wins:

```
Admin?          → Tier 99
API access?     → Tier 4 (Enterprise)
Subscription?   → Tier 3 (Subscriber)
Area unlocked?  → Tier 2 (Unlocked)
Registered?     → Tier 1 (Free Account)
Anonymous?      → Tier 0 (Public)
```

For area unlocks, hierarchy is checked: DeSO → kommun → lan. A kommun unlock covers all DeSOs in that kommun.

## Data Obfuscation by Tier

### Public (Tier 0)
- Composite blended score (area + proximity)
- 8 free preview indicators with actual values and percentiles (2 per display category)
- Category sections with locked indicator counts
- School skeleton placeholders
- CTA to purchase full report

### Free Account / Registered (Tier 1)
- Same as Public
- Account enables report claiming and Google OAuth

### Unlocked / Report Purchased (Tier 2)
- Full indicator breakdown with exact percentiles and raw values
- All proximity factors with distances and effective distances
- Nearby schools list with merit values
- Nearby POIs by category
- Report persists at `/reports/{uuid}`

### Subscriber (Tier 3)
- Same as Unlocked for all locations (no per-report purchase needed)
- Exact percentile and normalized value
- Full trend history
- Methodology note, national context
- Source URL, last ingested date

### Admin (Tier 99)
All subscriber fields plus:
- Weight, weighted contribution
- Rank and rank total
- Normalization method
- Coverage count and total
- Source API path, field code
- Data quality notes, admin notes

## Report Purchase Flow

The primary monetization is a one-time report purchase (79 SEK) for a specific address.

### Flow

1. User drops pin on map → sees composite score + free preview
2. Clicks "Lås upp fullständig rapport" → `/purchase/{lat},{lng}`
3. **Identity step**: Choose guest email, create account, login, or Google OAuth
4. **Payment step**: Stripe Checkout session (79 SEK)
5. Stripe redirects to `/purchase/success?session_id=...` → polling page
6. `StripeWebhookController` receives `checkout.session.completed` → marks report as `completed`
7. Email sent with report link → `/reports/{uuid}`

### Guest Reports

- Guest purchases use email only (no account required)
- On later Google login, guest reports are automatically claimed via `ClaimGuestReports` listener
- Guest can access reports via signed URL: `/my-reports?email=...&signature=...` (24h validity)

### Controllers

| Controller | Purpose |
|---|---|
| `PurchaseController` | Checkout flow, Stripe session creation, status polling |
| `StripeWebhookController` | Handles `checkout.session.completed` and `expired` events |
| `ReportController` | Displays completed reports |
| `MyReportsController` | Lists reports for authenticated or guest users |

### Dev Mode

In `local` environment without Stripe secret key, checkout auto-completes for testing.

## Pricing

| Product | Price | Currency |
|---|---|---|
| Address report | 79 SEK | One-time |
| Kommun unlock | 199 SEK | One-time |
| Monthly subscription | 349 SEK/month | Recurring |
| Annual subscription | 2,990 SEK/year | Recurring |

Prices stored in öre (7900 = 79 SEK).

## View-As Simulation

Admins can simulate any tier via session override:

```
POST /admin/view-as   { "tier": 2 }
DELETE /admin/view-as
```

The `DataTieringService.resolveEffectiveTier()` checks session override before actual tier.

## Authentication

### Google OAuth

`SocialAuthController` handles Google login via Laravel Socialite:

1. `GET /auth/google` → redirect to Google consent screen
2. `GET /auth/google/callback` → find or create user, set `google_id`, `avatar_url`, `email_verified_at`
3. Claims any guest reports matching the email
4. Logs in with remember flag

### Guest Report Claiming

On any login (including Google OAuth), the `ClaimGuestReports` listener matches `Report.guest_email` to the authenticated user's email and assigns ownership.

## Implementation

**Service**: `DataTieringService`
**Enum**: `DataTier` (backed integer enum)
**Controller**: Each API endpoint calls `resolveEffectiveTier()` and passes the result to tier-specific response transformers.
**Preview Service**: `PreviewStatsService` generates category stats and free indicator values for the public tier preview.

## Related

- [DeSO Indicators API](/api/deso-indicators)
- [DeSO Schools API](/api/deso-schools)
- [Target Customers](/business/target-customers)
