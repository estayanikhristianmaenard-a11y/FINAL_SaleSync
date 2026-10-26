# Final role plan

Source: the user's final role-capability table supplied October 27, 2026. This replaces conflicting scope/deferment decisions in the earlier tentative PDF and prototype handoff. The table defines required capabilities; it does not claim those capabilities are already implemented.

Each owner builds their module on their respective branch first. Combine and test the modules after their respective work is complete. Our implementation stays on `customer`, uses one customer page, and follows the coworker's UI design.

## Authoritative responsibility matrix

| Features | Customer | Seller | Admin Management | Admin Maintenance |
| --- | --- | --- | --- | --- |
| Account | Profile, password, addresses | Store profile and settings | Manage accounts and suspensions | Security and technical logs |
| Products | Browse, search, filter, compare, save | Listings, options, inventory, product requests | Product registry, categories, moderation | Data integrity checks |
| Orders | Cart, checkout, purchases, tracking | Fulfillment, delivery and pickup | Order oversight | Courier event tools |
| Payments | Payment status, vouchers, invoices | Sales, earnings, payout statements | Payment reconciliation and payouts | Payment integration tools |
| Returns and refunds | Submit requests, evidence and appeals | Review requests and inspect returns | Resolve disputes and authorize refunds | Integration event monitoring |
| Messages | Seller conversations and inbox | Customer conversations and inbox | — | — |
| Promotions | Claim and apply vouchers | Shop vouchers and flash-deal requests | Platform vouchers, deals and banners | — |
| Reports | Purchase history | Sales summary and business insights | Marketplace and per-shop sales reports | System monitoring |
| Seller applications | Apply to become a seller | — | Review applications and decide | — |
| Settings | Notification preferences | Shop, collections and pickup settings | Platform settings and business rules | Integration configuration |
| Logs and operations | Notifications | Notifications | Administrative activity log | Backups, jobs, email outbox, webhooks and incidents |

## Customer implementation checklist

Status describes the current `customer` working tree after implementing the seven requested customer features. Other owners still need to adopt the shared contracts before cross-role acceptance.

See the [customer module audit](CUSTOMER-MODULE-AUDIT.md) for the October 27 source/configuration review, verified limitations and remaining work.

| Required capability | Current state | Remaining customer work / connection |
| --- | --- | --- |
| Profile | Partial | Name updates work. Registration stores primary phone and birthday and enforces minimum age 18; agree any additional fields/edit permissions |
| Password management | Connected | Authenticated change, current-password check, hash update and older-session invalidation; recovery policy is a separate decision |
| Addresses | Connected | Registration saves a default Home address from house/street/barangay/city/province/postal code and phone; own address editing/deletion/default/checkout selection work |
| Browse, search, filter | Connected | Published server catalogue and public store details |
| Compare | Connected | Read-only comparison of public products; selections are temporary view state |
| Save products | Connected | Persistent own Save/remove/list, including removal of unavailable listings |
| Cart and checkout | Connected | Server options, quantities, selected-item quotes, idempotent checkout and stock reservation |
| Purchases and purchase history | Connected | Own saved orders, option snapshots, payment/order status and history |
| Tracking | Partial | Order status timeline works; consume delivery/pickup tracking from the seller/courier integration contract |
| Payment status | Connected; external testing pending | PayMongo test adapter and verified status flow exist; real test credentials/webhook setup still needed |
| Vouchers: claim and apply | Customer connected | Eligibility, claim, server quote/checkout discounts and atomic usage; seller/management supply approved offers |
| Invoices | Partial | Saved order document exists; agree authoritative invoice fields/identifiers and connect them |
| Return/refund requests | Customer connected | Own paid delivered/completed purchase, quantity/net-value checks, configurable default seven-day window and case timeline; seller/management decide |
| Return evidence | Connected | Validated private images, owner-authorized retrieval and case links |
| Appeals | Customer connected | Rejected case appeal becomes escalated; management decides |
| Seller conversations/inbox | Customer connected | Persistent own store threads, messages, unread/read state and older-message loading; seller adopts shared participant contract |
| Seller application | Customer connected | Submit, status/history, requested-information resubmission; management grants approval/permissions |
| Notification preferences | Connected | Own optional email choices applied to outbox creation and delivery; essential inbox/security notices retained |
| Notifications | Connected | Order/payment, message/application/security events and context-aware opening; worker uses configured mail driver |

## Connections the module owners must share

- Identity, IDs, database migrations, session conventions and centavo money units remain shared contracts. Each owner implements their own routes and authorization; do not duplicate the user/product/order databases.
- Customer voucher actions claim/apply eligible offers. Seller owns shop offers/requests; management owns platform offers/deals/banners. The customer cannot create or authorize a discount through browser input.
- Customer returns/appeals submit records. Seller reviews and inspects goods. Management resolves disputes and authorizes refunds. Provider-confirmed results update payment/refund state; the customer cannot authorize their own refund.
- Customer and seller messaging must authorize both participants without giving either access to another customer's conversation or evidence.
- Customer seller applications submit information and view status. Management approval is required before seller permissions are granted.
- Customer tracking reads the shared fulfillment record. Seller controls fulfillment; maintenance operates courier integration tooling. Customers do not fabricate delivery/pickup events.
- Management owns platform business settings; maintenance owns technical integration configuration. Both access their permitted operations through role checks.

The [module integration contract](MODULE-INTEGRATION.md) and [customer feature handoff](CUSTOMER-FEATURES.md) describe current shared schemas/APIs. Voucher stacking, consumption and return-window defaults are implemented policies for team review; the role matrix did not specify them. Invoice issuance and independently progressing fulfillment/pickup still need shared contracts.
