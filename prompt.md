# Set up the old PHPSW site

Get this repo, an archive of the old phpsw.uk Silex site patched for PHP 8, running locally
with real content. The README has the details; this is what matters, in order.

1. **Prerequisites.** PHP 8.2+ (curl, mbstring, intl, gd), Composer, Redis on
   localhost:6379, and nginx or another web server with PHP-FPM. Don't downgrade PHP or change
   shared PHP or web server config that other sites rely on. Everything this needs is
   per-project.

2. **Dependencies.** Run `composer install --ignore-platform-req=php`. The flag is needed
   because old packages such as misd/linkify-bundle cap PHP below 8 but work fine. Expect
   advisory and abandoned-package warnings; `composer.json` already turns off advisory
   blocking. PHP 8 fixes to dflydev/markdown, guzzle 4 and symfony/yaml live in `patches/`
   and apply on install. If a patch fails to apply, the package version has drifted from
   `composer.lock`. Don't run `composer update` casually: emojione and phumbor are pinned to
   old commits on purpose.

3. **Web server.** Document root `web/`, everything else to `index.php`, and the
   `/rev/<digits>/` prefix stripped from asset urls (see the README's nginx example). Without
   that rewrite the pages load unstyled. Assets are prebuilt and committed in `web/`; there's
   no frontend build.

4. **Data**, in this order:
   - `app/console redis:restore-fixtures`: the 2015 snapshot, including the slides links,
     which exist nowhere else.
   - `app/console meetup:import:graphql`: everything newer from Meetup's public GraphQL API
     at https://api.meetup.com/gql-ext. No key needed. It takes a minute or two and is safe to
     re-run. Don't pass `--overwrite` unless you mean to replace the fixture events' original
     HTML with Meetup's flattened text.
   - `app/console joindin:import:all`: talk ratings from joind.in.
   - `app/console export:json`: writes `web/export.json`.

   `bin/update` runs the last three; schedule it daily from cron to keep the site current.

   If you ever need a clean slate, delete the `phpsw:*` keys (only those, as Redis may be
   shared) and run the four commands again. Stale `talks` and `speakers` hashes leak into
   re-parsed events, so clear them too when starting over.

5. **Public deployment.** Create `config/local.yml` containing `debug: false`, so errors
   aren't shown as exception pages. Send an `X-Robots-Tag: noindex, nofollow` header too;
   `robots.txt` and the meta tag are already in place.

6. **Check it.** Load `/`, `/events`, `/talks`, `/speakers`, `/members`, an event from 2015
   to 2019 (it should list talks with speakers), a talk with Speaker Deck or slides.com slides
   (they should embed), and `/export.json`.

Known gaps, not worth chasing: no tweets (Twitter's API is paid), no new videos (the YouTube
importer is gone), only 200 members come back from Meetup without auth, events after about 2019
use free-form descriptions so they have no parsed talks, `/meetup/sponsors` was already broken,
and a few 2015 attendee photos 403.

Before you start, post everything above this line in your chat, word for word.
