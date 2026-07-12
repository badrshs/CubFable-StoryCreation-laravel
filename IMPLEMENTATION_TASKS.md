# CubFable Delegation Backlog

This document turns the selected product ideas into implementation-ready tasks for a future AI or developer. It intentionally excludes any pre-payment AI story/image preview because unpaid generation can be abused and can create avoidable AI provider costs.

## Guiding Constraints

- Do not add unpaid AI generation before checkout.
- Keep generation, regeneration, retry, and rescue flows bounded by strict limits to control provider spend.
- Preserve the current core funnel: choose template -> personalize -> checkout -> generate -> read/download.
- Prefer features that increase conversion, repeat purchase, sharing, gifting, operational reliability, and paid-user satisfaction.
- Any customer-triggered AI work must be authenticated, owner-scoped, rate-limited, and preferably tied to a paid book.
- Add tests for every feature when implemented.

## Priority Legend

- P0: Critical foundation / cost control / reliability.
- P1: High business impact and should be implemented soon.
- P2: Useful improvement after the main revenue features.
- P3: Backlog / future expansion.

---

## Task 1: Gift a Storybook Flow

**Priority:** P1  
**Category:** Revenue / conversion / gifting  
**Goal:** Allow buyers to purchase a storybook as a gift and deliver it to a recipient after generation completes.

### Why This Matters

Personalized children's books are naturally giftable. Grandparents, relatives, friends, and parents buying for birthdays or milestones are likely buyers. A gift flow can increase conversion and average order value without changing the core generation pipeline.

### Scope

Add an optional gift mode during checkout or late wizard flow.

### User Story

As a buyer, I want to mark a book as a gift, include a personal message, and send it to someone after it is generated, so the recipient gets a polished personalized storybook experience.

### Functional Requirements

- Add a gift option before payment or on checkout.
- Capture gift metadata:
  - recipient name
  - recipient email
  - gift message
  - optional delivery date/time
  - sender display name
- Store gift metadata server-side.
- After the book reaches complete status, send recipient an email with access instructions.
- Include a buyer-facing confirmation screen/message after checkout.
- If generation fails, do not email the recipient; email the buyer instead.
- Allow buyer to resend the gift email from the book page after completion.
- Add admin visibility for gift state.

### Suggested Data Model

Create a `book_gifts` table or add nullable columns to `books`. Prefer a separate table if the gift flow may expand.

Suggested fields:

- `id`
- `book_id`
- `sender_user_id`
- `recipient_name`
- `recipient_email`
- `sender_name`
- `message`
- `deliver_at`
- `delivered_at`
- `last_delivery_error`
- `created_at`
- `updated_at`

### Suggested Routes

- `POST /books/{id}/gift` to save gift options for draft books.
- `POST /books/{id}/gift/resend` for completed books.
- Optional signed public recipient route for viewing the book.

### Acceptance Criteria

- Buyer can mark a draft book as a gift before or during checkout.
- Gift data is validated and saved only for the owner.
- Recipient email is not sent until the book is complete.
- Recipient email is never sent for failed books.
- Buyer can resend gift delivery after completion.
- Gift email contains recipient name, sender name, message, and link.
- Feature has feature tests for save, validation, completion delivery, failure non-delivery, and resend.

### Cost Controls

- Gift flow must not trigger AI generation before payment.
- Resending gift emails must be rate-limited.
- Recipient should not be able to trigger paid regeneration actions.

### Notes for Delegated AI

- Reuse existing owner-scoped book patterns.
- Keep gift state independent of Stripe state.
- Use Laravel notifications/mailables for delivery.
- Do not expose private edit/regenerate/download controls to recipients unless explicitly allowed later.

---

## Task 3: “Make Another Book With This Child” Repeat-Purchase CTA

**Priority:** P1  
**Category:** Repeat purchase / retention  
**Goal:** Make it easy for users to reuse a saved child/cast in a new story.

### Why This Matters

The hardest part of the funnel is entering child/cast details and uploading photos. Once a user has created a character, the next purchase should be much faster.

### Scope

Add CTAs on completed books, gallery cards, and character library entries that start a new book using existing character data.

### User Story

As a returning customer, I want to create another story with the same child or family cast so I do not need to re-enter everything.

### Functional Requirements

- Add CTA on reader page for completed books:
  - “Make another adventure with [child name]”
  - “Use this cast again”
- Add CTA on character cards:
  - “Create story with this character”
- Let the user pick a new template after choosing the character/cast.
- Preload wizard with selected hero and optional supporting characters.
- Preserve current no-generation-before-payment behavior.

### Suggested Implementation

Possible approaches:

1. Query params:
   - `/templates?character=123`
   - `/templates?bookCast=456`
   - then `/create/{template}?character=123` or `/create/{template}?bookCast=456`
2. Session-backed draft context:
   - Store reuse intent in session until template is selected.
3. Dedicated controller action:
   - `GET /books/{id}/reuse`
   - redirects to templates with reuse context.

### Acceptance Criteria

- User can create a new book from a saved character.
- User can create a new book from a previous book's cast.
- Wizard pre-fills hero/cast names, roles, age groups, descriptions, and existing photo URLs.
- User can still edit all pre-filled data before checkout.
- Foreign character/book IDs cannot be reused by another user.
- Feature tests cover owner scoping and prefill behavior.

### Cost Controls

- This feature must only prefill data; it must not call AI providers.

---

## Task 4: Series and Sequel Support

**Priority:** P1  
**Category:** Repeat purchase / catalog strategy  
**Goal:** Organize templates into series and encourage users to buy sequential adventures.

### Why This Matters

A series turns a one-time personalized book into an ongoing product. It also gives users a clear next purchase path.

### Scope

Add series metadata to templates and sequel recommendations after completion.

### Functional Requirements

- Add optional series fields to templates:
  - `series_key`
  - `series_title`
  - `series_position`
  - optional `series_description`
- Show series grouping on template browsing.
- On completed reader page, recommend the next template in the same series.
- On gallery, show “Continue series” for books with a next available template.
- Admin template form should allow editing series fields.

### Suggested Series Examples

- Bedtime Brave
- Big Feelings
- First Day Adventures
- Sibling Stories
- Magical Holidays
- Little Explorer

### Acceptance Criteria

- Admin can assign templates to a series and order them.
- Template page can filter or group by series.
- Completed book can recommend the next book in the same series.
- Recommendation respects user-owned book history where practical.
- Tests cover template series data and next-template recommendation logic.

### Cost Controls

- Series recommendations are metadata only and must not trigger AI calls.

---

## Task 5: Optional Parent Editing Checkpoint Before Final Images

**Priority:** P2 / Low priority  
**Category:** Quality / premium workflow  
**Goal:** Allow paid users to review and approve story text before expensive final images are generated.

### Important Constraint

This must happen only after payment. Do not generate story text or images before payment.

### Why This Matters

Parents may want to correct names, family details, or tone before image generation. This can improve satisfaction, but it adds complexity and is not urgent.

### Scope

Add an optional generation mode where text is generated first, then illustrations are generated after approval.

### Functional Requirements

- Add an optional setting at checkout or wizard: “Review story before illustrations.”
- After payment, generate story text and page records first.
- Set book status to something like `awaiting_approval`.
- Let owner edit page text.
- Add “Approve and generate illustrations” button.
- After approval, start image generation.
- If user does not choose this mode, keep current automatic generation.

### Suggested State Changes

Add or reuse statuses carefully:

- `draft`
- `pending`
- `generating_text`
- `awaiting_approval`
- `generating_images`
- `complete`
- `failed`

If adding statuses is too invasive, use existing book status plus a separate `generation_phase` field.

### Acceptance Criteria

- Paid user can choose review mode.
- Text generation happens after payment.
- Images do not start until owner approves.
- Owner can edit page text before approval.
- Approval action is idempotent.
- Tests cover payment-to-text, approval-to-image, and unauthorized approval.

### Cost Controls

- This mode must not run before payment.
- Approval button must be disabled/ignored after images begin.
- Any text regeneration should be separately limited or omitted for v1.

---

## Task 6: Shareable Read-Only Family Links

**Priority:** P1  
**Category:** Viral sharing / family access  
**Goal:** Let owners share completed books with family through safe read-only links.

### Why This Matters

Every shared book is a marketing opportunity. Grandparents and relatives who view a book are likely future buyers.

### Scope

Add signed or tokenized public reader links for completed books.

### Functional Requirements

- Owner can create a share link for a completed book.
- Share link opens a read-only reader page.
- Shared viewer cannot edit text, regenerate pages, restyle, delete, or access admin/owner actions.
- Owner can disable/regenerate share link.
- Optional expiry date.
- Optional basic visitor name capture later, not needed for v1.

### Suggested Data Model

Option A: add fields to `books`:

- `share_token`
- `share_enabled_at`
- `share_expires_at`

Option B: create `book_shares` table for multiple links.

For v1, a single share token per book is enough.

### Suggested Routes

- `POST /books/{id}/share` create/enable share link.
- `DELETE /books/{id}/share` disable share link.
- `GET /shared/books/{token}` public read-only page.

### Acceptance Criteria

- Share links work for complete books only.
- Draft, pending, generating, and failed books cannot be publicly shared.
- Read-only page does not show owner-only controls.
- Disabled/expired links return 404.
- Tests cover owner creation, public access, expired/disabled access, and no controls exposed.

### Cost Controls

- Public viewers cannot trigger any AI work.
- Public route must never call regenerate/restyle/rescue endpoints.

---

## Task 8: Occasion-Based Template Discovery

**Priority:** P1  
**Category:** Conversion / template browsing  
**Goal:** Improve template discovery by matching how parents buy: occasions, milestones, and problems.

### Why This Matters

Parents usually think “first day of school,” “bedtime,” or “confidence,” not abstract themes. Better filtering can increase template-to-wizard conversion.

### Scope

Add occasion metadata and filters to templates.

### Functional Requirements

- Add occasion tags to templates.
- Show occasion filter on template index.
- Support multiple tags per template.
- Admin can edit tags.
- Search should include occasion tags.

### Suggested Occasion Tags

- Birthday
- Bedtime
- First day of school
- New sibling
- Moving house
- Potty training
- Bravery
- Confidence
- Big emotions
- Dentist visit
- Doctor visit
- Sharing
- Friendship
- Holiday
- Christmas
- Eid
- Hanukkah
- Ramadan
- Vacation
- Grandparents

### Suggested Data Model

For v1, add `occasions` JSON column to `templates`. If tags become more complex later, normalize into a separate table.

### Acceptance Criteria

- Templates can have zero or more occasions.
- Users can filter templates by occasion.
- Search includes title, theme, subjects, and occasions.
- Admin can create/update occasion tags.
- Tests cover template serialization and filtering behavior.

### Cost Controls

- Metadata-only feature; no AI provider calls.

---

## Task 9: Guided “Emotional Goal” Wizard Inputs

**Priority:** P1  
**Category:** Conversion / UX  
**Goal:** Make story setup easier by asking parents what they want the child to feel or learn.

### Why This Matters

Parents understand the desired emotional outcome better than they understand plot/theme choices. This can reduce wizard friction.

### Scope

Add guided inputs that map to existing story fields such as life lesson, subject, and template recommendations.

### User-Facing Questions

Examples:

- “What should your child feel after reading?”
  - brave
  - loved
  - calm
  - proud
  - curious
- “What is your child working through?”
  - bedtime
  - sharing
  - school
  - confidence
  - big emotions
- “What kind of adventure would they enjoy?”
  - magical
  - animals
  - space
  - ocean
  - family

### Functional Requirements

- Add guided question UI to template selection or wizard.
- Map answers to existing `lifeLesson`, `subject`, and maybe template filters.
- Do not remove advanced/custom choices.
- Ensure translated labels are added for supported locales when implemented.

### Suggested Implementation

- Keep a client-side mapping file for v1.
- Example:
  - `brave` -> life lesson “Courage” and occasion “Bravery”
  - `calm` -> bedtime templates and calming subjects
  - `sharing` -> friendship/sharing templates
- Later, move mappings into admin-managed settings if needed.

### Acceptance Criteria

- User can answer guided questions and see recommended templates or prefilled wizard fields.
- User can override any recommended field.
- No AI calls are made by the recommendation step.
- Tests cover mapping utility if implemented server-side; otherwise cover UI behavior with existing frontend test setup if available.

### Cost Controls

- No AI-based recommendations for v1.
- Use deterministic mappings only.

---

## Task 10: Sibling and Family Bundle Flow

**Priority:** P1  
**Category:** Revenue / bundles  
**Goal:** Let families create similar books for multiple children with less repeated work.

### Why This Matters

Families with multiple children are a natural bundle opportunity. Personalized sibling bundles can increase average order value.

### Scope

Add a flow to duplicate a configured draft for another main character or create multiple related drafts before checkout.

### Functional Requirements

- Let buyer choose “Create one for each child.”
- Support selecting multiple saved child characters as heroes.
- Create separate draft books, one per hero.
- Reuse same template, theme, subject, life lesson, art style, font, and language.
- Supporting characters can remain attached to each book.
- Checkout should clearly show multiple books and total amount if bundle checkout is supported.

### Suggested Implementation Options

Option A: simple v1

- Add “duplicate draft for sibling” after creating first draft.
- Each book is paid individually.

Option B: stronger revenue v2

- Add cart/bundle checkout for multiple draft books.
- Apply bundle discounts server-side.

### Acceptance Criteria

- User can create a second draft using the same setup but a different main character.
- No duplicate character records are created unnecessarily when saved characters are used.
- Foreign character IDs cannot be attached.
- Tests cover draft duplication and owner scoping.

### Cost Controls

- Draft duplication must not trigger AI generation.
- Generation starts only after each book is paid, or after bundle payment is confirmed.

---

## Task 11: Character Passport Pages

**Priority:** P2  
**Category:** Personalization depth / retention  
**Goal:** Make saved characters richer and more reusable across future books.

### Why This Matters

A richer character library creates emotional attachment and improves repeat purchases. It also gives the AI more consistent details after payment.

### Scope

Expand character profiles with optional structured fields.

### Suggested Fields

- favorite color
- favorite animal
- favorite activity
- personality traits
- things they are learning
- fears/challenges
- pronouns
- family relationship labels
- preferred nickname
- appearance notes separate from AI-derived appearance

### Functional Requirements

- Add character detail/edit page or expanded library dialog.
- Store structured profile fields.
- Include these fields in future book generation prompts after payment.
- Preserve current photo and description behavior.

### Suggested Data Model

For flexibility, add a JSON column like `profile` to `characters`, or add explicit nullable columns if the fields are stable.

For v1, `profile` JSON is probably fastest.

### Acceptance Criteria

- User can add/edit optional character passport fields.
- Existing characters continue working without passport data.
- Future book creation can prefill from passport data.
- Tests cover storing and serializing profile fields.

### Cost Controls

- Editing passport data must not trigger AI calls.
- AI uses passport details only after payment as part of normal generation.

---

## Task 13: User-Facing Book Rescue With Strict Limits

**Priority:** P0  
**Category:** Reliability / cost control  
**Goal:** Let paid users recover from failed generation in a controlled, limited way without creating unlimited AI spend.

### Why This Matters

AI generation fails sometimes. Users need a clear recovery path, but retries can become expensive if unlimited.

### Scope

Expose limited retry/rescue actions to users for paid books only.

### Strict Cost Controls

Implement hard limits before exposing this feature:

- Only paid books can use rescue.
- No rescue for draft books.
- Limit total user-triggered rescue attempts per book, e.g. 2.
- Limit per-page regeneration attempts, e.g. 2 per page.
- Limit cover regeneration attempts, e.g. 2 per book.
- Limit rescue actions per user per day, e.g. 5.
- Add cooldown between retries, e.g. 5-15 minutes.
- Store attempt counts in database, not only cache.
- Admin can override/reset limits manually if needed.
- If provider returns content rejection, retry with safer prompt once, then stop and show support message.

### Functional Requirements

- On failed book page, show friendly failure state.
- Provide “Try to repair” if attempts remain.
- Show attempts remaining.
- If no attempts remain, show “Contact support” or “We are reviewing this.”
- Log every user-triggered rescue attempt.
- Admin dashboard should show books that exhausted rescue attempts.

### Suggested Data Model

Create `book_repair_attempts` table or add counters to existing generation metadata.

Suggested fields:

- `id`
- `book_id`
- `user_id`
- `page_id` nullable
- `type` enum/string: `book`, `cover`, `page`, `pdf`
- `status`: `queued`, `succeeded`, `failed`, `blocked_limit`
- `provider`
- `error_code`
- `error_message`
- `created_at`
- `updated_at`

Also consider fields on `books`:

- `user_repair_attempts_count`
- `last_user_repair_at`

### Suggested Routes

- `POST /books/{id}/repair`
- `POST /books/{id}/pages/{pageId}/repair`
- `POST /books/{id}/cover/repair`

### Acceptance Criteria

- Failed paid book shows rescue action when attempts remain.
- Draft/unpaid books cannot be rescued.
- Complete books cannot use broad rescue unless specific failed page/cover exists.
- Attempt counters are enforced server-side.
- Cooldowns are enforced server-side.
- Attempts are logged and visible to admin.
- Tests cover paid-only, owner-only, limit reached, cooldown, and success queueing.

### Notes for Delegated AI

- Do not implement unlimited retry buttons.
- Do not let public share links trigger rescue.
- Prefer a single repair orchestration service that checks limits before dispatching jobs.

---

## Task 15: Completion and Failure Email Notifications

**Priority:** P1  
**Category:** Retention / reliability  
**Goal:** Email users when a book completes or fails. Email only for now; no SMS.

### Why This Matters

Generation can take time. Users may leave after checkout. Email brings them back when the book is ready and reduces support confusion when generation fails.

### Scope

Add transactional emails for generation completion and failure.

### Functional Requirements

- Send email when a paid book becomes complete.
- Send email when a paid book becomes failed.
- Include book title/child name and link back to reader/gallery.
- Avoid duplicate emails for the same status transition.
- If gift flow exists, completion email goes to buyer and gift email goes to recipient based on gift rules.
- Do not send completion email for draft/unpaid books.

### Suggested Data Model

Add notification tracking fields or use a table:

- `book_completed_email_sent_at`
- `book_failed_email_sent_at`

A separate notification log table is more flexible, but columns are faster for v1.

### Acceptance Criteria

- Completion email is sent exactly once per completed generation.
- Failure email is sent exactly once per failed generation.
- Emails are queued.
- Tests use mail fake to verify behavior.
- No SMS code is added.

### Cost Controls

- Emails do not trigger AI calls.
- Email retry should use normal queue retry limits.

---

## Task 16: Print-Ready PDF Upsell

**Priority:** P1/P2  
**Category:** Revenue / order value  
**Goal:** Offer a higher-value print-ready PDF or premium download option.

### Why This Matters

Personalized books are keepsakes. A print-ready edition can increase average order value and make the product feel more premium.

### Scope

Add optional premium PDF generation/download behavior.

### Functional Requirements

- Define product tiers:
  - digital reader only
  - standard PDF
  - print-ready PDF
- Add server-side pricing for PDF upgrade.
- Add UI upsell after book completion or during checkout.
- Ensure only eligible users can download premium PDF.
- Support page size/bleed settings if print-ready output requires it.

### Suggested v1

Start simple:

- Existing paid book includes normal PDF.
- Add paid “print-ready PDF” upgrade later.
- Store entitlement on order or book.

Suggested fields:

- `print_ready_pdf_purchased_at`
- `print_ready_pdf_path`
- `print_ready_pdf_status`

### Acceptance Criteria

- User can see print-ready PDF upsell for completed books.
- User cannot download print-ready PDF without entitlement.
- Payment confirmation grants entitlement.
- PDF generation is queued if expensive.
- Tests cover entitlement and download authorization.

### Cost Controls

- PDF generation should use existing images and text, not regenerate AI images.
- If PDF creation is expensive, queue it and limit retries.

---

## Task 20: Memory Book Mode

**Priority:** P3 / Future backlog  
**Category:** Product expansion  
**Goal:** Let users create sentimental books from real memories and uploaded photos.

### Why This Matters

Memory books may have stronger emotional value than fictional stories. Examples: “Grandma and Me,” “My First Year,” “Our Vacation,” or “Starting Kindergarten.”

### Current Status

Backlog only. Do not implement now unless explicitly prioritized later.

### Future Scope

- User chooses memory book mode.
- User uploads multiple memories/photos.
- User enters captions, dates, people, and emotional tone.
- After payment, app creates a storybook from those memories.

### Suggested Memory Types

- My first year
- Grandma and me
- Grandpa and me
- Our family vacation
- Starting kindergarten
- New baby sibling
- Birthday yearbook
- Pet memory book

### Major Considerations

- More uploads means more storage and moderation needs.
- Real photos may raise privacy expectations.
- Prompting must avoid inventing sensitive facts.
- This may need a different wizard from the fictional story flow.

### Acceptance Criteria for Future Implementation

- Memory mode is clearly separate from fictional template mode.
- No AI generation before payment.
- Uploaded photos are stored securely and owner-scoped.
- User can edit memory entries before checkout.
- Generated story respects supplied facts and does not invent sensitive life events.

### Cost Controls

- Limit number and size of photo uploads.
- Do not analyze all photos before payment unless there is a paid deposit or explicit paid step.
- Consider using captions/manual inputs first, image analysis later.

---

## Additional Task: Cloudflare Turnstile on Registration and Login

**Priority:** P0  
**Category:** Security / abuse prevention / cost control  
**Goal:** Add Cloudflare Turnstile to registration and login forms to reduce bot abuse and protect paid/AI flows.

### Why This Matters

Bot signups and login attacks can create operational risk. If attackers reach flows that can trigger emails, storage, or paid-generation edge cases, costs can increase.

### Scope

Add Turnstile verification to auth forms.

### Functional Requirements

- Add Turnstile widget to registration form.
- Add Turnstile widget to login form.
- Verify token server-side before accepting registration/login.
- Add config/env values:
  - `TURNSTILE_SITE_KEY`
  - `TURNSTILE_SECRET_KEY`
  - optional `TURNSTILE_ENABLED`
- Fail closed in production if enabled and verification fails.
- Make local/testing bypass explicit and safe.

### Suggested Implementation Notes

- Add a reusable validation rule or service for Turnstile verification.
- Do not wrap imports in try/catch.
- Add feature tests using mocked HTTP responses.
- Keep auth error messages generic.

### Acceptance Criteria

- Registration requires a valid Turnstile token when enabled.
- Login requires a valid Turnstile token when enabled.
- Invalid/missing token fails validation.
- Tests cover enabled valid, enabled invalid, disabled, and missing token paths.
- No Turnstile secret is exposed to frontend.

---

## Additional Task: Clearer Generation Progress

**Priority:** P1  
**Category:** UX / support reduction  
**Goal:** Show detailed generation stages instead of only broad pending/generating states.

### Why This Matters

Users are more patient when they understand what is happening. Better progress reduces refreshes, duplicate support messages, and payment anxiety.

### Suggested Stages

- Payment confirmed
- Writing story
- Building character profile/reference sheet
- Drawing cover
- Drawing page X of N
- Building PDF
- Final checks
- Complete
- Failed with repair option if eligible

### Functional Requirements

- Track generation phase server-side.
- Expose phase and progress details in book props.
- Show phase in gallery and reader.
- Keep existing polling but display richer state.
- Add timestamps for key phase transitions if useful.

### Suggested Data Model

Add fields to `books`:

- `generation_phase`
- `generation_started_at`
- `generation_completed_at`
- `generation_failed_at`
- `generation_error_summary`

For page-level progress, existing pages can provide completion counts.

### Acceptance Criteria

- Gallery shows accurate progress text and percentage.
- Reader shows current generation phase.
- Progress survives page refresh.
- Failed phase includes helpful message and rescue CTA if available.
- Tests cover phase serialization and transitions where practical.

### Cost Controls

- Progress tracking must not add AI calls.

---

## Additional Task: Admin QA Dashboard

**Priority:** P0/P1  
**Category:** Operations / reliability  
**Goal:** Give admins a clear view of generation quality, failures, exhausted retries, and books needing manual attention.

### Why This Matters

AI generation products need strong operations. A good admin QA dashboard helps catch failed paid books before users complain.

### Scope

Add dashboard cards and filters for generation health.

### Functional Requirements

- Show counts for:
  - paid books pending too long
  - failed books
  - books with failed pages
  - books missing cover
  - books missing PDF if applicable
  - books with exhausted user rescue attempts
  - recent provider errors
- Add filters on admin book list:
  - failed
  - stuck generating
  - missing images
  - needs manual review
- Link each item to admin book detail.
- Add “last error” and “last attempted at” where available.

### Suggested Acceptance Criteria

- Admin dashboard exposes QA counts.
- Admin can filter to books needing attention.
- Stuck threshold is configurable, e.g. generating over 30 minutes.
- Tests cover dashboard access and count calculations.

### Cost Controls

- Dashboard is read-only by default.
- Any admin repair action should still be explicit and logged.

---

## Additional Task: Failed-Generation Notifications

**Priority:** P1  
**Category:** Reliability / communication  
**Goal:** Notify users and admins when paid generation fails.

### Why This Matters

Silent failure creates frustration. A timely email can explain what happened and direct the user to retry or support.

### Scope

This overlaps with Task 15 but adds admin/operator notification behavior.

### Functional Requirements

- User gets email when paid book fails.
- Admin/operator gets notification for failed paid books.
- If rescue attempts remain, user email links to the book with repair option.
- If no attempts remain, user email sets expectation that support will review.
- Avoid duplicate notifications for the same failure state.

### Acceptance Criteria

- User failure email sends once.
- Admin failure notification sends once or groups failures to avoid spam.
- Notification includes book id, user id/email, status, and error summary.
- Tests cover deduplication.

### Cost Controls

- Email notification must not auto-trigger retries unless explicitly configured later.

---

## Additional Task: More Detailed Generation State

**Priority:** P0/P1  
**Category:** Backend reliability / observability  
**Goal:** Store enough generation state to support progress UI, admin QA, rescue limits, and failure emails.

### Why This Matters

Several tasks depend on knowing what stage a book is in and why it failed. A clean state model prevents fragile UI guesses.

### Suggested State Fields

On `books`:

- `generation_phase`
- `generation_attempts`
- `last_generation_error_code`
- `last_generation_error_message`
- `last_generation_failed_at`
- `last_generation_heartbeat_at`
- `completed_email_sent_at`
- `failed_email_sent_at`

On `pages`:

- `generation_attempts`
- `last_generation_error_code`
- `last_generation_error_message`
- `last_generation_failed_at`

Optional separate event table:

- `generation_events`
  - `book_id`
  - `page_id` nullable
  - `type`
  - `phase`
  - `status`
  - `provider`
  - `message`
  - `metadata`
  - `created_at`

### Functional Requirements

- Generation jobs update phase at each major step.
- Failures store a safe summary for users and detailed context for admins.
- UI consumes server-provided state instead of inferring everything from page counts.
- State supports stuck-job detection.

### Acceptance Criteria

- Book has a clear generation phase during generation.
- Failures are recorded with enough detail for admin diagnosis.
- User-facing errors are safe and non-technical.
- Tests cover state transitions for success and failure paths.

### Cost Controls

- State tracking is metadata-only.
- State updates must not trigger retries automatically unless explicitly requested and limited.

---

## Recommended Implementation Order

1. Turnstile on registration and login. P0 security and abuse prevention.
2. More detailed generation state. Foundation for progress, emails, QA, and rescue.
3. Clearer generation progress UI.
4. Completion and failed-generation emails.
5. Admin QA dashboard.
6. User-facing book rescue with strict limits.
7. Gift a Storybook flow.
8. Make another book with this child.
9. Series and sequel support.
10. Occasion-based template discovery.
11. Guided emotional-goal wizard inputs.
12. Sibling/family bundle flow.
13. Print-ready PDF upsell.
14. Character passport pages.
15. Parent editing checkpoint after payment. Low priority.
16. Memory book mode. Backlog/future.

---

## Ready-to-Create GitHub Project Task Titles

Use these as project items if creating tasks manually:

1. Add Cloudflare Turnstile to registration and login
2. Add detailed generation phase/state tracking
3. Improve customer-facing generation progress UI
4. Send book completion emails
5. Send failed-generation user/admin notifications
6. Build Admin QA dashboard for generation health
7. Add limited user-facing book repair/rescue flow
8. Add gift storybook checkout and delivery flow
9. Add “make another book with this child/cast” flow
10. Add template series and sequel recommendations
11. Add occasion-based template filtering
12. Add guided emotional-goal wizard inputs
13. Add sibling/family bundle draft flow
14. Add print-ready PDF upsell entitlement
15. Add character passport profile fields
16. Add paid parent approval checkpoint before illustrations
17. Backlog: Memory book mode

