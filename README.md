# Dojo Club

A WordPress plugin for CoderDojo clubs: sessions, anonymous polls, quizzes, RFCs, session projects, a lending library and ninja accounts. Built and tested by [CarrickDojo](https://carrickdojo.com), for use by any dojo.

**Status: 1.0 in development.** The installed version on CarrickDojo is still CarrickDojo Polls 0.4 (the `main` branch).

See [docs/design.md](docs/design.md) for the design, data and privacy rules.

## Install

1. Make the zip from this branch (the `dojo-club/` folder name matters: it's the plugin's slug):

   ```bash
   git archive --prefix=dojo-club/ -o dojo-club.zip HEAD
   ```

2. In WordPress admin: **Plugins → Add New → Upload Plugin**, then Install and Activate.
3. **Dojo Club → Settings:** set the club name and session times, then generate the **live** and **test** secrets. Each is shown once.
4. On each adult's profile (**Users → edit**), tick their **Dojo roles**.

## Endpoints

Under the site's REST prefix, e.g. `https://cms.example.org/json/dojo/v1/…`. Every route needs the `X-Dojo-Secret` header unless marked public.

| Method | Path | Returns |
| --- | --- | --- |
| GET | `/dojo/v1/status` | `{ club, version, scope }`: `scope` is `live` or `test`, depending on the secret sent |

## Licence

GPL-2.0-or-later. See [LICENSE](LICENSE).
