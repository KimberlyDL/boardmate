# BoardMate decisions

Decisions made while building that **differ from or add to** the two spec guides (*System Features Guide* and *Billing and Tenancy Design: Revised Subsystem Guide*). The guides have not been updated yet, so where they disagree, this file and the code win. Add an entry whenever a new decision is made.

## Accounts and sign-in

| Decision | Detail |
| --- | --- |
| Email must be verified before logging in | Unverified accounts cannot get a token. |
| Changing the email address | The new address must be confirmed first; the old address is told about the change. |
| Logins expire after 30 idle days | Each use extends the token. `boardmate.token_idle_days`; expired tokens are pruned by a daily job. |
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
| "In use" | A unit that is reserved, occupied, leaving or overstaying. One rule (`RentableUnit::isInUse`, `Property::occupancyBlocks`) blocks deleting the property, switching rental mode, and removing the unit. Each check runs under the same property lock that booking approval takes, so they cannot race. |
| Unit status changes | Only through `UnitStatusService`, with a fixed list of allowed moves. A move that is not allowed, or based on an out-of-date status, is an error. |
| Deleting a property | Refused while any unit is in use. Otherwise it is unpublished and soft-deleted (history stays), and its pending applications are declined ("This place is no longer listed"). |
| Switching rental mode | Refused while any unit is in use. The old units are archived with their price history. |
| Prices | Effective-dated, never overwritten, no back-dating. Saving the price already in force changes nothing. A scheduled future price is replaced, not stacked. A price set today can be corrected the same day. |
| Locked prices | A price a bill used is locked (`price_rules.locked_at`; billing calls `PriceBook::lock`). It is never deleted or replaced; a later price can still end it. |
| Listing photos | Up to 15. Saved as WebP with the longer side at most 1600 px (`large`) and 480 px (`thumb`). EXIF and GPS are removed and the original is not kept. Images over 8200 px on a side are refused. Profile photos are a 256 px WebP square. `boardmate:rebuild-images` converts older uploads. |

## Booking (up to the reservation)

| Decision | Detail |
| --- | --- |
| Boarders apply to a property, not a unit | The owner or a Manager picks the unit when approving. |
| Reservation length | From the planned move-in date (or the approval day if that date has passed) plus the property's `reservation_expiry_days` (default 7). Unclaimed reservations expire daily; the boarder is reminded the day before. |
| One reservation per boarder | Approving one cancels the boarder's other pending applications automatically. A boarder can have up to 5 pending applications. |
| Last free unit taken | The property's other pending applicants are declined ("The place is now fully booked"). |
| Street address | Hidden from a boarder until their reservation is approved. |
| ID file on an application | Private; deleted 30 days after the application closes. |
| Booking stops at the reservation | Move-in, deposits and billing belong to the tenancy phase. |

## Scheduler and data

| Decision | Detail |
| --- | --- |
| Daily jobs | `boardmate:daily` at 09:00 Manila. Each job runs once per day; failed or stale jobs are retried. `--date` catches up past days only; a future date needs `--simulate`, which runs the jobs (with real effects) without marking them done. |
| Time zone | App and database connection are both `Asia/Manila`. In the app, calendar dates (`…_on`, `reserved_until`, `effective_from`) use `manilaDate()`, moments use `manila()`, and clock times (curfew, visitor hours) use `clockTime()`. |
| Activity log | Never cleaned automatically; it is the paper trail. |

## Left out for now (by choice)

- Server setup: cron, queue worker, production URLs, map tiles, online payment provider. When it is done, PHP needs at least `upload_max_filesize = 6M`, `post_max_size = 30M` (an owner application can carry 5 files of 5 MB), the GD extension with WebP, and room to raise `memory_limit` to 512M while a photo is resized. The app uploads listing photos one per request, so the usual 30-second time limit is enough.
- Privacy notice (current text is a draft).
- Account deletion or deactivation by the user.
- Updating the spec guides.
- UI tests (the owner tests the UI by hand).
