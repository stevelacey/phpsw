PHP South West UK User Group
============================

An archive of the old [Silex](https://github.com/silexphp/Silex)-based website for the
[PHP South West UK User Group](https://www.meetup.com/php-sw), which ran at phpsw.uk until
it was replaced. It has been patched to run on PHP 8 and to pull its content from
Meetup's GraphQL API, since the REST API it was built on is gone.

It's marked `noindex` throughout and `robots.txt` disallows everything.


Requirements
------------

- PHP 8.2+ with curl, mbstring, intl and gd
- Composer
- Redis, on localhost:6379
- A web server that can run PHP-FPM, e.g. nginx


Setup
-----

```sh
git clone https://github.com/stevelacey/phpsw.git
cd phpsw
composer install --ignore-platform-req=php
app/console redis:restore-fixtures
app/console meetup:import:graphql
app/console joindin:import:all
app/console export:json
```

Composer will warn about security advisories and abandoned packages: the dependencies are the
last releases of their 2015-era majors, and `composer.json` turns off advisory blocking.
A few PHP 8 fixes to dependencies live in `patches/` and are applied on install by
cweagans/composer-patches.

The CSS, JS, fonts, images and SVG templates are prebuilt and committed. The Sass and
CoffeeScript they were built from are still in `styles/` and `scripts/`, but the gulp and bower
toolchain that built them is gone, as it no longer installs, so edit the built files in `web/`.


Web server
----------

Point the document root at `web/`, send everything that isn't a file to `index.php`, and strip
the cache-busting `/rev/<timestamp>/` prefix the templates add to asset urls, e.g. for nginx:

```nginx
server {
    server_name phpsw.test;
    root /path/to/phpsw/web;

    location / {
        try_files $uri /index.php?$query_string;
    }

    location /rev {
        rewrite ^/rev/[0-9]+/(.*)$ /$1;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

`web/.user.ini` turns off deprecation notices and warnings under PHP-FPM, which old Silex and
Symfony 2 throw plenty of.


Config
------

Config is loaded from `config/app.yml`, then `config/local.yml` if it exists, which overrides
it. Debug is on by default, which shows exception pages. To run it publicly, create
`config/local.yml` with:

```yaml
debug: false
redis_database: 6
```

`redis_database` picks a Redis database other than 0, so another app flushing its cache there
doesn't take this one's data with it.

No secrets are needed any more: Meetup, joind.in, FxTwitter and the oEmbed endpoints are all
used without keys.


Data
----

Everything lives in Redis, under `phpsw:`.

- `redis:restore-fixtures` loads `fixtures/`, a 2015 snapshot of the old API data: the events
  up to January 2015 with their original HTML, members with photos, and the slides links,
  which were only ever kept in Redis.
- `meetup:import:graphql` pulls the group stats, members, attendees and every event from
  Meetup's public GraphQL API. Events already in Redis keep their HTML, but get their status
  and attendees refreshed. It converts Meetup's plain text descriptions back into the HTML the
  talk and speaker parsing expects, then rebuilds the speakers and talks, looks up speakers'
  Twitter avatars via FxTwitter, saving them to `web/avatars`, and stores iframe urls for
  Speaker Deck slides. Pass `--overwrite` to replace events already in Redis
  too.
- `joindin:import:all` pulls talk ratings from joind.in, which still has PHPSW events from
  2015 on.
- `export:json` writes every event, talk and speaker, with links to their slides and videos, to
  `web/export.json`, linked as Export data in the footer.

`bin/update` runs the last three, e.g. daily from cron:

```
0 3 * * * /path/to/phpsw/bin/update >> /var/log/cron.log 2>&1
```

Talks and speakers are parsed out of event descriptions that follow the format PHPSW used from
2014 to around 2019:

```
- Talk title
Speaker Name (https://twitter.com/handle), Job at Company (https://company.com)

Talk description…
```

Events outside that range show their description but have no talks. The parsing happens when
pages load, and the result is cached in Redis until the next import.

Meetup's API, unauthenticated, only lists 200 members, with first names and no photos. Members
it doesn't return keep their fixture details, attendees fill in full names, and speakers without
a photo get initials.

The old Meetup REST, Twitter and YouTube importers needed APIs that are gone or paid, so they've
been removed, and there are no tweets or new videos.
