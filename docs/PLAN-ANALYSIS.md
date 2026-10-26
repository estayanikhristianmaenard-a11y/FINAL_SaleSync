# Customer module analysis

Original source: `SYSTEM DEVELOPMENT PLAN (TENTATIVE) - Google Docs.pdf`, supplied October 25, 2026. The user's [final role plan](FINAL-ROLE-PLAN.md), supplied October 27, supersedes conflicting scope/deferment decisions here. Work stays on `customer`; other owners implement their modules on their own branches before combination.

## Scope and decisions

The implemented foundation includes registration, sign-in/out, email verification, public product browsing/search/filter/details, public store details, cart quantities, checkout/order summary, PayMongo Test Mode, own order/payment history, profile, saved delivery addresses and in-app notifications. The final required customer scope also includes password management, comparison, saved products, vouchers, invoices, tracking, return/refund requests with evidence and appeals, seller conversations/inbox, seller applications and notification preferences. See the final plan's implementation checklist for current gaps.

This checkout contained only the PDF. PHP 8.2 is installed in XAMPP, and the plan explicitly mentions SQLite. Use dependency-free PHP with PDO SQLite and one shared database. The team confirmed there is no existing required stack. This is a new backend foundation; it cannot yet claim compatibility with an unseen project.

There is exactly one customer HTML entry point: `public/index.php`. Home, Shop, Cart, My Orders and Profile use sections, hash routes or modals inside it. The coworker's layout, colors, typography and components from `OVERALL-UI` were connected on October 27, 2026; see [the integration notes](CUSTOMER-UI-INTEGRATION.md). PayMongo's external hosted payment page returns to this same customer page.

## Implemented backend

| Customer feature | Backend behavior |
| --- | --- |
| Authentication | Password hashes, strict sessions, CSRF protection, sign-in attempt limits, active-customer role checks |
| Verification | One-hour hashed token, single use, resend cooldown; private development mail outbox or PHP mail transport |
| Catalogue | Public published products from active approved stores; search, category/store/price filters, sorting, pagination |
| Profile and addresses | Own name and up to 20 labeled delivery addresses; one default address |
| Cart | Signed-in customers only; set/remove quantities, current server prices, availability and totals |
| Checkout | Verified email; own address; atomic stock reservation and order snapshots; retry key prevents duplicate orders |
| Payments | Test-key-only hosted checkout, signed webhook, amount/currency/mode/reference checks, server reconciliation |
| Orders | Own paginated history, details, payment status and status history; safe cancellation before payment creation |
| Notifications | Own order/payment notifications and mark-as-read |

## Shared integration contract

All roles must share identities, catalogue, inventory, orders and payments. Do not create separate databases per role. The initial migration includes the shared tables needed for customer flows and uses integer centavos for money. Review the migration and API contract with the other module owners before merging their work. Their migrations should extend these tables rather than recreate them.

Role keys are `customer`, `seller`, `admin_maintenance` and `admin_management`. Customer registration always assigns `customer`. Seller approval, publishing, account moderation, fulfillment transitions, reports and maintenance routes belong to the other modules. No customer endpoint can mutate products, roles or order fulfillment status.

Orders start at `pending_payment`; verified payment moves them to `processing`. Seller integration may move paid orders through `ready_for_shipment`, `shipped`, and `completed`, recording history and notifications in the same transaction. An unpaid order can become `cancelled` and release stock exactly once. Order items retain seller IDs for future seller-specific access checks; this branch does not implement those seller permissions.

## Assumptions and pending team decisions

- Shipping defaults to zero and currency to PHP. Confirm delivery pricing before deployment.
- Email verification is required for checkout and payment, while unverified signed-in customers can manage their cart/profile.
- Password management is required by the final plan and remains pending; profile updates currently change the name only. Email-change and recovery policies need definition before implementation.
- A full address edit uses all address fields. Stored orders retain their delivery snapshot when an address is edited/deleted.
- Stock is reserved when an order is created. Orders without a provider session may be cancelled by the customer. Once a provider session may exist, it must be confirmed expired before stock is released. Known sessions are reconciled by webhook, refresh, or the CLI job. Unknown creation outcomes require provider dashboard lookup; they are never automatically retried. Schedule the reconciliation job and establish the team's abandoned-order expiry policy.
- PayMongo test keys, enabled methods, a publicly reachable webhook URL and webhook secret still need configuration. A real provider purchase has not been performed without those credentials.
- Development email is written to a private local outbox; delivery to real inboxes requires a configured PHP mail transport.
- The final role matrix assigns listings/options/inventory/product requests to sellers, and registry/categories/moderation to management. It assigns data integrity checks to maintenance. Customer work reads published products and implements its own browse/compare/save actions.
- Earlier deferrals for saved products, vouchers, conversations and customer return/refund requests are superseded: these are required pending features. Comparison is already connected as a read-only public-data view. Reviews and personalized feeds are not explicitly included in the final role table.
- The tentative plan's historical records and sales CSV rules span management/reporting modules. The UI catalogue seed supplies 141 visible sample products; the alternative starter seed supplies 12 fixtures. Neither manufactures historical successful payments.

## Frontend acceptance checklist

- Apply the coworker's design to one page, with accessible sections/modals and loading, empty, validation and error states.
- Guests browse the catalogue and public store details; cart/orders/profile actions prompt sign-in.
- Preserve a checkout retry key until that request succeeds or is intentionally abandoned.
- Show server totals and stock conflicts; format centavos only for display.
- Process `#verify-email=...`, then clear the token from the location.
- On `#orders/{id}` payment return, refresh payment status from the server. A redirect does not prove payment.
- Confirm the real PayMongo test flow and real email delivery after credentials/transport are supplied.
