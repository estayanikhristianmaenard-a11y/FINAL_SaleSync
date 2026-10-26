# Philippine address dropdowns

Registration, Add address and Edit address (including dialogs opened from checkout) use the existing customer design with three required native dropdowns in order:

1. Province / region (all 82 provinces and 18 regions).
2. City / municipality belonging to that selection.
3. Barangay belonging to that city / municipality.

The full local directory contains 1,642 cities/municipalities and 42,010 barangays. House/building/unit number, street and postal code remain user-entered fields. Postal codes are not guessed or automatically assigned from barangay names.

## Dataset provenance

- Authority: Philippine Statistics Authority [PSGC masterlist and national totals](https://psa.gov.ph/classification/psgc), as of September 30, 2026; published October 12, 2026.
- Downloaded mirror: [bendlikeabamboo/barangay](https://github.com/bendlikeabamboo/barangay), dataset version `2026-10-12`, pinned commit `16ce5315dad13cf3df80d43edc15053646ae61a5`.
- Source file: `barangay/data/barangay_flat.json`, SHA-256 `440755bcb8a119e1314104352c280b2096d58007baaeb487a589a1f54b445bad`.
- Retrieved October 27, 2026. The official spreadsheet download was inaccessible from this environment; the pinned mirror was used and its national totals were checked against PSA's published totals. An older API fallback was rejected and is not used by the application.
- Mirror MIT notice: [PSGC-MIRROR-LICENSE.txt](../database/reference/PSGC-MIRROR-LICENSE.txt). Attribution: Manuel B., mirror copyright 2025; geographic names and hierarchy originate from PSA's public records. PSA identifies its site content as CC BY 4.0 unless otherwise stated.

The deployable directory is `database/reference/ph-locations.json`, containing provenance, area choices, city records, their barangays and parent indexes. `scripts/prepare-ph-locations.mjs` validates the pinned input hash, rejects duplicate/broken hierarchy records and verifies the expected totals before generating this file. It reads JSON only; downloaded code is never executed. The app needs neither Python nor network access to use the directory.

## Hierarchy handling

- Metro Manila appears as **Metro Manila (NCR)** in the region group. It includes all 16 cities and the municipality of Pateros without inventing a province.
- Selecting any region lists its descendant cities/municipalities, including independent cities. Selecting a province lists that province's actual descendants. Highly urbanized cities assigned directly to a region are available through the region selection rather than an invented provincial relationship.
- Region-wide city labels include their province when present, distinguishing municipalities with the same name. Codes identify records; names alone never determine parentage.
- Manila's submunicipalities/districts are flattened into the City of Manila barangay list. District names remain in option labels, so the three-level flow retains every barangay.
- The mirror classifies grouping code `0990100000` (City of Isabela, not a province) as a city above actual city code `0990101000`. The empty grouping choice is excluded; actual City of Isabela and all its barangays are retained. This normalization yields PSA's 149 cities plus 1,493 municipalities.
- The Negros Island Region and the source's special geographic area hierarchy are retained. Every one of the 42,010 barangays is reachable through the three dropdowns; an automated test checks this.

## API and validation

All endpoints are public, read-only, same-origin lookups:

| Endpoint | Result |
| --- | --- |
| `GET /locations/provinces` | `{items,source}`; items have `code,name,kind,region_code,region_name` |
| `GET /locations/cities?province_code=1300000000` | City/municipality options with `code,name,label` |
| `GET /locations/barangays?city_code=1381200000` | Barangay options with `code,name` and optional `district` |

Registration and address-form `province`, `city` and `barangay` values are **10-digit PSGC strings**, replacing free-text names. Keep leading zeroes. The server validates both parent relationships and resolves official display names before composing the saved address. For example, `1300000000 → 1381200000 → 1381200011` resolves to **Metro Manila (NCR) → City of Pasig → Manggahan**. The same picker implementation supplies all forms; it supports asynchronous edit prefill and ignores responses for a closed/replaced modal.

Changing a parent clears and disables dependent selections until their options load. Cached lookups avoid repeated requests; stale responses cannot replace a newer selection. Loading/error/retry messages use the existing form components. Fields elsewhere in registration retain their values when location selections change. A request made while dependent controls are disabled cannot bypass the client guard or backend validation.

## Updates and existing addresses

Migration `004_structured_addresses.sql` stores house/street details, location codes and postal code on saved addresses, including those created at registration. Edit dialogs preselect the saved hierarchy. All current address forms use this same dropdown flow, including Add address from checkout. Changing the checkout address does not change its saved default.

Existing saved addresses remain readable; no existing customer data is renamed or deleted. Older full-text addresses have nullable components/codes. Their edit dialog shows the original saved text and asks the customer to select its location and enter house/street details once. The application does not guess components from free text. Default-only/contact-only updates preserve the existing location. New saves and location edits are validated on the server, while historical order snapshots remain unchanged.

To refresh the reference data later, obtain the new official release or a verified mirror, review its hierarchy/changes, update the source hash/version/provenance and expected totals in the preparation script, regenerate the bundled JSON, then run the service, HTTP and customer browser tests. Do not silently replace the dataset during a registration request. The snapshot is complete for its documented release, not a promise to reflect future boundary changes automatically.
