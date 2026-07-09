# Evolvaxe --- Global Marketplace Module

## Purpose

The Marketplace is the main shared multiplayer layer.

## Listings

Support unique-item and stackable-item listings.

Store seller, item instance, definition, quantity, unit/total price,
fees, status, listing/sale/expiration times, and version.

Statuses: `ACTIVE`, `SOLD`, `CANCELLED`, `EXPIRED`, `LOCKED`.

## Escrow

Listing validates ownership/tradeability, moves the item out of normal
containers into `MARKET_ESCROW`, charges valid fees, and creates the
listing. Listed items cannot be used.

## Purchase Transaction

Lock listing → validate version/quantity → validate buyer wallet → debit
buyer → calculate fee → credit seller net → transfer ownership → move
item to Market Delivery → create transaction → update listing → commit.

Use idempotency keys.

## Market Delivery

Purchased items enter a dedicated delivery container so geometric
inventory fullness cannot break a completed purchase.

## Search

Filter by family, definition, material origin, quality bucket, property
existence/value, price, crafter, and listing age.

## Metrics

Display lowest active listing, recent median/average sale price, sale
volume, active supply, and trend. These are market observations, not
fixed item values.

## Aggregation

Materials can aggregate by definition + origin + quality bucket. Unique
equipment requires broader analytics because exact instances differ.

## Fees

Listing and transaction fees are currency sinks.

## Anti-Abuse

Create signals for wash trading, self-trading patterns, extreme
manipulation, and bot-like listing activity. Signals do not
automatically equal bans.

## Services

`MarketListingService`, `MarketPurchaseService`, `MarketSearchService`,
`MarketPricingAnalyticsService`, `MarketDeliveryService`.

## Tests

Escrow, concurrent purchase, insufficient funds, partial stack, unique
item, fee accounting, idempotency, delivery.
