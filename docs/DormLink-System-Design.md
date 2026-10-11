# DormLink System Design & Developer Guide

Oct 4, 2026 · @Leynard

**DORMLINK: A Mobile-Based Boarding House Management and Dormitory Finder System.** Formerly BoardMate (renamed 2026-10-09). Folders, databases, config and commands now use `dormlink`.

DormLink is 27 subsystems in four groups, scoped for a first release by one or two developers. Bedspacers pay the owner individually, billing uses anniversary due dates only, and every group cost (shared utilities and shared expenses) is settled through shared contributions under a room or property leader. Only C4, C5, C6 and D5 need heavy design; everything else is deliberately plain, so developers should not add options, screens or settings beyond what is written here.

This guide merges the *System Features Guide* (F1–F11), the *Billing and Tenancy Design Guide* (S1–S10, SC-01 to SC-34) and `dormlink/docs/DECISIONS.md`. Where they disagree, DECISIONS.md and the code win. Section 2 lists where this guide deliberately differs from the two earlier guides. Status is as of 2026-10-09.

## 1. Subsystem features at a glance

**Complexity:** *Simple* = standard CRUD or an off-the-shelf package. *Standard* = a few rules or a state machine. *Complex* = money or date logic that needs worked-example tests. **Status:** *Built* = phases 0–7 done (Phase 7 = tenancy foundation). *Next* = Phase 8, billing. *Planned* = Phase 1, not started. *Later* = after Phase 1.

### A. Platform foundation

| ID | Subsystem | Key features | Complexity | Status | First release |
| --- | --- | --- | --- | --- | --- |
| A1 | Accounts and sign-in | Email or Google sign-up with a consent step; verified email before login; 30-day idle token expiry; suspension revokes access | Simple | Built | Keep |
| A2 | Roles, permissions, data separation | Admin, owner, caretaker (Collector or Manager), group leader, boarder; role switching; no owner sees another owner's data | Standard | Built | Keep, leader role added |
| A3 | Owner onboarding and verification | Any account can apply; ID and property proof reviewed by the admin; listings hidden until verified; documents deleted after 90 days | Simple | Built | Keep |
| A4 | Caretaker management | Link caretakers, assign per property, Collector or Manager level, every action audited | Simple | Built | Keep |
| A5 | Files and photos | Public listing photos (WebP, no GPS), private proofs and IDs, signed expiring links | Simple | Built | Keep |
| A6 | Notifications | One service for in-app and email messages, templates in one place, queued retries, 9:00 AM reminders | Simple | Built | Keep |
| A7 | Audit log | Permanent who-changed-what record for money, prices, approvals and caretaker actions | Simple | Built | Keep |
| A8 | Scheduler | One daily job at 09:00 Manila, safe to repeat, catches up missed days | Standard | Built | Keep |
| A9 | Numbering and documents | Receipt and bill numbers per owner; PDF receipts, statements of account, settlement summaries and notices | Standard | Numbering built, documents planned | Full, no QR codes |
| A10 | Dashboards and reports | Owner, caretaker, tenant and leader dashboards; nine CSV reports | Simple | Planned | Full |

### B. Listings and booking

| ID | Subsystem | Key features | Complexity | Status | First release |
| --- | --- | --- | --- | --- | --- |
| B1 | Properties, rooms, units, prices | Rooms in every property, each rented whole or by bedspace, with optional floor, door number and code such as F4-03; who can stay; unit statuses and Not ready flag; utility accounts; effective-dated prices; member emergency contacts in the room view | Standard | Built | Extended |
| B2 | Dorm Finder | Public search with map and filters; listing pages; address hidden until a reservation is approved | Simple | Built | Keep |
| B3 | Booking and reservations | Apply to a property; owner assigns a bedspace or a whole room and names the leader; reservation expires if unclaimed | Standard | Built | Extended |
| B4 | Map and rooms view | Map-first Finder; rooms by floor in a stacked 3D view; tenant names for the owner; apply to a room; whole-room option | Standard | Steps 1–5 built; step 6 next | New |

### C. Tenancy and billing

| ID | Subsystem | Key features | Complexity | Status | First release |
| --- | --- | --- | --- | --- | --- |
| C1 | Tenancy lifecycle | Move-in with deposit and first rent; month-to-month stay; notice with end date; overstay flag | Standard | Move-in built; notice and overstay later | Simplified |
| C2 | Group leaders and occupants | One billing leader per room; occupant list; leader changes by owner and member approval | Standard | Built; member approval later | Replaced |
| C3 | Charge rules and pricing | Fixed, actual-bill and included charges; effective-dated price changes; one per-tenancy discount; one-time charges | Standard | Discount built; rest planned | Simplified |
| C4 | Billing cycles | Rent due on each tenancy's anniversary, in advance; utilities billed in arrears; immutable bills with adjustment lines | Complex | Next | Simplified |
| C5 | Split engine | Equal, occupant-days, weights, custom amounts; centavo-exact rounding | Complex | Engine built | Keep |
| C6 | Payments and receipts | Cash and proof upload; oldest-first allocation; credit for early payment; numbered receipts | Complex | Planned | Simplified |
| C7 | Arrears | Due-soon, due-today and grace reminders; marked late on day 4; overdue list for collectors | Standard | Planned | Simplified |
| C8 | Deposit and settlement | Deposit ledger; deductions with reasons; refund; shortfall charged | Standard | Planned | Simplified |

### D. House operations

| ID | Subsystem | Key features | Complexity | Status | First release |
| --- | --- | --- | --- | --- | --- |
| D1 | Maintenance requests | Category, photos and urgency; assign; resolve and confirm; optional one-time charge | Simple | Planned | Required |
| D2 | Curfew and late entry | Optional curfew time; reminder 30 minutes before; late-entry requests; violation log | Simple | Planned | Required |
| D3 | House rules | Rules by category; versions with acknowledgement; acceptance recorded at move-in | Simple | Planned | Required |
| D4 | Visitor management | Advance registration; walk-in log; overnight approval and monthly limit | Simple | Planned | Required |
| D5 | Shared contributions | Group ledger per room or lease; owner shared bills posted as locked lines; member expenses; repayments; four split methods | Complex | Planned | Required, first version |

## 2. Billing and group model

The owner bills one person per billing unit, and everything a group owes each other lives in shared contributions (D5).

&#91;embedded content: who pays whom · 2 property types, leader highlighted\]

In a room rented by bedspace each bedspacer pays the owner for their own bed; only shared utility bills go to the room leader. In a room rented as a whole the leader is billed for everything and the group splits it.

### Structure

Property → Room → Bedspace. A property is the building or dorm, with one map pin; an owner with two buildings lists two properties. Every property has at least one room, and each room is rented either as a whole or by bedspaces, so one property can mix both. A room rented as a whole is itself the rentable unit and has no bedspaces. A house or studio rented as a whole is a property with one room.

| Concept | Rule |
| --- | --- |
| Rental mode | Set per room: whole or bedspaces. It changes only while nothing in the room is in use, and never because a member joins |
| Room | Exists in every property and is rented either as a whole or by bedspaces. Has an optional floor number and an automatic code such as F4-03 (floor 4, room 3; just 03 when the room has no floor). The floor is a number on the room, not a separate table. Optional door number ("Room 201"), shown instead of the code, and "Who can stay" (Any, Female only, Male only) |
| Occupants | The owner records everyone who stays (name, move-in date, optional account). In a room rented as a whole the owner has no transactions with members, only the leader |
| Leader | One per room. The owner appoints the first leader. The leader is automatically the admin of the D5 group; the owner or the group may add moderators |

### Who is billed for what

| Charge | Room rented by bedspace | Room rented as a whole |
| --- | --- | --- |
| Rent and fixed inclusions | Each bedspacer, for their own bed | The leader |
| Shared utility bills (actual bills) | The room leader, auto-posted to the room group | The leader, auto-posted to the room group |
| Deposit | Each bedspacer | The leader only |
| Move-out notice | Each bedspacer | The leader; the owner is told when occupants change |
| Payments and receipts | Each bedspacer pays and gets their own receipt | The leader pays the owner the full amount |
| Standing and reminders | Individual | The leader |

Members repay the leader inside D5, by cash or online, recorded as marked paid and confirmed. The leader pays the owner in full first, so a member's unpaid share shows in D5 as owed to the leader and never as unpaid to the owner.

*Confirmed:* "shared utilities" means actual-bill utilities (metered electricity, water, internet). Fixed utility prices are inclusions on each bedspacer's own bill.

### Leader changes

| Scenario | Solution |
| --- | --- |
| Who can propose a new leader | The owner, the current leader, or any member |
| What makes it take effect | The owner or the current leader approves, and a majority of the active members approve (more than half). It starts at the next billing period |
| No leader, or leader gone | The owner may set one. If the owner does not, the group chooses by majority vote |
| Unpaid shared bills at the time of change | They move to the new leader. D5 keeps each member's own share on record |
| Leader leaves with notice | Must hand over before the move-out notice is approved |
| Leader leaves without notice | The owner appoints an interim leader and the group may vote a replacement |
| Leader agrees to responsibility | Consent is recorded when appointed or elected, with date and who approved |

### Changes from the earlier guides

| Earlier decision | Now |
| --- | --- |
| No room level (D1 in the Billing guide) | Rooms added to every property, each rented whole or by bedspace; optional floor number on a room |
| Lease holder with billed-through or direct billing and liability-change requests (S3) | One leader per room, plus an occupant list. No hybrid billing |
| Shared Expenses built later (S10) | Required in the first release; it holds all group bills |
| Opt-in charges, exempt and weight overrides, common and hybrid due dates (S4, S5) | Removed. Anniversary due dates only, with one per-tenancy discount |
| Escalation levels 4 to 6, late fees, promises, plans (S8) | Removed. Reminders, grace and overdue list only |
| Holdback, true-up, belongings handling (S9) | Removed. Deposit minus deductions, shortfall charged |
| QR codes on receipts, timed disputes | Removed. The owner adjusts a charge instead |
| Rent and fixed utilities on the same bill for the coming period (SC-02) | Rent in advance, utilities in arrears; the move-in payment (deposit + period 1 rent) is separate from bills |

Log these in `dormlink/docs/DECISIONS.md` when the work starts.

## 3. Architecture and design principles

DormLink is one Laravel API and one Ionic app, and every subsystem reuses the same shared service modules, so adding a feature never means adding infrastructure.

&#91;embedded content: system layers · 5 layers, 8 shared modules\]

Read top to bottom: the app talks only to the API, and subsystems reach shared behaviour only through the highlighted modules.

### Design principles

| Principle | What it means for a developer |
| --- | --- |
| Keep simple things simple | A Simple subsystem gets a table, a policy, a form request, a notification and an audit entry. No extra settings, states or screens unless this guide lists them. A new setting needs a written reason in DECISIONS.md. |
| One place for each rule | Unit status changes only through `UnitStatusService`; money through `Money`; dates through `ManilaDate`; splits through `SplitEngine`; numbers through `Numbering`. Never re-implement these in a controller. |
| Money is integer centavos | `bigint` columns ending `_centavos`. Never float or decimal. Writes that touch money run inside `DB::transaction`. |
| History is never rewritten | Soft deletes on anything with money; prices are effective-dated; issued bills are immutable and corrected by adjustment lines; the audit log is append-only. |
| Defaults before options | Every owner setting has a default, and the system must work with all defaults untouched. |
| Owners never see each other | Every query is scoped to the owner's properties; policies check this on each action. |
| Safe to run twice | Scheduled work computes what is due as of a day, so a repeated or missed run never double-charges. |
| Decisions are written down | A choice that differs from the guides goes into `dormlink/docs/DECISIONS.md` the same day. |

### Shared service modules

Subsystems depend on a module's contract (interface), never its implementation. Bindings live in `ServiceModulesProvider`.

| Module | Use it for | Used by |
| --- | --- | --- |
| Files | Every upload and every file link (public or signed private) | A3, A5, B1, B3, C6, D1 |
| Notifications | Every message to a person, with templates in one folder | All subsystems |
| Audit | Changes to money, prices, approvals, overrides and caretaker actions | A3, A4, B1, B3, C1–C8 |
| Numbering | Receipt, bill and notice numbers, inside the saving transaction | A9, C4, C6, C7 |
| Scheduler | Daily jobs: implement `DailyJob`, register in `config/dormlink.php` | B3, C4, C7, D1, D2, D4 |
| Split | Dividing any amount among people | C5, D5 |
| BillingCalendar | Due dates, rent periods, proration, utility due dates | C1, C4 |
| SocialAuth | Verifying a Google ID token | A1 |

## 4. Subsystem details

Each subsystem lists its purpose, the rules developers build to, and the scenarios that can happen with the agreed solution. Scenario IDs like SC-10 point to the Billing and Tenancy guide; scenarios marked *proposed* are new here and need owner confirmation.

### A. Platform foundation

#### A1. Accounts and sign-in

**Purpose:** create and secure every account. Only the super admin is seeded; everyone else signs up on the web.

- Sign-up by email or Google, with a consent step. Email must be verified before a token is issued.
- Tokens are Sanctum bearer tokens that expire after 30 idle days; each use extends them and a daily job prunes expired ones.
- Google ID tokens are verified on the server. A new account is created only after consent; an account with the same email is linked automatically.
- One account can be both owner and boarder, with role switching.

| Scenario | Solution |
| --- | --- |
| Login before verifying the email | Refuse with a clear message and offer to resend the link |
| Google sign-in with an email that already has an account | Link to the existing account, no duplicate |
| User changes their email | New address must be confirmed first; the old address is told; sign-in email stays unchanged until then |
| Token idle for 30 days | User signs in again; the prune job removes the old token |
| Forgot password | Reset link by email |
| Boarder account suspended | Tokens revoked; pending applications and the reservation are cancelled, the unit is freed and owners are told |
| User asks to delete their account | Not built yet (deferred). Admin suspends it meanwhile; record as an open item |

#### A2. Roles, permissions and data separation

**Purpose:** decide who may do what, and keep every owner's data private from every other owner.

- Roles: platform admin, owner, caretaker (Collector or Manager per property), group leader (per room), boarder. Built on spatie/laravel-permission; Policies check every action.
- The platform admin verifies owners and suspends abuse but never sees tenants' billing.
- Every query is scoped to the caller's own properties.

| Scenario | Solution |
| --- | --- |
| Same person is owner and boarder | Role switching; each request is checked against the active role and its data scope |
| Caretaker guesses another property's id | Scoped query plus Policy returns not found or forbidden; keep a test for it |
| Collector tries to edit an issued bill | Refused. Only Manager or owner may, and only through adjustment lines |
| Admin wants to view a tenant's bills | Not allowed; admin sees verification and suspension data only |

#### A3. Owner onboarding and verification

**Purpose:** make sure listings come from real owners.

- Any signed-in account can apply. It gets the owner role at once to set things up, but listings stay hidden until the admin verifies it.
- Required proof: a valid government ID and one proof of the property. Optional: business or barangay permit. Up to 5 files (jpg, png, webp, pdf; 5 MB each), private, shown to the admin by signed expiring links.
- Files are deleted 90 days after the verify, reject or suspend decision; the rows stay with `purged_at`.

| Scenario | Solution |
| --- | --- |
| Application is missing a required document | Validation error; it cannot be submitted |
| Admin rejects the application | Reason shown to the owner, who may apply again with new documents |
| Owner tries to publish before verification | Blocked; publishing needs a verified owner |
| Owner is suspended | Listings hidden and pending applications declined ("not taking bookings right now"); existing reservations stay until they expire |
| Owner applied before documents were required | Admin sees a warning on the application |
| Someone reuses a document link later | Signed links expire; request a new one |

#### A4. Caretaker management

**Purpose:** let owners delegate day-to-day work without giving everything away.

- Owner links a caretaker; the link has a default level (new caretakers start as Collector). Each property assignment has its own level, and that level decides what the caretaker can do there.
- Collector: record and confirm payments, send reminders, mark late, approve move-out dates. Manager: everything the owner can do on the assigned properties except add or remove caretakers, change the owner's payment details, delete a property or transfer ownership.
- Changing the level in the Caretakers tab applies to all that owner's properties; the list shows "Levels differ per property" when they no longer match.
- Every caretaker action is audited with the caretaker's name.

| Scenario | Solution |
| --- | --- |
| Caretaker should manage house A but only collect at house B | Set the level per property assignment |
| Caretaker works for two owners | Two separate links; each owner sees only their own data |
| Caretaker leaves | Remove the link; all assignments with that owner end at once; audit entries stay |
| Owner only wants to watch | Use dashboards, reports and the audit log; no caretaker needed |
| Something was changed by mistake | Audit log shows who, what and when |

#### A5. Files and photos

**Purpose:** one safe way to store every file.

- All uploads go through the Files module. Public: listing photos. Private: payment proofs, bill photos, maintenance photos, IDs, owner documents.
- Listing photos: up to 15, saved as WebP (large 1600 px, thumb 480 px), EXIF and GPS removed, original not kept, anything over 8200 px a side refused. Profile photos are a 256 px WebP square.
- An application's ID file is deleted 30 days after the application closes.

| Scenario | Solution |
| --- | --- |
| Huge phone photo | Resized to WebP and stripped of location data |
| Wrong file type or too large | Validation error that lists the allowed types and size |
| Older uploads not yet converted | Run `dormlink:rebuild-images` |
| Upload fails on the server | Check PHP: `upload_max_filesize` 6M, `post_max_size` 30M, GD with WebP, `memory_limit` able to reach 512M |
| Private file requested after its retention period | Show "file no longer kept"; the record stays |

#### A6. Notifications

**Purpose:** one service every feature calls to message a person.

- Channels: in-app (with unread count) and email. Push comes after the Android build. SMS is not used because of per-message cost.
- Call `send()` or `sendToAddress()`; wording lives in one `Templates/` folder written by one person so the tone stays consistent.
- Reminders go out at 9:00 AM Asia/Manila. With `NOTIFICATIONS_QUEUE=true` a queue worker retries failed emails.
- Boarders can mute only the curfew reminder; everything else is mandatory.

| Scenario | Solution |
| --- | --- |
| Email provider is down | Message stays queued and the worker retries; the in-app copy is unaffected |
| A new event needs a message | Add a template and call the service; never send mail directly from a controller |
| Daily job runs twice | Scheduler skips finished jobs, so reminders are not duplicated |
| Boarder mutes curfew reminders | Respect it for that type only |

#### A7. Audit log

**Purpose:** the permanent paper trail for disputes and for owners who delegate.

- Record every change to money, prices, approvals, overrides and caretaker actions with `Audit::record()`; use `AuditDiff::between()` for before and after.
- Entries are never edited or deleted, and `activitylog:clean` must not be scheduled. Scopes: `owner`, `admin`, `account`.
- Account numbers are masked (`•••• 6789`); passwords and tokens are never recorded.

| Scenario | Solution |
| --- | --- |
| Boarder disputes a price change | Show the audit entry with before, after, who and when |
| Caretaker overrode a rule | The entry names the caretaker and the reason given |
| Log becomes very large | Keep it by design; add indexes or archiving later, never deletion |
| A sensitive value would be logged | Mask or omit it before calling `record()` |

#### A8. Scheduler

**Purpose:** run all time-based work once a day.

- `dormlink:daily` runs at 09:00 Asia/Manila. Every job implements `DailyJob` and is listed in `config/dormlink.php`.
- `scheduler_runs` records each job per day; finished jobs are skipped, failed or stale jobs are retried.
- A job computes what is due as of the given day, so a missed or repeated run never double-charges.

| Scenario | Solution |
| --- | --- |
| Server was down for days | Run `--date=YYYY-MM-DD` for each missed past day |
| A job fails halfway | It is retried on the next run; finished jobs stay skipped |
| Run twice on the same day | Second run does nothing for finished jobs |
| Need to test a future day | Use `--simulate`: real effects, but jobs are not marked done |
| Going to production | One cron entry for `schedule:run` plus a queue worker under Supervisor |

#### A9. Numbering and documents

**Purpose:** unique readable numbers and printable documents.

- Numbers are per owner, such as OR-2026-000123, taken with `Numbering::next()` inside the transaction that saves the document. *Built.*
- Documents (planned): PDF receipts (no QR code in the first release), statements of account, settlement summaries and formal notices, using dompdf and simple-qrcode.

| Scenario | Solution |
| --- | --- |
| Four cashiers save receipts at the same moment | Numbering inside the transaction; the concurrency test proves no duplicates or gaps |
| A receipt was issued in error | Never renumber or delete; issue a correcting entry that references the original (proposed) |
| A document must be shown later | Regenerate from stored data, or store the PDF through the Files module |

#### A10. Dashboards and reports

**Purpose:** give owners, caretakers, boarders and group leaders a complete view of their part of the business. Planned, full scope.

- Owner and caretaker widgets: occupancy per property and room, due this week, overdue and collection list, collected this month (caretaker: Manager only), pending applications and proofs, open maintenance, visitors today and overnight requests, late-entry requests, rooms with unpaid shared bills, recent activity (owner only).
- Tenant dashboard: current bill, balance and credit, pay, history, my unit (with the property's emergency numbers), requests, group balances (D5), move-out, notifications. Leaders also see the group's shared bills and member balances.
- Critical reports as CSV *(confirmed list)*: collections by date range; outstanding balances with overdue ageing; occupancy per property, floor and room; deposit ledger; upcoming move-outs and expiring reservations; utility bill history per room or property; price change history; maintenance open and closed; visitor and late-entry logs.
- Keep it simple: every widget and report reads the same tables as the rest of the system. Do not store separate totals.

| Scenario | Solution |
| --- | --- |
| Dashboard total differs from the ledger | Not possible by design: both compute from the same rows |
| New owner with no tenants | Show empty states with the next step, not zeros everywhere |
| Owner wants to find rooms with unpaid shared bills | Room widget lists the room, its leader and the amount |
| Large CSV export | Stream the file; move to a queued job only if it becomes slow (proposed) |
| Collector opens "collected this month" | Hidden; Manager and owner only |

### B. Listings and booking

#### B1. Properties, rooms, units and prices (S1)

**Purpose:** model what can be rented, how it is grouped, what state it is in, and what it costs.

- Structure: Property → Room → Bedspace, with no building level (removed 2026-10-09). A property is a dorm, apartment, house or studio and has at least one room. Each room is rented either as a whole or by bedspaces, so one property can mix both. A house or studio rented as a whole is a property with a single room. Each room has one leader (C2).
- A room has an optional floor number and an automatic code such as F4-03 (floor 4, room 3; just 03 when the room has no floor). Floor is a number on the room, not a separate table. The owner can filter and report by floor. A room can also have a door number, unique in the property and shown as "Room 201" instead of the code, and a "Who can stay" tag: Any, Female only or Male only. The room view lists the room's members with each member's emergency contact, visible to the owner and to the caretakers assigned to the property.
- The rentable unit is a whole room or one bedspace, depending on the room's rental mode. A room's mode is set when it is created and never changes because a member joins. Unit statuses: Available, Reserved, Occupied, Leaving, Overstaying. A separate Not ready flag hides a unit that needs cleaning or repair.
- Status changes go only through `UnitStatusService`, with a fixed list of allowed moves. A disallowed move, or one based on an out-of-date status, is an error.
- "In use" means reserved, occupied, leaving or overstaying. One rule (`RentableUnit::isInUse`, `Property::occupancyBlocks`) blocks deleting a property or room, switching a room's rental mode and removing a unit, under the same property lock that booking approval takes.
- Publishing needs a verified owner, a map pin, at least one photo, rent on every unit and at least one unit ready to rent. The property may also keep a short list of emergency numbers (police, ambulance, hotline, barangay), shown to its tenants under My unit.
- Prices are effective-dated and never overwritten, with no back-dating. A price a bill used is locked by `PriceBook::lock` and can only be ended by a later price. Owners may change or schedule prices at any time.
- Utility accounts attach to the property, or to a room when the room has its own bill. Each has a method (see C3) and a *billed by* setting of Owner or Group.

| Scenario | Solution |
| --- | --- |
| Owner groups bedspaces into a room | Create the room with its floor; code is generated; assign bedspaces to it |
| Owner moves an occupied bedspace to another room | Not allowed while in use; end the tenancy and start a new one (proposed) |
| Owner deletes a room or property with a unit in use | Refused. Otherwise unpublish and soft-delete; pending applications are declined ("no longer listed") |
| Owner switches a room's rental mode while someone is booked or staying | Refused, including when a member joins a whole-rented room (add them to the occupant list instead). When no unit in the room is in use, its old units are archived with their price history |
| Owner saves the price already in force | No change |
| Owner schedules a new price, then another | The scheduled price is replaced, not stacked |
| Owner wants a price effective in the past | Refused. Correct a bill with adjustment lines (C4) instead |
| Unit needs cleaning | Set Not ready; it disappears from the public list until cleared |
| One property has a single meter, another has a meter per room | Each utility account picks its own method (SC-01) |
| Publish is clicked with something missing | Block it and list exactly what is missing |

#### B2. Dorm Finder (F1)

**Purpose:** let anyone browse available places without an account.

- Shows only properties of verified owners with at least one available or soon-available unit. Properties with bedspace rooms show a count such as "3 of 6 bedspaces available".
- Map (Leaflet + OpenStreetMap), filters (location, price range, rental type, inclusions), and a listing page with photos, price, inclusions, house rules summary, curfew and slots.
- A *who can apply* label (for example female only) is shown but not enforced.
- The street address is hidden from a boarder until their reservation is approved.

| Scenario | Solution |
| --- | --- |
| A unit is Leaving | Show "Expected available from \[day after the end date\]" |
| Reserved, Occupied or Overstaying | Not shown as available |
| Owner suspended while listed | Listing disappears immediately |
| No results for the filters | Say so and suggest widening price or area |
| Boarder wants the exact address before applying | Not shown; it is revealed after approval |
| Map tiles for production | Open item: choose a tile provider before launch |

#### B3. Booking and reservations (F2)

**Purpose:** take applications and hold a unit for the chosen boarder.

- A logged-in boarder applies to a property, not a unit, with name, contact, planned move-in date and a short message; an ID upload is optional and private. Up to 5 pending applications at a time.
- The owner or a Manager picks the unit when approving: a bedspace (inside a room) or a whole room. They also name the leader when the room has none yet, and record the other people who will stay. Approval creates a reservation (unit Reserved) and cancels the boarder's other pending applications. One reservation per boarder.
- When the last free unit is taken, the property's other pending applicants are declined ("fully booked").
- Reservation lasts from the planned move-in date (or the approval day if that has passed) plus `reservation_expiry_days` (default 7). A daily job expires unclaimed ones and reminds the boarder the day before.
- Statuses: Pending, Approved, Declined, Cancelled, Expired. No reservation fee in Phase 1.
- Booking stops at the reservation; move-in, deposits and billing belong to C1.

| Scenario | Solution |
| --- | --- |
| Several applicants for one place | Owner chooses; approving one declines or cancels the rest as above |
| Boarder tries a 6th pending application | Refused with the limit explained |
| Approved boarder never moves in | Expires after the window; unit returns to Available; reminder sent the day before |
| Boarder cancels | Status Cancelled and the unit is freed |
| Applicant's account is suspended | Approval refuses them; their pending applications and reservation are cancelled |
| Owner is suspended | Pending applications on their properties are declined so boarders regain slots |
| Walk-in boarder with no application | Owner or caretaker creates the tenancy directly (C1) |
| Two approvals race for the last unit | Property lock; the second approval fails cleanly |

#### B4. Map and rooms view (planned, from the professor's review)

**Purpose:** let boarders find a place on the map, see which rooms are free floor by floor, and apply to a specific room. Approved 2026-10-09; built one step at a time, each step approved first. `dormlink/docs/DECISIONS.md` holds the details.

| Step | What | Status |
| --- | --- | --- |
| 1 | Buildings removed: a property is the building (one map pin); room codes become F2-03 | Built |
| 2 | Optional door number ("Room 201") shown instead of the code; "Who can stay": Any, Female only, Male only | Built |
| 3 | Map is the Finder's default view. One pin per property: green with free rooms, grey when full (full places are shown too). Distance is a straight line from a campus (default; the admin keeps the campus list), the user's location, or a tapped point | Built |
| 4 | Rooms & availability view in a stacked 3D look, on the map card and the listing page | Built |
| 5 | Boarders apply to a room (a bedspace or the whole room); the owner can assign a different room or bed when approving, and the boarder is told | Built |
| 6 | An empty bedspace room can also be rented whole when the owner allows it and sets a whole-room price; the whole-room choice and the bedspaces block each other | Next |

- **Stacked 3D look:** each floor is a slab drawn at an angle, stacked from the lowest floor up, with its room tiles on top. Rooms without a floor share one slab. Tapping a tile opens the room's details.
- **Room tiles** show the door number (or the code), Available, Full or Not ready, the price, "2 of 4 bedspaces available" and who can stay.
- Shared spaces such as the CR, kitchen and stairs are written in the room or property details, not drawn.
- **Owner view:** the owner, and the caretakers assigned to the property (Managers and Collectors), see the same view with the tenants' names on each tile, opening to the room view (members and emergency contacts).
- **Public screens never show who lives where.** UI words are "View rooms" and "Rooms & availability".

### C. Tenancy and billing

This group is the heart of the product. Rules here must have tests that use the Billing guide's worked examples as expected results.

#### C1. Tenancy lifecycle (S2)

**Purpose:** track a stay from move-in to final settlement. Next to build.

- In a room rented by bedspace every bedspacer has their own tenancy. In a room rented as a whole the leader has the tenancy and the other people are occupants on a list the owner can see; they have no bills, deposit or notice of their own. People joining or leaving never change the room's rental mode.
- A tenancy has no term; it runs month to month until a move-out notice or an owner-set end.
- States: Reserved → Active → Notice given → Ended → Settled. Unit status follows: Reserved, Occupied, Leaving, then Available or Overstaying. Settlement may continue after the unit is re-rented.
- Move-in from a reservation moves the unit Reserved → Occupied through `UnitStatusService`. The first day of stay is the day after move-in, and rent is due on that anchor day each month.
- Activation requirement (default on): deposit and first rent must be recorded before the tenancy is Active; the owner or a Manager may override with a required reason, which is logged (a Collector cannot). House rules are accepted at move-in (D3) and at least one emergency contact is recorded.
- Move-in payment: the deposit plus advance rent for period 1 (rent after any discount, no utilities). It is recorded as a tenancy payment, never on a bill. Bill #1 is period 2 rent plus period 1 utilities.
- Move-in rules: the owner or a Manager records it, dated today or up to 30 days back, and the tenancy starts Active. A walk-in boarder needs an account first. A boarder has one current tenancy at most, a room's occupants cannot exceed its capacity, and the owner records the leader's consent when appointing one.
- Notice: the boarder or leader files an end date, a caretaker or the owner approves it, and the unit is free the day after. An optional minimum notice only flags short notice for the owner; there is no automatic fee.
- Overstay: the day after the end date the unit shows Overstaying. This is a flag only, with no automatic daily charge.
- A tenancy can carry one rent discount (C3).

| Scenario | Solution |
| --- | --- |
| Move-in with deposit and first rent (SC-33) | Record both; tenancy becomes Active. Kim moves in on the 25th, day 1 is the 26th, next rent due on the 25th |
| Boarder gives notice (SC-10) | Record the date; owner or caretaker approves; no period starts after it; short notice is flagged |
| Boarder paid beyond the end date | Not refunded by default; owner may grant credit |
| Boarder leaves without notice (SC-11, proposed) | Owner or caretaker sets the end date with a reason; unpaid amounts stay on record and are handled in C8 |
| Boarder stays after the end date (SC-29, proposed) | Unit shows Overstaying; the owner extends the end date or follows up |
| Someone joins or leaves a whole-rented room | The leader updates the occupant list and the owner is notified. The room stays rented as a whole; the owner may schedule a new price (C3) and the leader is still the only one billed |
| Leader gives notice for the whole lease | Hand over to a new leader (C2). The lease tenancy ends when all members have moved out or when a new tenancy is booked for the room |
| Boarder moves to another bedspace or room | End the old tenancy and start a new one; rules apply from the next period (proposed) |
| Owner skips the deposit for a trusted boarder | Use the override with a reason; it is audited |

#### C2. Group leaders and occupants (replaces lease holders, S3)

**Purpose:** give every room one responsible person, and let the owner know who stays.

- One leader per room. The leader is the billing party for shared utility bills (room rented by bedspace) or for everything (room rented as a whole), and is automatically the admin of the D5 group.
- The owner appoints the first leader and records consent (date, who approved). The owner or the group may add moderators; there can be several.
- The occupant list holds name, move-in date, emergency contact (name, relationship, phone), optional contact and optional account. The leader can add people; the owner sees the list, including each member's emergency contact, in the room view and can remove entries. Caretakers assigned to the property see the same. The leader and other members do not see each other's emergency contacts. Occupant dates drive the occupant-days split.
- Leader changes follow the rule in section 2: proposed by the owner, the leader or a member; approved by the owner or current leader and by more than half of active members; effective next billing period.

| Scenario | Solution |
| --- | --- |
| Owner sets up a new room | Appoint a leader at the first move-in; record consent |
| Group rents a whole room (SC-08) | The leader is billed once; D5 splits the bill; the owner sees only the leader's payments |
| Member joins mid-month or rarely stays (SC-09) | Leader adds them to the occupant list; D5 splits by occupant-days or weights |
| One member does not pay the leader (SC-26) | The leader has already paid the owner, so it appears in D5 as owed to the leader; the owner is not affected |
| Leader wants to step down | Propose a replacement; approvals as in section 2; unpaid shared bills move to the new leader |
| Leader leaves without notice | Owner appoints an interim leader; group may vote a replacement |
| Room has no leader | Owner sets one, or the group chooses by majority vote |
| Group has only two members | Both must agree, since a majority is more than half |

#### C3. Charge rules and pricing (S4)

**Purpose:** define what a boarder is charged. Simplified: owners can change prices and give a discount.

- Methods: fixed per bedspace; fixed per property; actual bill (owner enters the total when it arrives); included in rent (no separate line). Fixed utility prices are inclusions on each bedspacer's own bill. Actual-bill utilities are shared bills and go to the leader (section 2).
- Rules are effective-dated. A change applies from the first period that starts on or after the chosen date; boarders get advance notice (default 30 days) and the owner sees a preview.
- The owner may apply one optional discount to a tenancy, manually and never automatically, as a fixed amount or percent on rent, labelled "Agreed rate". Example: a single tenant renting a whole room at an agreed rate.
- One-time charges need a reason (damage, lost key, extra occupant). Never automatic.
- Removed from the first release: opt-in charges and the exempt and share-weight overrides.

| Scenario | Solution |
| --- | --- |
| Owner pays utilities and sets fixed prices (SC-02) | Fixed rules issued with each rent period, for example ₱1,800 rent + ₱500 electricity + ₱300 water + ₱150 internet = ₱2,750 per bedspace |
| Meter bill is known only when it arrives (SC-03) | Owner enters coverage dates, total and optional photo; it is posted to the leader's group as a locked line |
| Price changes effective Nov 1 (SC-04) | Kim (due 25th) pays the old price for the Oct 26 period and new from Nov 26; Ben (due 12th) new from Nov 12 |
| Single tenant rents a whole room at a discount, then others join (SC-05) | Discount stays on that tenancy until the owner edits it; new prices apply from a chosen date; new people are added as occupants; the room stays rented as a whole and only the leader is billed |
| Room rented whole by Kim (₱4,000 rent + ₱100 water); Ericka joins | The room stays rented as a whole and cannot be switched to bedspaces. The owner schedules a new price, for example ₱5,000 rent + ₱200 water (₱100 per person), from a chosen date. Only Kim, the leader, is billed; Ericka is added to the occupant list with her move-in date, and D5 splits Kim's bill between them. The owner still sees both members and their join and leave dates |
| Owner changes the discount | Edit the tenancy discount from a chosen date; the change is audited |
| Utility bill not received yet | Nothing is issued until the owner enters it |
| Sudden bill spike | Keep the bill photo and per-account bill history so it can be explained |
| Boarders pay the internet themselves (SC-31) | Billed by Group: no line on the owner's bill; members record it in D5 |

#### C4. Billing cycles and due dates (S5)

**Purpose:** generate the right bill on the right day and never change it afterwards. Simplified: anniversary due dates only.

- Rent clock: each tenancy's own anchor day from its move-in date, paid in advance. Each bill carries rent for the period starting (in advance) and utilities for the period just finished (in arrears), both fixed inclusions and actual bills. Period 1 rent was paid at move-in, so it is never on a bill.
- Utility clock: an actual bill has its own coverage dates and is due N days after issue (default 7). It goes to the leader (section 2).
- Anchor days 29–31 fall on the last day of shorter months.
- Issued bills are immutable. Corrections are adjustment lines; credit rolls forward or is refunded on request. Issuing a bill locks the prices it used and takes its number inside one transaction.
- The daily job finds what is due as of the day, so reruns cannot duplicate bills. Removed from the first release: common-date and hybrid modes, partial-period proration.

| Scenario | Solution |
| --- | --- |
| Rent follows move-in date (SC-19) | Move-in Sep 25 (day 1 is Sep 26): periods Sep 26–Oct 25 (due Sep 25), Oct 26–Nov 25 (due Oct 25), and so on; no proration on the first period |
| Utility bill arrives on the provider's dates (SC-20) | Own coverage dates; a bill covering Sep 10–Oct 9 entered Oct 12 is due Oct 19 with N = 7 |
| Wrong utility total entered (SC-14) | Owner enters the right total; adjustment lines credit each share (₱1,800 → ₱1,080 on two shares gives ₱360 credit each) |
| Anchor day is the 31st | Due on the last day of the month in shorter months; next period starts the next day |
| Tenancy has a notice end date | No period starting after the end date is generated |
| A boarder disputes a bill | Owner adjusts the charge with an adjustment line; no separate dispute workflow |

#### C5. Split engine (S6)

**Purpose:** divide any amount among people exactly to the centavo. Built as the `Split` module. Its main user is now D5, where leaders split shared bills and members split expenses.

- One function: share = total × weight ÷ sum of weights. Methods: equal, occupant-days, weights, custom amounts. The leader or a moderator chooses.
- Modes: by weight (default), fixed amounts, or fixed plus remainder. Any gap between typed amounts and the total shows as *unallocated* and must be absorbed or fixed before saving. The mode is saved with the split.
- Work in centavos: round each share down and give the leftover centavos to the largest fractional remainders.

| Scenario | Solution |
| --- | --- |
| ₱1,000 split three ways (SC-13) | ₱333.34, ₱333.33, ₱333.33; total is exactly ₱1,000.00 |
| Newcomer arrives mid-period on a ₱1,080 bill, 13 of 30 days (SC-06) | Occupant-days: ₱753.49 and ₱326.51. Equal: ₱540 each. Newcomer pays from the next bill: ₱1,080 and ₱0 |
| Someone types amounts that do not add up (SC-12) | Show the unallocated gap; they absorb it or edit before saving |
| ₱600 bill, one flat ₱100 | Fixed plus remainder: the other two pay ₱250 each |
| Weights 1, 1 and 0.5 on ₱600 | ₱240, ₱240, ₱120 |
| Nobody counted for the period | The leader absorbs it and is warned before saving (proposed) |

#### C6. Payments, receipts and disputes (S7)

**Purpose:** record money received by the owner and apply it correctly. Simplified.

- Allocation is oldest charge first; when charges share a due date, rent first, then utilities, then other charges. Partial payments are allowed.
- Methods: cash (recorded by the owner or a caretaker, confirmed at once) and proof upload (reference number and screenshot, status Pending until confirmed; duplicate references are flagged). Online payment through GCash or Maya is not in Phase 1.
- Each bedspacer pays for their own bed. In a room rented as a whole, and for shared utility bills, the leader pays the owner the full amount. Members repay the leader in D5; the owner never records those payments.
- Every confirmed payment gets a numbered receipt. Overpayment is held as credit and applied to the next charges. No QR code in the first release.
- Disputes: no separate workflow. The boarder reports the problem on the charge; the owner confirms payment, adjusts the charge with an adjustment line, or rejects it with a reason. The owner may mark a charge *under review* to silence reminders for it.

| Scenario | Solution |
| --- | --- |
| Partial payment (SC-15) | ₱2,000 against Sept electricity ₱500, Oct rent ₱1,800 and Oct electricity ₱500 pays the first, ₱1,500 of rent and leaves ₱800 unpaid |
| Leader pays a shared bill in full | One payment and one receipt to the leader; D5 shows each member's share as owed to the leader |
| Screenshot looks fake or reused | Stays Pending; the duplicate reference check flags it; a caretaker or owner decides |
| Payment proof rejected | Boarder is notified with the reason and the charge stays unpaid |
| "I already paid" or "this bill is wrong" (SC-24, proposed) | Boarder reports it with proof; the owner resolves it as above; all steps are logged |
| Pays early or several periods ahead (SC-34) | Held as credit; ₱4,000 paid on Oct 20 for rent due Oct 25 is applied on Oct 25 and no reminder is sent |
| Payment recorded against the wrong boarder | Reverse it with an audited entry and record it again; never delete (proposed) |

#### C7. Arrears and reminders (S8, levels 1–3)

**Purpose:** follow up on late payments without locking anyone out. The system records amounts and notices only. Payment standing is separate from tenancy state. Simplified.

- Days count from the due date and run at 9:00 AM Asia/Manila. The owner may turn a level off or retime it.
- A collection list sorted by days overdue serves caretakers and owners. In a room rented as a whole or for shared bills, the leader is the one overdue.
- Removed from the first release: late fees, payment promises and plans, formal demand, notice-to-vacate automation, case-file export, early-warning flag.

| Level | Default | Timing | Action |
| --- | --- | --- | --- |
| 1. Due soon, then due today | On | 3 days before, and on the due date | In-app and email reminder |
| 2. Overdue (grace) | On | Days 1–3 | Reminders on day 1 and day 3, no penalty |
| 3. Late | On | Day 4 | Marked late and a first notice sent |

Scenarios:

| Scenario | Solution |
| --- | --- |
| Boarder pays a few days late (SC-16) | Grace days and reminders per owner settings; no fee |
| Collector follows up in person (SC-32) | Collection list: record cash with receipt, log a visit remark or promised date, send a reminder, mark late |
| Boarder keeps paying late | Owner uses the notes on the account; no automatic escalation |
| Boarder will not pay and will not leave | Owner sets the end date by notice (C1), the unit flags Overstaying after it, and the deposit is applied in C8 |
| Arrears exceed the deposit (SC-25) | The remainder is charged to the boarder as a one-time charge and stays on record |
| Owner asks about legal steps | The app keeps the ledger, notices and dates; it does not give legal advice. Confirm local rules with the barangay or a lawyer |

#### C8. Deposit and move-out settlement (S9)

**Purpose:** return or apply the deposit fairly. The deposit is held money, not income, so it has its own ledger: received, applied, deducted, refunded. Simplified.

- Deposit amount is set per property, default one month's rent after any discount (rent 4,500, discount 500: deposit 4,000). A later discount change does not alter a recorded deposit. Each bedspacer holds their own deposit. In a room rented as a whole the leader holds the only deposit, and it follows the leader role if the leader changes; the lease tenancy ends when all members move out or a new tenancy is booked.
- Settlement is a short worksheet: final rent (from the deposit or paid in cash), deductions with a reason, and any unpaid amounts. Keeping the deposit needs a recorded reason and is never automatic.
- If deductions and arrears exceed the deposit, the shortfall is charged to the boarder as a one-time charge and stays on record.
- Final utility shares that arrive after move-out are either waived or charged later as a one-time charge, owner's choice.
- Removed from the first release: holdback and true-up, belongings handling, move-in and move-out checklists.

| Scenario | Solution |
| --- | --- |
| Use the deposit for the last period (SC-17) | Rent ₱4,000, deposit ₱4,000, water ₱100, damage ₱300. Apply deposit: boarder pays ₱400, refund ₱0. Keep for refund: boarder pays ₱4,000 rent, refund ₱3,600 |
| Rent rose after the deposit (SC-18, proposed) | Deposit keeps the amount paid; the gap shows at settlement and the owner waives or collects it (₱500 on ₱4,500 rent) |
| Deductions exceed the deposit | Charge the shortfall as a one-time charge |
| Final utility bill arrives after move-out | Waive it, or add it later as a one-time charge |
| Boarder disputes a deduction | The owner explains and may adjust the amount; the decision is audited |
| Whole-room lease ends | The leader's deposit is settled once; members have no separate deposit |

### D. House operations

These four are required in the first release but deliberately plain. Build them as simple lists with statuses and notifications, and resist adding workflow. The defaults are recommendations to confirm before building.

#### D1. Maintenance requests (F8)

**Purpose:** let boarders report problems and let the owner or caretaker track them to completion.

- Boarder submits a category (plumbing, electrical, appliance, furniture, internet, cleaning, pest, other), a description, photos and urgency (Normal or Urgent).
- Statuses: Submitted, In progress, Resolved, Closed, Cancelled. The boarder confirms the fix, or it auto-closes 3 days after Resolved.
- Owner or Manager records the repair cost. A charge to the boarder is a one-time charge with a reason (C3), only when the boarder caused the damage, never automatic.
- A request can set the unit's Not ready flag while repairs are ongoing.

| Scenario | Solution |
| --- | --- |
| Boarder never confirms | Auto-close 3 days after Resolved |
| Urgent problem such as a leak | Urgent flag notifies owner and caretakers at once and tops the dashboard list |
| Who caused the damage is unclear | Default is no charge; the owner may add a one-time charge with a reason, which the boarder can dispute (C6) |
| Boarder moves out with a request still open | Request stays visible to the owner; damage deductions are handled in settlement (C8) |
| Request is invalid | Rejected as Cancelled with a reason the boarder sees |

#### D2. Curfew and late entry (F9)

**Purpose:** optional curfew with a lightweight way to handle exceptions.

- Per property: a curfew time (for example 10:00 PM) or off. Boarders get a reminder 30 minutes before and can mute it.
- Late-entry request in advance with reason and expected arrival; caretaker or owner approves or declines.
- Caretaker logs violations with date and time. Consequence is log only by default; repeated violations show on the owner dashboard; any fee is a manual one-time charge with a reason.

| Scenario | Solution |
| --- | --- |
| Boarder will be late | Files a request beforehand; decision is notified |
| Boarder arrives late without a request | Caretaker logs the violation; no automatic penalty |
| Owner changes the curfew | Rules get a new version (D3) and boarders are notified |
| Same boarder breaks curfew repeatedly | Shown on the owner dashboard; owner decides |
| Property has no curfew | Setting is off and no reminders are sent |

#### D3. House rules (F10)

**Purpose:** make each property's rules visible and agreed to.

- Owner or Manager writes rules per property by category: payments, quiet hours, curfew, visitors, cleanliness, safety, other. Curfew time and visitor limits appear automatically from their settings.
- A public summary shows on the listing. The boarder accepts the rules at move-in; acceptance and date are recorded.
- Each change creates a new version; boarders are notified and asked to acknowledge. Old versions stay viewable.

| Scenario | Solution |
| --- | --- |
| Owner edits a rule | New version; notify and ask for acknowledgement; old version kept |
| Boarder has not acknowledged a new version | Keep reminding; do not block access (proposed) |
| Dispute about what was agreed | Show the version in force on that date and the boarder's acceptance record |
| Move-in without accepting rules | Not allowed; acceptance is part of the move-in step (C1) |

#### D4. Visitor management (F11)

**Purpose:** keep a record of visitors and catch unrecorded extra occupants.

- Boarder registers a visitor in advance (name, date, expected in and out, overnight yes or no). Caretaker logs unregistered walk-ins.
- Overnight stays need approval (default on) with an owner-set limit of nights per boarder per month (default 3). Optional visitor hours show in house rules.
- Over the limit: reminder to the boarder and notice to the owner first; if it continues, the owner may apply an extra-occupant fee, off by default.
- Statuses: Registered, Approved, Declined, Checked in, Checked out. Store the visitor's name only (contact optional) and delete records after 90 days.

| Scenario | Solution |
| --- | --- |
| Visitor arrives unregistered | Caretaker logs a walk-in |
| Overnight request | Owner or caretaker approves or declines; boarder is notified |
| Boarder exceeds 3 nights in a month | Reminder and owner notice; fee only if the owner enabled it |
| Visitor never leaves | Treated as an extra occupant; owner may add a one-time charge with a reason (C3) |
| Retention period passes | Daily job deletes visitor records after 90 days |

#### D5. Shared contributions and group bills (S10, now required)

**Purpose:** the one place where a group records and settles what it owes. It receives the owner's shared bills automatically and also holds the group's own expenses. No money is held or moved by the app.

- A group is created automatically for each room, whether it is rented by bedspace or as a whole. Members come from the occupant list (C2); an account is optional.
- Roles: the leader is the admin, automatically. Moderators (set by the owner, or chosen by the group by majority) can add and edit expenses and confirm repayments. Members can add expenses and see their own balance.
- Owner bills (shared utilities, or everything in a room rented as a whole) arrive as locked lines. Nobody can change the amount. The leader or a moderator chooses the split method for each line, or saves a default for the group: equal, custom amounts, weights or occupant-days (C5).
- Any member can add an expense they paid, pick the participants (default everyone) and attach proof; the same split methods apply.
- Repayment is two steps: the member marks paid (cash or online, with a reference or proof), and the leader or payer confirms. The leader pays the owner first, so unpaid member shares show as owed to the leader and not as unpaid to the owner.
- Owners and caretakers see the occupant list and the owner's own charges posted to the group. They cannot see members' repayments or the group's own expenses *(confirmed)*.
- Kept simple on purpose: repayments are settled one by one, with no fewest-transfers netting. There are no flags, comments or timed disputes; if a share or expense is wrong, the leader or a moderator edits it manually. Recurring expense templates are postponed.

| Scenario | Solution |
| --- | --- |
| Owner posts the monthly shared electricity bill | It appears in the group as a locked line; the leader picks the split; members see their shares |
| Member says an expense is wrong | The leader or a moderator edits it manually. After anyone has repaid, changes are adjustment entries |
| Member pays the leader in cash or online | Member marks paid with an optional reference; the leader confirms |
| Member does not repay | Recorded as owed to the leader. The app does not enforce it and the owner is not affected |
| Member moves out with a balance | Open balances are shown to them and the group before the end date; records stay afterwards |
| Leader changes | Unpaid shared bills move to the new leader; the old leader's own share stays on record; the admin role moves with the leader |
| Someone joins mid-period | Occupant-days or equal split from the group's saved default |
| Boarders handle internet themselves (SC-31) | One member adds it as an expense; no line on the owner's bill |

## 5. Developer guide

These conventions apply to every subsystem; the full list is in `dormlink/docs/CONVENTIONS.md`.

### Conventions

| Area | Rule |
| --- | --- |
| Database | snake\_case plural tables; `<singular>_id` foreign keys; money as `bigint` `_centavos`; dates end `_on`, timestamps `_at`; booleans start `is_` or `has_`; status is a string holding a backed-enum value; soft deletes on anything with money |
| Time | App and database use Asia/Manila. Use `ManilaDate`: `manilaDate()` for calendar dates, `manila()` for moments, `clockTime()` for clock times. End dates last until 23:59:59 |
| Money | `Money::toCentavos()` for input, `format()` for display; splits only through the Split module |
| Enums | PHP string-backed enums in `app/Enums`; after any change run `php artisan dormlink:export-enums`; never edit `dormlink-app/src/types/enums.ts` |
| API | `/api/v1`, kebab-case plural paths, `{ data, meta?, message? }` success and `{ message, errors? }` errors, Sanctum bearer auth, Scribe docblocks on every endpoint |
| Layout | Controllers in `app/Http/Controllers/Api/V1`; validation in Form Requests; authorization in Policies; reusable capabilities in `app/Services/<Module>` |
| Tests | Pest. Money and date logic must use the Billing guide's worked examples as expected results. Run `php artisan test` and `vendor/bin/pint` before handing over a phase |

### Checklist for a new feature

1. Find its subsystem above; if it fits a Simple subsystem, keep it simple.
2. Migration, model and factory following the naming rules; add an enum and export it if it has a status.
3. Form Request for validation and a Policy that scopes to the owner's own properties.
4. Use a shared module for files, messages, audit, numbers and splits instead of writing your own.
5. Wrap any write that touches money in `DB::transaction`.
6. Add Scribe docblocks, tests (including a cross-owner access test), and a DECISIONS.md entry for anything that differs from this guide.

### Suggested build order

Planned for 1–2 developers over about two months. Estimates, to confirm in the tenancy-phase plan before any code is written.

| Weeks | Developer A: money loop | Developer B: groups and house operations |
| --- | --- | --- |
| 1–2 | Done (Phase 7): C1 move-in, deposit and first rent; C3 discount | Done (Phase 7): rooms and floors (B1), booking changes (B3), leaders and occupants (C2) |
| 3–4 | C4 anniversary billing; split methods (C5) | D5 groups, locked owner lines, split choice |
| 5–6 | C6 payments and receipts; C7 reminders and overdue list | D5 repayments and balances; D1 maintenance; D3 house rules |
| 7 | C8 deposit and settlement; A9 statements and notices | D2 curfew; D4 visitors |
| 8 | A10 reports | A10 dashboards; integration, server setup, bug fixing |

A single developer would need roughly 14 to 16 weeks for this scope (estimate). Billing (C4) and payments (C6) need worked-example tests and are where schedules usually slip, so start them by week 3. If time runs short, cut in this order: A9 statements and notices, the report list in A10, D2, D4.

### Deferred and open items

| Item | Notes |
| --- | --- |
| Online payments (GCash, Maya) | Not in Phase 1; proof upload covers transfers |
| Push notifications | After the Android build |
| Late fees, payment promises and plans, formal demand, notice-to-vacate automation, case-file export | Removed from the first release (C7) |
| Common-date and hybrid due dates, proration, opt-in charges, exempt and weight overrides | Removed (C3, C4) |
| Holdback and true-up, belongings handling, move-in and move-out checklists | Removed (C8) |
| QR codes on receipts, timed dispute workflow | Removed (C6) |
| D5: recurring templates | Postponed. Fewest-transfers settle-up, and flags or timed disputes, are not planned |
| SOS alert button, payer contact, co-boarder announcements, building-level utility accounts | Later features |
| Server setup | Cron, queue worker, production URLs, map tiles, PHP upload limits |
| Privacy notice | Current text is a draft |
| Account deletion or deactivation by the user | Not built |
| UI tests | The owner tests the UI by hand |
| Spec guides | Not yet updated to match DECISIONS.md; this guide is the merged view |

### Sources

- [BoardMate System Features Guide](https://claude.ai/artifact/ChMZyTLBfpKRpzMPXCkAMR)
- [BoardMate Billing and Tenancy Design: Revised Subsystem Guide](https://claude.ai/artifact/VD4yqeHsbBCYkoqmkMUUuv)
- `dormlink/docs/DECISIONS.md` and `dormlink/docs/CONVENTIONS.md` in the repository
