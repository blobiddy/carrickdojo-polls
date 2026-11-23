# CarrickDojo Polls

A WordPress plugin for anonymous polls, questionnaires, RFCs and live quizzes on the CarrickDojo club site ([club.carrickdojo.com](https://club.carrickdojo.com)).

The polls and quizzes themselves (questions, options, times) are `.md` files on the SvelteKit club site. This plugin stores only what has to be kept on a server: session codes, sign-ins, personas, votes, final results and quiz scores. It deletes everything it can as soon as it can.

See [docs/design.md](docs/design.md) for the terms, data and privacy rules.

## How it works

1. A poll manager generates the session's codes on the **Polls** admin page and prints them:
   - **Member codes** (`RED-TIGER`), handed one each to ninjas and mentors at random.
   - **Mentor codes** (`RED-TIGER-BLUE-OWL`, up to 3) for mentors' laptops, and one **screen code** for the projector. These print on a separate "Mentors only" page.
2. On the club site, everyone **signs in** with their code. Codes only work on the day of their session.
3. Members pick a **persona** (e.g. `Cosmic-Kernel-Duke`) from the club site's word lists, used in quizzes. They can change it during the session.
4. Members vote **once in every poll** open that session. Votes are stored **without** the code.
5. The projector shows live **totals** ("18 of 30 voted"), never how anyone voted.
6. When a poll closes (or a mentor closes it early), the club site **finalises** it: the votes are tallied, the result is saved, and the votes are deleted. With fewer than 5 votes, only the total is published.
7. Quizzes run on a schedule from their `.md` file. The mentor laptop can start, pause, resume, shift time, show the leaderboard or end them.
8. The day after a session, its codes, sign-ins, personas and quiz data are deleted automatically.

## What each code can do

|                                    | Member | Screen | Mentor |
| ---------------------------------- | :----: | :----: | :----: |
| Vote, pick a persona, play quizzes |   ✓    |        |        |
| See live counts and quiz screen    |        |   ✓    |   ✓    |
| Control quizzes, sign everyone out |        |        |   ✓    |

## Install

1. Zip the plugin folder (without `.git`):

   ```bash
   zip -r carrickdojo-polls.zip carrickdojo-polls -x 'carrickdojo-polls/.git/*'
   ```

2. In WordPress admin: **Plugins → Add New → Upload Plugin**, then Install and Activate (or **Replace current** to upgrade).
3. As an administrator, open **Polls** and set the **polling secret** at the bottom of the page. Use the same value as `DOJO_POLLING_SECRET` in the club site's Vercel settings. Create one with:

   ```bash
   openssl rand -hex 32
   ```

4. Give mentors who run polls the **Poll manager** role (**Users → edit user → Role**). It only opens the Polls page.

## Endpoints

All under the site's REST prefix, e.g. `https://cms.carrickdojo.com/json/polls/...`

Everything except `results` needs the `X-Polling-Secret` header and is called by the club site's server only, never the browser. "Who" is the kind of code the `token` must belong to.

| Method | Path                     | Who            | Body                                        | Returns                                                     |
| ------ | ------------------------ | -------------- | ------------------------------------------- | ----------------------------------------------------------- |
| POST   | `/polls/signin`          | anyone         | `{ session, code, expires }`                | `{ token, kind, expires_at }`                               |
| POST   | `/polls/signout`         | anyone         | `{ token }`                                 | `{ signed_out: true }`                                      |
| POST   | `/polls/signout-all`     | mentor         | `{ token }`                                 | `{ signed_out: count }` (members only)                      |
| POST   | `/polls/me`              | any            | `{ token }`                                 | `{ session, code, kind, voted, persona }`                   |
| POST   | `/polls/persona`         | member         | `{ token, persona }`                        | `{ persona }`                                               |
| POST   | `/polls/live`            | screen, mentor | `{ token, polls: [poll] }`                  | `{ session, members, signed_in, polls: { poll: { votes, finalised } } }` |
| POST   | `/polls/vote`            | member         | `{ token, answers: { poll: answer } }`      | `{ voted: [poll] }`                                         |
| POST   | `/polls/{poll}/finalise` | (club site)    |                                             | result                                                      |
| GET    | `/polls/{poll}/results`  | public         |                                             | result, or 404 before finalising                            |
| POST   | `/polls/quiz/control`    | mentor         | `{ token, quiz, action, seconds }`          | run                                                         |
| POST   | `/polls/quiz/answer`     | member         | `{ token, quiz, question, answer, points }` | `{ counted, score }`                                        |
| POST   | `/polls/quiz/me`         | member         | `{ token, quiz }`                           | `{ persona, score, rank, answered, run }`                   |
| POST   | `/polls/quiz/live`       | screen, mentor | `{ token, quiz, question, before, limit }`  | `{ run, players, answered, tally, leaderboard, previous }`  |

- `session` is the session date, e.g. `2026-11-24`.
- `expires` is when the sign-in should end (ISO 8601), normally the end of the session. It's capped at 12 hours.
- `answers` maps poll slugs to an answer, or a list of answers for pick-several polls. Several polls can be answered at once (a questionnaire or RFC): `{ "dark-default": "yes", "next-feature": ["Games page", "Badges"] }`
- Poll, quiz and question slugs use lowercase letters, numbers and hyphens, with at least one letter.
- A result is `{ poll, total, finalised_at, result }`. `result` is `{ answer: count }`, or `null` when fewer than 5 votes were cast.
- A persona is 2 to 4 words of letters, joined by hyphens. It must be unique in the session and must not look like a code (any existing code, or a colour then an animal).
- Quiz `action` is `start`, `pause`, `resume`, `shift` (`seconds`: negative skips ahead, positive adds time), `leaderboard` (`seconds` to show it) or `end`.
- A quiz run is `{ started_at, paused_at, shift_seconds, leaderboard_until, ended, now }`. `now` is the server's clock, so devices can correct their own.
- For `quiz/live`, `question` gives the answered count and answer tally for that question; `before` lists the questions before this round, so `previous` is the leaderboard before it (for rank changes).
- The club site must check that a poll is open and answers are real options **before** calling `vote`, check persona words against its lists before calling `persona`, and work out quiz points (it holds the correct answers and the timer).

### Errors

| Status | Meaning                                                                                               |
| ------ | ----------------------------------------------------------------------------------------------------- |
| 400    | Missing or badly shaped data, a wrong code, a code on the wrong day, a persona that looks like a code |
| 401    | Wrong or missing secret, or the sign-in has ended                                                     |
| 403    | That kind of code can't do this (e.g. the screen code trying to vote)                                 |
| 409    | Already voted, poll finalised, persona taken, no persona yet, or quiz ended                           |
| 429    | Too many wrong codes (10 a minute per session). Wait a minute                                         |
| 503    | The polling secret hasn't been set                                                                    |

## Files

- `carrickdojo-polls.php`: plugin header, settings, database tables and role
- `includes/words.php`: word lists for codes
- `includes/helpers.php`: validation and dates
- `includes/secret.php`: the polling secret
- `includes/codes.php`: session codes
- `includes/personas.php`: persona checks
- `includes/auth.php`: sign-in tokens, kinds of code, wrong-code limit
- `includes/cleanup.php`: daily deletion of old data
- `includes/rest.php`: the list of REST routes
- `includes/rest-session.php`: sign-in, personas, live counts
- `includes/rest-votes.php`: voting and results
- `includes/rest-quiz.php`: live quizzes
- `includes/admin.php`: the Polls admin page
- `uninstall.php`: removes everything when the plugin is deleted

## Planned

- Restrict sign-in to the club building's IP address. If `club.` or `cms.` are ever proxied through Cloudflare, use `CF-Connecting-IP`, trusting it only for requests that come from Cloudflare.
- Scale questions (1–100).
