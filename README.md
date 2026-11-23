# CarrickDojo Polls

A WordPress plugin for anonymous, one-time-code polls on the CarrickDojo club site ([club.carrickdojo.com](https://club.carrickdojo.com)).

The poll itself (questions, session times) is a `.md` file on the SvelteKit club site. This plugin only stores the codes and votes, and the final result.

## How it works

1. A mentor generates codes (e.g. `TIGER-RED`) in **Tools → Polls**, prints them and hands them out at random.
2. Each code can be used once. Codes are case-insensitive.
3. Votes are stored **without** the code, so nobody can tell who voted for what.
4. When the poll closes, the club site asks the plugin to finalise it: the votes are tallied, the result is saved, and all votes and codes are deleted.
5. If fewer than 5 votes were cast, only the total is published, not the breakdown.

## Install

1. Zip the plugin folder (without `.git`):

   ```bash
   zip -r carrickdojo-polls.zip carrickdojo-polls -x 'carrickdojo-polls/.git/*'
   ```

2. In WordPress admin: **Plugins → Add New → Upload Plugin**, then Install and Activate.
3. Make sure `wp-config.php` defines the shared secret (the same one the club site's message form uses):

   ```php
   define('CARRICK_FRONTEND_SECRET', 'a-long-random-string');
   ```

## Endpoints

All under the site's REST prefix, e.g. `https://cms.carrickdojo.com/json/api/...`

| Method | Path                       | Who                    | What                                                  |
| ------ | -------------------------- | ---------------------- | ----------------------------------------------------- |
| POST   | `/api/polls/{slug}/vote`     | Club site (secret)     | Body `{ "code": "TIGER-RED", "answers": { ... } }`  |
| POST   | `/api/polls/{slug}/finalise` | Club site (secret)     | Tally, save result, delete votes and codes            |
| GET    | `/api/polls/{slug}/results`  | Anyone                 | The final result, once finalised (404 before)         |

The club site sends the secret in an `X-Frontend-Secret` header.

`answers` maps question IDs to an answer, or a list of answers for pick-several questions:

```json
{ "dark-default": "yes", "next-feature": ["Games page", "Badges"] }
```

Question IDs use lowercase letters, numbers and hyphens, and must include at least one letter.

### Responses

| Status | Meaning                                                        |
| ------ | -------------------------------------------------------------- |
| 201    | Vote saved / poll finalised                                    |
| 400    | Missing or badly shaped answers, or the code isn't valid/used  |
| 401    | Missing or wrong secret                                        |
| 409    | Poll already finalised                                         |
| 429    | Too many wrong codes (10 a minute per poll). Wait a minute     |
| 503    | `CARRICK_FRONTEND_SECRET` isn't set in `wp-config.php`         |

## Files

- `carrickdojo-polls.php`: plugin header, settings and database tables
- `includes/words.php`: animal and colour word lists for codes
- `includes/codes.php`: generating and managing codes
- `includes/rest.php`: the REST endpoints
- `includes/admin.php`: the Tools → Polls admin page
- `uninstall.php`: removes the tables when the plugin is deleted

## Planned

- Restrict voting to the club building's IP address.
