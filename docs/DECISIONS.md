# DormLink decisions

Decisions made while building that **differ from or add to** the two spec guides (*System Features Guide* and *Billing and Tenancy Design: Revised Subsystem Guide*). The guides have not been updated yet, so where they disagree, this file and the code win. Add an entry whenever a new decision is made.

## Name (2026-10-09)

| Decision | Detail |
| --- | --- |
| BoardMate is now DormLink | Full title: *DORMLINK: A Mobile-Based Boarding House Management and Dormitory Finder System*. Everything was renamed: folders (`dormlink`, `dormlink-app`), databases (`dormlink`, `dormlink_test`), `config/dormlink.php`, `dormlink:*` commands, `DormLinkNotification`, Capacitor `com.dormlink.app`, and the app's saved keys (`dormlink.*`, so everyone logs in again once). Records saved before the rename that say "BoardMate" stay as history. The two older spec guides keep their BoardMate titles. |

## Accounts and sign-in

| Decision | Detail |
| --- | --- |
| Email must be verified before logging in | Unverified accounts cannot get a token. |
| Changing the email address | The new address must be confirmed first; the old address is told about the change. |
| Logins expire after 30 idle days | Each use extends the token. `dormlink.token_idle_days`; expired tokens are pruned by a daily job. |
| Google sign-in | The Google ID token is verified on the server. A new account is created only after the person accepts the consent step. An existing account with the same email is linked automatically. |
| Accounts are created on the web | Boarders, owners and caretakers sign up themselves. Only the super admin is seeded. |
| Suspending an account | Its tokens are revoked. A suspended boarder's pending applications and reservation are cancelled, the unit is freed, and the owners are told. If the account is an owner, the pending applications on their properties are declined too. Approval refuses suspended applicants. |

## Owners

| Decision | Detail |
| --- | --- |
| Applying as an owner | Any signed-in account can apply. It gets the owner role at once so it can set things up, but listings stay hidden until a platform admin verifies it. A rejected owner can apply again. |
| Proof documents | Required: a valid government ID and one proof of the property (title, lease, or a utility bill in the owner's name). Optional: business or barangay permit, other. Up to 5 files (jpg, png, webp or pdf, 5 MB each), private, shown to the admin by signed, expiring links. |
| Suspending an owner | Their listings are hidden and the pending applications on their properties are declined ("This place is not taking bookings right now"), so boarders get their application slots back. Reservations stay until they expire. |
| Document retention | Files are deleted 90 days after the verify, reject or suspend decision. The rows stay as a record (`purged_at`). Owners who applied before documents were required show a warning to the admin. |

## Caretakers

| Decision | Detail |
| --- | --- |
| Access is per property | The level on each property assignment (Collector or Manager) decides what the caretaker can do there. The level on the owner–caretaker link is the default for new assignments. |
| Changing the level in the Caretakers tab | Applies to every property the caretaker has with that owner. Per-property levels can still be changed on each property's Caretakers tab; the list says "Levels differ per property" when they no longer match. |
| The owner link must be active | Removing a caretaker removes all of their assignments with that owner at once. |

## Properties, units and prices

| Decision | Detail |
| --- | --- |
| Publishing needs | A verified owner, a map pin, at least one photo, rent on every unit, and at least one unit ready to rent. |
| "In use" | A unit that is reserved, occupied, leaving or overstaying. One rule (`RentableUnit::isInUse`, `Property::occupancyBlocks`, `Room::hasUnitsInUse`) blocks deleting the property or a room, switching a room's rental mode, and removing the unit. Each check runs under the same property lock that booking approval takes, so they cannot race. |
| Unit status changes | Only through `UnitStatusService`, with a fixed list of allowed moves. A move that is not allowed, or based on an out-of-date status, is an error. |
| Deleting a property | Refused while any unit is in use. Otherwise it is unpublished and soft-deleted (history stays), and its pending applications are declined ("This place is no longer listed"). |
| Rooms (2026-10-05) | Every property has at least one room, and each room is rented whole or by bedspace (`rooms.rental_mode`), so one property can mix both. A house or studio rented as a whole is a property with one room. Rental mode moved from the property to the room; the property only shows a summary (`whole`, `bedspaces` or `mixed`). |
| Buildings removed (2026-10-09) | A property is the building: the structure is Property → Room → Bedspace, with one map pin per property. An owner with two buildings lists two properties. The `buildings` table, `properties.building_id` and `/owner/buildings` are gone. |
| Room floor and code | The floor is an optional number on the room, not a table. The code is automatic: floor and room number, e.g. `F4-03`, or `03` when the room has no floor. Room numbers count per property and are never reused. |
| Door number and who can stay (2026-10-09) | Both optional on a room. The door number (`rooms.door_no`, up to 10 characters) is typed by the owner and is unique within the property, ignoring case and removed rooms. When set, it is what people see: "Room 201" in the app, notifications and messages (`Room::label()`), with the code shown small for staff. The activity log keeps the code. "Who can stay" is Anyone (default), Female only or Male only. |
| Switching a room's rental mode | Refused while any unit in the room is in use. A person joining a whole-rented room never switches it; the owner changes the price and the leader's occupant list instead. The old units are archived with their price history. |
| Whole-room option (2026-10-10) | A bedspace room can also be rented as one room: the owner or a Manager adds its whole-room option (`PUT /rooms/{room}/whole-option`: rent, and capacity, by default one per bedspace), an extra whole unit marked `is_whole_option`. The option and the bedspaces block each other (`rentable_units.blocked`, kept in step by `UnitStatusService::syncWholeOption` on every status change): the whole room is offered only while no bedspace is held, and while someone holds the whole room no bedspace is offered. Counts and labels count places only (bedspaces), never the option. The option is removed like a unit, not while it is held. A room held through its option behaves as a room rented whole (`Room::isLetWhole`): the renter is its leader and occupants are listed. Boarders ask for it with `whole: true` (`booking_applications.wants_whole`). |
| Removing a room | Refused while a unit is in use, and a property always keeps at least one room. The room and its units are archived. |
| Prices | Effective-dated, never overwritten, no back-dating. Saving the price already in force changes nothing. A scheduled future price is replaced, not stacked. A price set today can be corrected the same day. |
| Locked prices | A price a bill used is locked (`price_rules.locked_at`; billing calls `PriceBook::lock`). It is never deleted or replaced; a later price can still end it. |
| Listing photos | Up to 15. Saved as WebP with the longer side at most 1600 px (`large`) and 480 px (`thumb`). EXIF and GPS are removed and the original is not kept. Images over 8200 px on a side are refused. Profile photos are a 256 px WebP square. `dormlink:rebuild-images` converts older uploads. |

## Owner settings (2026-10-06)

| Decision | Detail |
| --- | --- |
| Settings of removed features are retired | Due-date policy and common day, partial-period handling, overstay daily charge, short-notice fee, final-utility holdback, split manager, utility due rule, late fees, formal demand, notice-to-vacate, early warning and payment promises. The columns stay (no migration) and keep their defaults, but the settings API neither shows nor accepts them (`PropertySettings::RETIRED`) and the app no longer displays them. |
| Deposit and first rent (2026-10-06) | Under the default rule the deposit is one month's rent **after** any discount (rent 4,500, discount 500: deposit 4,000). The advance rent paid at move-in is also after the discount and excludes utilities. |
| Rent in advance, utilities in arrears (2026-10-06) | Each bill from the anchor day is the rent for the period starting plus the utilities of the period just finished. The move-in payment is separate from bills. This replaces the guide's example SC-02, which puts rent and fixed utilities together for the coming period. |

## Booking (up to the reservation)

| Decision | Detail |
| --- | --- |
| Boarders apply to a room (2026-10-10) | The application carries the room the boarder wants (`booking_applications.room_id`). It is required when the place has more than one room (a one-room place uses its room) and the room must have a free place when applying. The owner or a Manager still picks the unit when approving: the approve screen starts in that room and may choose another. If the reserved unit is in another room, the approval message says so ("placed you in room 202 instead of room 201") and the activity log notes the room asked for. The owner's "New booking application" message names the room. |
| Reservation length | From the planned move-in date (or the approval day if that date has passed) plus the property's `reservation_expiry_days` (default 7). Unclaimed reservations expire daily; the boarder is reminded the day before. |
| One reservation per boarder | Approving one cancels the boarder's other pending applications automatically. A boarder can have up to 5 pending applications. |
| Last free unit taken | The property's other pending applicants are declined ("The place is now fully booked"). |
| Street address | Hidden from a boarder until their reservation is approved. |
| Full places stay on the Finder (2026-10-09) | Published places of verified owners are always found (`Property::scopeOnFinder`), marked `is_full` when no unit is free and ready, and listed after places with a free unit. Their page can be opened, but applying needs a free unit (`scopeListable`): "This place has no free room right now." `available=1` hides them. |
| Distance (2026-10-09) | Straight-line metres, computed by the API from a `near` point (`distance_m`, `sort=nearest`); nobody types a distance. The point is a campus (the admin's list; one is the default, the first added becomes it, removing it passes it to the oldest), the user's location, or a point they tap. The choice is kept on the device. |
| Map first (2026-10-09) | The Finder opens on the map (up to 200 pins, `per_page`), green pins with the "from" price for places with a free room, grey "Full" pins; a tap shows a card with distance, price and availability. The list is the second tab. The "Who can stay" filter matches rooms for that group or for anyone. |
| Rooms & availability (2026-10-10) | The listing returns every room (`rooms`): label, floor, status (`available` when a unit is free and ready, `not_ready` when its free units are all not ready, else `full`), free and total places, capacity, "from" rent (of its free places, or of all when none is free) and who can stay; never who lives there. The app draws floors as slanted slabs, top floor first, rooms without a floor on the bottom slab; a tap opens the room with Apply. Owners and caretakers get the same view on the property's Rooms tab (Floors / List), with tenant names (`units[].tenant_name`) and reservation holders on the tiles; a tap opens the room's people. |
| ID file on an application | Private; deleted 30 days after the application closes. |
| Planned occupants and leader (2026-10-06) | When approving a whole room, the owner or a Manager can list the people who will stay and mark the applicant as the room's leader. Both are only a plan until move-in. |

## Tenancy up to move-in (Phase 7, 2026-10-06)

| Decision | Detail |
| --- | --- |
| A tenancy starts `active` | There is no "reserved" tenancy: the approved application is the reservation. Notice, move-out, overstay and settlement come later. |
| One current tenancy per boarder | A boarder with a tenancy cannot be moved in elsewhere until it ends. |
| Walk-ins need an account | The owner or a Manager moves a walk-in into an available unit using the person's DormLink account. Someone holding a reservation must be moved in from it instead. |
| Move-in date | Today, or up to 30 days back (`TenancyService::MAX_DAYS_BACK`), never in the future. |
| Move-in payments | The deposit and the advance first rent are stored in `tenancy_payments`, apart from bills. With "activation required" on, both must be paid in full unless the owner or a Manager overrides with a reason. |
| Moving in withdraws other applications | The boarder's other pending applications are withdrawn ("You moved into …"). |
| Room leaders | One per room, appointed by the owner or a Manager with the person's consent recorded by them (no in-app acceptance). The leader must live in the room. In a whole room the leader is the person who rents it, set at move-in; a bedspace room's leader can be replaced. |
| Occupants of a whole room | Kept with join and leave dates. Never more people than the unit's capacity, counting the tenant; the owner raises the capacity first. Only the leader's changes notify the owner and Managers. |
| Discounts | Optional, manual and effective-dated like prices: from today or later, never back-dated, a scheduled one is replaced, and one a bill used is locked (`locked_at`). |
| Rental mode is per room | See *Rooms (2026-10-05)*; this reverses the guides' property-level rental mode. |

## Billing and payments (Phase 8, approved 2026-10-10, built 2026-10-16)

Done: all eight steps. Left for later phases: reminders and the overdue list (C7), PDF receipts and statements (A9), marking a charge "under review", online payments, editing a reported amount when confirming.

| Decision | Detail |
| --- | --- |
| Anniversary bills (step 2) | `BillingService` / daily job `billing.issue_rent_bills`. On each tenancy's due date: rent for the period starting at the price in force on its first day, minus the agreed-rate discount in force that day, plus fixed utilities for the period just finished (price on that period's first day). Period 1 is the move-in payment, so the first bill is period 2. Issuing locks the prices and discount used and takes the bill number in one transaction. One bill per tenancy period (unique in the database); missed days are caught up. |
| Which fixed utilities | "Fixed per bedspace" on bedspace tenancies, "fixed per property" only on whole-room tenancies (a whole room or a whole-room option). Only accounts the owner bills; an account with a room (`utility_accounts.room_id`) only bills that room. "Billed by the boarders" accounts never appear on owner bills. |
| Metered bills (step 3) | `MeteredBillService`. The owner or a Manager enters the provider's bill (coverage dates, total, optional private photo). A room meter bills that room's leader in full; a whole-property meter is split across rooms by occupant-days within the coverage (bedspace tenants from move-in; in a whole room every listed occupant from joining). Each share is its own bill to the room's leader, due the property's utility due days (default 7) after entry. The split is saved with the entry (`utility_bills.shares`). |
| Room without a leader | A room with a share and no leader stops the entry ("Room A has no leader. Appoint one, or waive its share."). A waived share is absorbed by the owner and recorded. |
| Correcting a metered total | A new entry replaces the old (`replaces_id`, reason required) with the same split; each room's bill gets an adjustment line for the difference and its leader is told (SC-14). Only the latest entry can be corrected. |
| One-time charges and corrections (step 4) | Owner or Manager only (`EditIssuedBills`), reason required. A charge is its own bill, due on a chosen date (default: utility due days). A correction is an adjustment line (positive charges more, negative is a credit); a bill never goes below ₱0. Issued lines are never edited. |
| Payments (step 5, built 2026-10-16) | Recorded by hand only (`PaymentService`). Staff-recorded payments (owner or any caretaker): reference no. and proof optional, confirmed at once, numbered receipt (OR-…). A boarder's report needs a reference no. or a screenshot, only for a place where they stay or stayed, and stays Pending until the owner or a caretaker (Managers and Collectors) confirms or rejects it with a reason. A reference no. used on another payment to the same owner (case-insensitive, rejected ones ignored) is flagged to staff. The paid date is never in the future. |
| Applying money (step 5) | `PaymentLedger`: confirmed payments and credit lines (negative adjustments) settle charge lines oldest due date first; same due date: rent, then utilities, then other charges. A credit line settles its own bill first. Left-over money is credit, applied automatically when the next bill, charge or correction is issued (SC-34). Each bill shows `balance_centavos`. |
| Reversing a payment (step 5) | Owner or Manager only, reason required. The payment stays on record as Reversed with its receipt number; a reversal entry (`reverses_id`) records who, when and why. Its allocations are removed, so the charges are owed again unless other credit covers them. A boarder cannot withdraw a pending report; staff reject it instead. Staff cannot change the amount of a reported payment when confirming: reject it, or record the right amount. |
| Owner and caretaker screens (step 6, built 2026-10-16) | Property page tab "Bills & payments" for everyone who records payments: unpaid total, payments to confirm (opens first when any wait; reused references marked), unpaid and paid bills, payment history. "Record a payment" shows what the payer owes first and fills in that amount. Owner and Managers also add one-time charges, correct a bill, and reverse payments; Collectors record, confirm and reject only. Metered bills are entered from the Utilities tab ("Enter bill" on an actual-bill utility the owner pays), with each room's share shown before billing and rooms without a leader waived or blocked. New API: `GET properties/{p}/bills` (`status` unpaid / paid / all, with `balance_centavos`, `status`, `is_overdue`). |
| Boarder screens (step 7, built 2026-10-16) | New "Bills" tab for boarders (`/boarder/bills`, where bill and payment notifications already pointed): per place what they owe and their credit, how to pay (the owner's GCash or bank details, shown only to people who stay or stayed there), bills to pay and paid, and their payments with receipt numbers or rejection reasons. "I paid: tell the owner" starts from the amount owed and needs a reference no. or a screenshot. Home shows "You owe ₱…" when something is due; My stay links to the bills. New API: `GET me/bills` (`meta.places`). |
| Property types (2026-10-10) | Dormitory, Boarding house, Apartment, Studio, House, Condo unit. "Mini house" removed; existing ones become House. |

## Reminders, overdue list and documents (Phase 9, C7 + A9, approved 2026-10-16, built 2026-10-17)

Steps: 1 tables, 2 daily reminder job, 3 overdue list API, 4 PDF documents, 5 owner and caretaker screens, 6 boarder screens, 7 wrap-up (end-to-end test through `dormlink:daily`). All built. Left for later: settlement summaries (with C8), a remark history screen (the API has it).

| Decision | Detail |
| --- | --- |
| Tables (step 1, built 2026-10-16) | `bills.late_at` + `late_notice_number` (NTC-…) + `late_marked_by`; `bills.under_review_at` / `_by` / `_reason`; `bill_reminders` (one per bill, kind and day; the balance at the time); `collection_notes` (remark + optional promised date, never edited). Enum `ReminderKind`: due soon, due today, overdue, marked late, manual. |
| Daily reminder job (step 2, built 2026-10-16) | `ReminderService` / daily job `billing.send_payment_reminders`, run right after the day's bills are issued. Per property settings: due soon (`remind_before_days`), due today, overdue on `overdue_reminder_days`, marked late the day after the grace days (`grace_days`, default 3, so day 4) with an NTC notice number (audited). The separate "mark late on day" setting was dropped on 2026-10-16 (owner decision): grace days alone decide it, so the two can never disagree. Only bills with something owed and not under review. No "due today" for a bill issued that day (its "New bill" message already says it). One message per payer, place and kind per day, listing the bills and what is left on each. The log uses `insertOrIgnore`, so a second run sends nothing. |
| Overdue list (step 3, built 2026-10-16) | `CollectionService`. `GET properties/{p}/collections`: payers with a bill past due, most days overdue first, with overdue and total owed, late flag, bills under review (counted apart, not in the overdue amount), last reminder, latest remark. Owner and all caretakers (`view_arrears`) see it and add remarks; any of them can remind now (`send_reminders`, once a day per payer, bills due today or earlier) and mark an overdue bill late early (`mark_late`). Under review: owner or Manager (`edit_issued_bills`), reason required, audited; no message to the payer. Ending a review lets the next daily run catch up on late marking. |
| PDF documents (step 4, built 2026-10-16) | Documents module (`DompdfDocumentService`, barryvdh/laravel-dompdf 3.1, DejaVu Sans with font subsetting, about 20 KB a file). Receipt: confirmed or reversed payments only, shows which bills it paid and any credit; a reversed one carries a REVERSED stamp. Statement of account (`StatementBuilder`): bills on their issue date, corrections on the day added, payments on the day paid, reversals add back; running balance; the closing balance equals the ledger. Late notice: bills marked late only, "not a notice to vacate". Links: `GET payments/{p}/receipt-link`, `bills/{b}/notice-link` (payer or staff), `properties/{p}/payers/{u}/statement-link` (staff), `me/statement-link` (payer); each returns a signed URL valid for `private_file_url_minutes` (30). |
| Staff screens (step 5, built 2026-10-16) | "Bills & payments" tab gets an **Overdue** view (anyone with `view_arrears`): who is behind, days overdue, late and under-review marks, last reminder, latest remark with promised date; actions Record payment (opens the sheet for that payer), Remind now (`send_reminders`), Add remark, Statement (PDF). Bill cards show Late and Under review badges. The bill sheet shows the review reason, "Late notice (PDF)", "Mark late now" (`mark_late`, overdue bills only, asks first) and Put under / End review (owner or Manager). Payments with a receipt number get a "Receipt" button. PDFs open in a new tab (opened before the link is fetched, so pop-up blockers allow it). Only the latest remark shows on the list; the full history is in the API for later. |
| Boarder screens (step 6, built 2026-10-16) | My bills: "Late" and "Being checked" (under review) badges on bills; the bill sheet has "Late notice (PDF)" for a late bill; each place has "Statement (PDF)"; each payment with a receipt number has "Receipt (PDF)". The review reason is visible to the boarder on the bill. |
| Missed days | Reminders are not back-filled: a day the job did not run sends nothing late. Marking late does catch up. |
| Who is reminded | Only the payer (the boarder, or the room leader for shared bills). Staff use the overdue list instead of getting emails. |
| Under review | Owner or Manager; stops reminders and late marking for that bill. |
| Remarks | Notes only. A promised date is shown, never acted on. |
| PDFs | `barryvdh/laravel-dompdf`, made on demand from stored data (not stored), opened through short-lived signed links. No QR codes. |
| Left out | Late fees, notice to vacate, early-warning flag, level 4 arrears, settlement summaries (with C8). |

## Map and rooms view (approved 2026-10-09, all six steps built by 2026-10-10)

From the professor's review. Built in this order, one step at a time:

1. **Buildings are removed** (done 2026-10-09). Structure is Property (the building or dorm: one map pin, rules, photos) → Room → Bedspace. An owner with two buildings lists two properties. Room codes lose the building part (`F2-03`); numbering stays per property.
2. **Rooms get an optional door number and "Who can stay"** (done 2026-10-09). The door number is typed by the owner, unique within the property, and shown instead of the code when set. "Who can stay" is Female only, Male only or Any.
3. **The map is the Finder's default view** (done 2026-10-09). One pin per property, green with free rooms and grey when full (full properties are shown too). Distance is a straight line from a campus (default; the admin manages the campus list), the user's location, or a point they tap. Nobody types a distance.
4. **Rooms & availability view** (done 2026-10-10) on the map card and the listing: floors, then room tiles marked Available, Full or Not ready. A room shows its price and "2 of 4 bedspaces available". Drawn in a stacked 3D look (user, 2026-10-09): each floor is a slab at an angle, lowest at the bottom, with its room tiles on top; rooms without a floor share one slab. The owner sees the same view for their own properties with the tenants' names on each tile, and so do the caretakers assigned to that property (Managers and Collectors; user, 2026-10-10), matching the room view. Public screens never show who lives where.
5. **Boarders apply to a room** (done 2026-10-10) (a bedspace or the whole room; until step 6 the room's rental mode decides which). The owner can assign a different room or bed when approving, and the boarder is told.
6. **An empty bedspace room can also be rented whole** (done 2026-10-10) when the owner allows it and sets a whole-room price. The whole-room choice and the bedspaces block each other.

## Scheduler and data

| Decision | Detail |
| --- | --- |
| Daily jobs | `dormlink:daily` at 09:00 Manila. Each job runs once per day; failed or stale jobs are retried. `--date` catches up past days only; a future date needs `--simulate`, which runs the jobs (with real effects) without marking them done. |
| Time zone | App and database connection are both `Asia/Manila`. In the app, calendar dates (`…_on`, `reserved_until`, `effective_from`) use `manilaDate()`, moments use `manila()`, and clock times (curfew, visitor hours) use `clockTime()`. |
| Activity log | Never cleaned automatically; it is the paper trail. |

## Left out for now (by choice)

- Online payments (GCash or Maya gateway): postponed 2026-10-10 until the panel review confirms they are needed. E-wallet and bank transfer payments are recorded by hand.
- Server setup: cron, queue worker, production URLs, map tiles, online payment provider. When it is done, PHP needs at least `upload_max_filesize = 6M`, `post_max_size = 30M` (an owner application can carry 5 files of 5 MB), the GD extension with WebP, and room to raise `memory_limit` to 512M while a photo is resized. The app uploads listing photos one per request, so the usual 30-second time limit is enough.
- Privacy notice (current text is a draft).
- Account deletion or deactivation by the user.
- Updating the spec guides.
- UI tests (the owner tests the UI by hand).
