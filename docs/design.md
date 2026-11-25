# Dojo Club: design (1.0)

A WordPress plugin for CoderDojo clubs: sessions, anonymous polls, quizzes, RFCs, session projects, a lending library and ninja accounts. Built and tested by CarrickDojo, for use by any dojo. The club name and local rules are settings.

The plugin is the **source of truth**: it stores the data, validates it and enforces the rules. A separate front end (for CarrickDojo, the SvelteKit club site) displays it and calls the plugin's API.

Replaces CarrickDojo Polls 0.4. See [Migration](#migration-from-04).

## Principles

1. **Children first.** Collect as little as possible, delete it as early as possible, and never link a vote to a person.
2. **Authored content in post types, live data in custom tables.** Things mentors write (sessions, polls, proposals, library items) get WordPress editing and revisions. Things that happen during a session (codes, votes, loans) go in lean tables.
3. **The plugin owns the rules.** Validation, timing, scoring, approvals and privacy are enforced here, never only in the front end.
4. **No other plugins needed.** Only WordPress core and this plugin.
5. **Test data lives beside live data, but can never touch it.** See [Test data](#test-data).
6. **Versioned API.** `/dojo/v1/`, so the front end and plugin can change independently.

## Terms

| Term | Meaning |
| --- | --- |
| **Session** | One club meeting on a date, e.g. Tuesday 19:00–20:00. |
| **Session code** | A printed one-night code. Member (`RED-TIGER`), mentor or screen (`RED-TIGER-BLUE-OWL`). |
| **Account** | A WordPress user. Mentors already have one; ninjas aged 13+ can ask for one. |
| **Persona** | A made-up name from approved word lists (`Cosmic-Kernel-Duke`), never typed freely. |
| **Poll** | One question. |
| **Questionnaire** | A group of polls with an intro. Kind: `questionnaire`, `rfc` or `quiz`. |
| **RFC** | Request for comment: voting rounds about a **proposal**. |
| **Quiz** | A questionnaire run live, with correct answers, timings and scores. |
| **Result** | A poll's tally, kept after the individual votes are deleted. |
| **Session project** | A project a ninja submits on the night, for the showcase and code review. |
| **Library** | Items the dojo lends out, with copies, loans and reviews. |

## People and access

### Who signs in, and how

| Who | Signs in with | Notes |
| --- | --- | --- |
| Ninja under 13 | session code | No account. |
| Ninja 13–15 | session code, or account with parental consent | A mentor ticks "consent on file". Consent is collected offline. |
| Ninja 16+ | account (preferred) or session code | |
| Mentor / volunteer | WordPress account, or a session code on a shared laptop | |
| Projector | screen code | Live counts and quizzes only. |

The age bands and the consent age are **settings** (GDPR lets each country choose 13–16; Ireland is 16). Only the **band** is stored, never a date of birth. A mentor moves an account from 13–15 to 16+.

### Dojo roles

Adults with WordPress accounts get one or more **dojo roles**, set as tick boxes on their user profile. Each role grants plugin capabilities:

| Role | Can |
| --- | --- |
| Champion | Everything, including settings, secrets, backup and restore. |
| Safeguarding Officer | Approvals, delete any content, accounts, privacy tools, audit log. |
| Technical Mentor | Settings (not secrets), test data tools, health check. |
| Mentor | Sessions, codes, polls, quizzes, RFCs, library, projects, approvals. |
| Volunteer | Library desk, print codes. |
| Committee | Results, reports, proposals. |

### Ninja accounts

- A WordPress user with the `dojo_ninja` role, which has **no capabilities**: blocked from WP admin, the admin bar and `wp-login.php`. Ninjas sign in only through the front end.
- **No real data:** a username (must not be a real name), a password, a persona. No email.
- Requested on the front end, then **approved by a mentor**, who sets the band (and consent for 13–15). Mentors can also create one directly.
- A mentor resets passwords (there's no email).
- Band and consent are user meta: `dojo_band` (`13-15` / `16+`), `dojo_consent` (yes / no).
- Usernames are never exposed: no author archives, no user listings, no `?author=` redirects for ninjas.
- Deleted after a set time without signing in (setting), or on request, using WordPress's own user deletion and privacy eraser.

### Authentication

- **Accounts:** username and password, checked with WordPress's own `wp_authenticate()`. Passwords are hashed by WordPress.
- **Session codes:** checked against that session's codes.
- Both give a **sign-in token**: random, 32 bytes, stored only as a SHA-256 hash, expiring at the end of the session (codes) or after a set time (accounts).
- **Rate limits:** wrong codes and wrong passwords are counted per session and per username, with a lockout. WordPress doesn't do this itself.
- **Sign out everywhere** for accounts; **sign everyone out** for mentors.

### Front-end secret

The front end's server calls the API with a secret in the `X-Dojo-Secret` header. There are **two secrets**:

- **Live secret:** used by Production. Sees live data only.
- **Test secret:** used by local development and previews. Sees test data only.

Both are stored as SHA-256 hashes in WordPress options (no `wp-config.php` access needed).

## Data

### Post types (authored, with revisions)

| Post type | Fields |
| --- | --- |
| `dojo_session` | date, start, end, status (`scheduled` / `cancelled` / `changed`), message, `test` |
| `dojo_questionnaire` | session (none = always open), kind (`questionnaire` / `rfc` / `quiz`), opens, closes (none = never closes), intro (content), proposal and round (RFC only), status (`pending` / `approved`), `test` |
| `dojo_proposal` | text (content; revisions are its history), status (`draft` / `comment` / `voting` / `accepted` / `rejected` / `review` / `withdrawn`), `test` |
| `dojo_item` | title, description, category (`book` / `kit` / `board` / `tool` / `other`), photo (media library), age guide |

Sessions are **explicit records**. "Generate term" creates them from the weekly rule in settings, skipping breaks; any one can then be cancelled or changed.

Ninja- and mentor-created polls, quizzes and RFCs are `pending` until a mentor approves them. Content created by a mentor is approved straight away.

### Custom tables

All names are prefixed `{wp_prefix}dojo_`.

**polls**

| Column | Notes |
| --- | --- |
| `id` | generated |
| `questionnaire_id`, `position` | |
| `type` | `yes-no`, `choice`, `scale`, `quiz` |
| `text` | |
| `options` | JSON list (choice and quiz) |
| `multiple`, `ordered` | choice only |
| `scale_min`, `scale_max`, `scale_step`, `label_min`, `label_max` | scale only |
| `correct`, `seconds`, `points`, `code`, `code_language` | quiz only |

Polls are **locked once their questionnaire opens**, so every vote matches the question that was asked.

**Data levels** (worked out, not stored): yes-no = binary, choice = categorical, ordered choice = ordinal, scale = numeric.

**Scale values** are stored as answered (e.g. 1–5). The front end can scale them for comparison.

| Table | Columns | Kept |
| --- | --- | --- |
| `codes` | session, code, kind (`member` / `mentor` / `screen`) | until the day after the session |
| `tokens` | token hash, session, code or user, kind, expires | until expiry, or the day after |
| `code_uses` | session, code or user, poll (no ID, no timestamp) | until the poll is finalised, or the day after |
| `votes` | random ID, poll, answer, session date | until finalised, or rolled up |
| `results` | poll, session date (always-open polls), counts JSON, total, finalised | forever |
| `personas` | session, code or user, persona | the day after (codes); kept (accounts) |
| `quiz_runs` | questionnaire, state, offsets | the day after |
| `quiz_answers` | run, code or user, poll, answer, points | the day after |
| `projects` | session, persona (text), title, links JSON (`kind` + URL), status, showcase order, `test` | forever; mentors can delete |
| `item_copies` | item, label, condition (`good` / `worn` / `damaged`), status (`available` / `requested` / `on-loan` / `missing` / `retired`), notes | forever |
| `loans` | copy, persona (text), requested session, loaned session, returned session, condition on return | forever |
| `reviews` | item, loan, persona (text), stars (1–5), comment, session date, status | forever |
| `logins` | username or session, failures, locked until | short-term |
| `audit` | WordPress user, action, object, time | a set time (setting) |

Link kinds: `scratch`, `game`, `web`, `github`, `proposal`, `other`.

## Polls

- **Closing:** at `closes`, or early by a mentor. Finalising tallies the votes into a result and deletes the votes.
- **Always-open polls** (no `closes`): votes are **rolled up into per-session results** the day after each session, then deleted. Trends ("last week", "last month") come from these. A member can vote again in each session. Votes can't be changed.
- **Showing results:** a breakdown appears once a poll (or a period, for trends) has **5 or more votes**. Below that, only the total is shown. This applies while open, when closed, and to trends.
- **One vote per code or account per poll per session.**

## Quizzes

- Questions, correct answers, timings and points are stored in the plugin, and **the plugin does the scoring**, so scores can't be faked.
- Automatic pacing from the start time, with mentor controls: start, pause, resume, extra time, skip, show leaderboard, end.
- Speed bonus capped at about a third of the points.
- Players see their own score; leaderboards show personas only.

## RFCs

- A proposal moves through `draft` → `comment` → `voting` → `accepted` / `rejected` / `review` → (revised) → `voting`…, or `withdrawn`.
- Each vote is a **round**: a questionnaire of kind `rfc` with its own polls, dates and result. Rounds never mix.
- Comments on RFCs are on the backlog; for now RFCs get votes only.

## Session projects

- A ninja submits a title and one or more links on the night. A mentor approves them, then they're shown in the **showcase** on the projector, in order.
- Shown with the persona used that night. Only people signed in to that session (and mentors) can see them.
- Kept forever. Mentors can delete them.
- Full, detailed projects are **not** in the plugin: they're `.md` files on the front end. A mentor can turn a session project into one by hand.

## Library

- A ninja taps **Borrow**; the copy becomes `requested` and appears in the mentor view to approve or decline.
- A mentor marks it **Returned** at any later session, whatever code the ninja has then.
- Loans are kept forever (for "most borrowed" and item history), recorded with the persona as text.
- After a loan, the borrower can leave a **review**: 1–5 stars and a short comment. Reviews are approved by a mentor.

## Free text

Project titles, review comments, ninja-written questions:

- plain text only (no HTML or markdown), with length limits;
- approved by a mentor before anyone else sees it;
- links must be `https`, and are shown as links, never embedded.

A basic word filter is on the backlog.

## Privacy rules

1. **No real names, emails, addresses or dates of birth.** Accounts hold a username, password, persona, band and consent tick.
2. **Codes are handed out at random**, so a code doesn't identify anyone.
3. **Votes never contain the code or account.** Code uses record only *that* a code voted, not how.
4. **No timestamps on votes, loans, reviews or projects:** session dates only. Code uses have no ID or timestamp, so they can't be matched to votes.
5. **5 or more votes** before any breakdown is shown.
6. **Personas, not names,** built only from approved word lists. Personas that look like codes are refused. Personas are never linked to votes.
7. **The projector needs a screen code**, so live counts never appear on the public internet.
8. **Delete early:** see the "Kept" column above. Clean-up runs daily (WP-Cron) and on API calls, because WP-Cron only runs when the site is visited.
9. **Secrets and tokens are stored as hashes.**
10. **No tracking or analytics**, and no cookies besides sign-in.
11. **Account data can be exported and erased** through WordPress's privacy tools.

## Test data

There's one WordPress site. Test data is kept separate inside it:

- Sessions, questionnaires, proposals and projects have a **`test` flag**; everything else hangs off them.
- Requests with the **test secret** see and change only test data. Requests with the live secret see only live data. This is enforced in one place in the plugin.
- **Create test session** (Tools): makes a session for today with polls that are open, closing soon and closed, an always-open poll, a practice quiz and sample library loans.
- **Delete all test data** (Tools).
- Test data never appears in reports or exports unless asked for.

## API

All under the REST prefix, e.g. `https://cms.example.org/json/dojo/v1/`. Every route needs `X-Dojo-Secret`, except published results. The full list will be in the README. Groups:

- `auth/` sign in (code or account), sign out, sign out everywhere, sign everyone out, me
- `accounts/` request, approve, reset password (mentor)
- `sessions/` today, upcoming
- `questionnaires/` list, get, create (pending), approve, close early
- `votes/` vote
- `results/` get, trends
- `live/` totals for the projector and mentors
- `quiz/` control, answer, me, live
- `personas/` set
- `projects/` submit, list, approve, delete, showcase order
- `library/` items, borrow, approve, return, review
- `approvals/` pending items for mentors

Errors use WordPress's `WP_Error` with an HTTP status and a message written for ninjas.

## WP admin

Menu: **Dojo Club**.

- **Dashboard:** next session, pending approvals, items on loan, health warnings, last clean-up.
- **Sessions:** generate a term, cancel or change, test flag. **Codes:** generate, print, delete.
- **Polls & quizzes:** questionnaires, polls, quizzes, RFC rounds; close early; results.
- **Proposals.**
- **Session projects:** approve, delete, order.
- **Library:** items and copies, loans, reviews.
- **Accounts:** requests, approve, band and consent, reset password, delete.
- **Approvals:** one queue for everything pending.
- **Results & reports:** aggregates only, CSV export.
- **Settings:** club name, weekly session rule and breaks, age bands, minimum group size, code counts, retention times, secrets.
- **Data & privacy:** what's kept and for how long, run clean-up now, privacy summary for parents.
- **Tools:** backup and restore (JSON), import from 0.4, create and delete test data, health check.
- **Audit log:** which WordPress user approved, deleted or changed what.

Each screen is limited by dojo role capabilities.

## Backup and export

- **Backup:** everything the plugin stores, as one JSON file. Account data is left out unless a Champion chooses to include it. Secrets are never included.
- **Restore:** from a backup, onto an empty install.
- **Export:** CSV of aggregate results and reports.

## Testing

Set up in full once 1.0 is finalised:

- **Plugin:** PHPUnit (via Composer, dev only, not in the release zip) for the pure logic: validation, tallying, roll-ups, scoring, codes, personas. WordPress integration tests need a database and are out of scope for now.
- **Front end:** Vitest for logic; Playwright (Firefox) for end-to-end flows against a test session.
- **Manual:** the test secret and "Create test session" on the live WordPress.

## Migration from 0.4

- A **Tools → Import from 0.4** screen copies existing results (including 24 Nov 2026) into the new tables, linked to imported sessions and questionnaires.
- Codes, sign-ins, personas and quiz data are not imported: they're short-lived.
- 0.4's tables are dropped only after the import is confirmed.
- 0.4 and 1.0 use different slugs (`carrickdojo-polls` / `dojo-club`), so 1.0 is installed alongside, imported, then 0.4 is removed.

## Hosting notes

- Runs on any host with current WordPress and PHP 8.3+. CarrickDojo uses Wasmer, with the REST prefix `/json`.
- If the CMS is ever behind a proxy (e.g. Cloudflare), bypass the cache for the API and read visitor IPs from the proxy's header for rate limits.

## Backlog

- Word filter for free text.
- RFC comments.
- IP restriction for session sign-in.
- Optional passkeys for accounts.
