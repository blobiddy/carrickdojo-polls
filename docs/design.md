# Design

How CarrickDojo polls work, and why. This is a club for children, so privacy decisions come first.

## Terms

| Term              | Meaning                                                                                                  |
| ----------------- | -------------------------------------------------------------------------------------------------------- |
| **Session**       | One club session. Its ID is the date, e.g. `2026-11-24`. Times come from `sessions.md` on the club site.  |
| **Session code**  | A slip for one session. **Member** (COLOUR-ANIMAL): ninjas and mentors vote and play. **Mentor** (four words, up to 3): mentors' laptops see and control everything. **Screen** (four words, one): the projector. |
| **Persona**       | A quiz name of 2–4 words (e.g. Cosmic-Kernel-Duke), spun from word categories on the club site. Never typed, never a real name. |
| **Poll**          | One question. Types: `yes-no` and `choice` (pick one, or several with `multiple: true`). Scale (1–100) is planned.  |
| **Questionnaire** | An ordered group of polls with a shared intro, for gathering opinions.                                   |
| **RFC**           | Request for comment: a group of polls about a **proposal**, ending in a recorded decision.              |
| **Proposal**      | A document (`.md` on the club site) that an RFC asks people to vote on.                                 |
| **Quiz**          | A group of polls run live: each question has a time limit and a correct answer, and scores are kept.    |
| **Response**      | Someone's answers, submitted together. Each poll's answer is stored separately.                         |
| **Result**        | A poll's final tally, kept after the votes are deleted.                                                  |

Games are out of scope for now.

## Where things live

| Data                                     | Where                 | Kept                                          |
| ---------------------------------------- | --------------------- | --------------------------------------------- |
| Sessions, polls, groups, proposals       | `.md` on the club site| Permanently (in git)                          |
| Session codes                            | WordPress             | Until the day after the session               |
| Sign-ins (hash of the token only)        | WordPress             | Until they expire, or the day after           |
| Code uses: which code voted in which poll| WordPress             | Until the poll is finalised, or the day after |
| Votes (no code)                          | WordPress             | Until the poll is finalised                   |
| Results                                  | WordPress             | Permanently                                   |
| Personas                                 | WordPress             | Until the day after the session               |
| Quiz runs (mentor overrides), answers and points | WordPress     | Until the day after the session               |
| Persona word lists                       | `.md` on the club site| Permanently (in git)                          |

## RFC lifecycle

`draft` → `open for comment` → `voting` → `accepted` / `rejected` / `sent back for review` → (revised) → `voting` …, or `withdrawn`.

Each vote is a **round** with its own polls, dates and result. Rounds never mix. Status changes, dates and notes are recorded in the proposal document.

## Privacy rules

1. **No personal data.** No names, emails or IP addresses are stored.
2. **Codes are handed out at random**, so a code doesn't identify a person.
3. **Votes never contain the code.** Code uses record only that a code voted in a poll, not how.
4. **Nothing can be put in time order.** Code uses have no ID or timestamp. Votes have random IDs, so one person's answers to several polls aren't stored next to each other and can't be linked.
5. **We don't analyse how people vote together.** Each poll is tallied on its own.
6. **Small groups are protected.** With fewer than 5 votes, only the total is published.
7. **Delete early.** Codes, sign-ins and quiz data go the day after the session. Votes go when the poll is finalised.
8. **Personas, not names.** Personas are built only from approved word lists (never typed), and the plugin refuses any persona that looks like a code, so a leaderboard never gives away a code. Personas are never linked to votes.
9. **The projector needs a screen code.** Live counts and leaderboards are only shown to screen and mentor codes, so they never appear on the public internet.
10. **While a poll is open, only totals are shown** ("18 of 30 voted"), even to mentors: in a room, live bars would show how a friend voted.
11. **Secrets are hashed.** The polling secret and sign-in tokens are stored only as SHA-256 hashes.

## Safety on shared laptops

Club laptops are shared, so the club site should:

- sign out automatically after a short idle time (e.g. 10 minutes),
- show an "I'm finished" button,
- show "Signed in as RED-TIGER" so a child notices if it isn't their code.

## Who does what

- **Club site (server):** holds poll and quiz definitions and persona word lists, checks a poll is open and answers are real options, checks persona words, works out the quiz schedule and points, decides when to finalise. Calls WordPress with `X-Polling-Secret`.
- **Plugin:** enforces codes, one vote per code per poll, anonymity and deletion.
- **Mentor laptop:** starts, pauses, resumes, shifts and ends quizzes, shows the leaderboard, closes polls early, signs everyone out.
- **Poll manager role:** generates and prints session codes on the Polls admin page. Nothing else.
- **Administrator:** everything, including the polling secret.

## Hosting notes

- `club.carrickdojo.com` (Vercel) and `cms.carrickdojo.com` (Wasmer) are **DNS only** in Cloudflare: not proxied, not cached. Only `carrickdojo.com` and `www` are proxied.
- If `club.` or `cms.` are ever proxied, first: bypass the cache for sign-in, poll pages and `/json/*`; skip bot challenges for `/json/polls/*` (the club site's server can't solve them); and read visitor IPs from `CF-Connecting-IP`.
