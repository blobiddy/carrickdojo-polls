# CarrickDojo Polls

A WordPress plugin for anonymous polls, questionnaires, RFCs and quizzes on the CarrickDojo club site ([club.carrickdojo.com](https://club.carrickdojo.com)).

The polls themselves (questions, options, times) are `.md` files on the SvelteKit club site. This plugin stores only what has to be kept on a server: session codes, sign-ins, votes, final results and quiz scores. It deletes everything it can as soon as it can.

See [docs/design.md](docs/design.md) for the terms, data and privacy rules.

## How it works

1. A poll manager generates **session codes** (e.g. `RED-TIGER`) on the **Polls** admin page, prints them, and hands one to each ninja and mentor at random.
2. On the club site, they **sign in** with their code. It only works on the day of that session.
3. They can vote **once in every poll** open that session. Votes are stored **without** the code.
4. When a poll closes, the club site asks the plugin to **finalise** it: the votes are tallied, the result is saved, and the votes are deleted. With fewer than 5 votes, only the total is published.
5. The day after a session, its codes, sign-ins and quiz scores are deleted automatically.

## Install

1. Zip the plugin folder (without `.git`):

   ```bash
   zip -r carrickdojo-polls.zip carrickdojo-polls -x 'carrickdojo-polls/.git/*'
   ```

2. In WordPress admin: **Plugins → Add New → Upload Plugin**, then Install and Activate.
3. As an administrator, open **Polls** and set the **polling secret** at the bottom of the page. Use the same value as `DOJO_POLLING_SECRET` in the club site's Vercel settings. Create one with:

   ```bash
   openssl rand -hex 32
   ```

4. Give mentors who run polls the **Poll manager** role (**Users → edit user → Role**). It only opens the Polls page.

## Endpoints

All under the site's REST prefix, e.g. `https://cms.carrickdojo.com/json/polls/...`

Everything except `results` needs the `X-Polling-Secret` header and is called by the club site's server only, never the browser.

| Method | Path                      | Body                                   | Returns                              |
| ------ | ------------------------- | -------------------------------------- | ------------------------------------ |
| POST   | `/polls/signin`           | `{ session, code, expires }`           | `{ token, expires_at }`              |
| POST   | `/polls/signout`          | `{ token }`                            | `{ signed_out: true }`               |
| POST   | `/polls/me`               | `{ token }`                            | `{ session, code, voted, nickname }` |
| POST   | `/polls/vote`             | `{ token, answers: { poll: answer } }` | `{ voted: [poll] }`                  |
| POST   | `/polls/{poll}/finalise`  |                                        | result                               |
| GET    | `/polls/{poll}/results`   | (public)                               | result, or 404 before finalising     |
| POST   | `/polls/quiz/points`      | `{ token, quiz, question, points }`    | `{ nickname, score, counted }`       |
| POST   | `/polls/quiz/leaderboard` | `{ session, quiz, limit }`             | `[ { nickname, score } ]`            |

- `session` is the session date, e.g. `2026-11-24`.
- `expires` is when the sign-in should end (ISO 8601), normally the end of the session. It's capped at 12 hours.
- `answers` maps poll slugs to an answer, or a list of answers for pick-several polls. Several polls can be answered at once (a questionnaire or RFC): `{ "dark-default": "yes", "next-feature": ["Games page", "Badges"] }`
- Poll, quiz and question slugs use lowercase letters, numbers and hyphens, with at least one letter.
- A result looks like `{ poll, total, finalised_at, result }`. `result` is `{ answer: count }`, or `null` when fewer than 5 votes were cast.
- The club site must check that a poll is open and that answers are real options **before** calling `vote`. It also works out quiz points, since it holds the correct answers and the timer.

### Errors

| Status | Meaning                                                                |
| ------ | ---------------------------------------------------------------------- |
| 400    | Missing or badly shaped data, a wrong code, or a code on the wrong day |
| 401    | Wrong or missing secret, or the sign-in has ended                      |
| 409    | Already voted in that poll, or the poll has been finalised             |
| 429    | Too many wrong codes (10 a minute per session). Wait a minute          |
| 503    | The polling secret hasn't been set                                     |

## Files

- `carrickdojo-polls.php`: plugin header, settings, database tables and role
- `includes/words.php`: word lists for codes and quiz nicknames
- `includes/helpers.php`: validation and dates
- `includes/secret.php`: the polling secret
- `includes/codes.php`: session codes
- `includes/cleanup.php`: daily deletion of old data
- `includes/rest.php`: the REST endpoints
- `includes/admin.php`: the Polls admin page
- `uninstall.php`: removes everything when the plugin is deleted

## Planned

- Restrict sign-in to the club building's IP address. If `club.` or `cms.` are ever proxied through Cloudflare, use `CF-Connecting-IP`, trusting it only for requests that come from Cloudflare.
- Scale questions (1–100).
