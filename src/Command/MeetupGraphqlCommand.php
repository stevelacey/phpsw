<?php

namespace PHPSW\Command;

use Knp\Command\Command,
    PHPSW\Meetup\Client,
    PHPSW\Meetup\Description,
    PHPSW\Meetup\Graphql,
    Symfony\Component\Console\Input\InputInterface,
    Symfony\Component\Console\Input\InputOption,
    Symfony\Component\Console\Output\OutputInterface;

/**
 * Imports from Meetup's public GraphQL API into the shape the old REST API import
 * (meetup:import:all) stored in redis, then rebuilds speakers and talks from it
 */
class MeetupGraphqlCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('meetup:import:graphql')
            ->addOption('overwrite', null, InputOption::VALUE_NONE, 'Overwrite events already in redis, e.g. from fixtures')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $app = $this->getSilexApplication();

        $this->app = $app;
        $this->gql = new Graphql();
        $this->redis = $app['redis'];
        $this->urlname = $app['meetup']['urlname'];

        $tasks = [
            'group'    => function ($callback) { $this->importGroup($callback); },
            'members'  => function ($callback) { $this->importMembers($callback); },
            'events'   => function ($callback) use ($input) { $this->importEvents($callback, $input->getOption('overwrite')); },
            'speakers' => function ($callback) { $this->importSpeakers($callback); },
            'talks'    => function ($callback) { $this->importTalks($callback); },
            'avatars'  => function ($callback) { $this->importAvatars($callback); },
            'embeds'   => function ($callback) { $this->importEmbeds($callback); }
        ];

        foreach ($tasks as $type => $task) {
            echo ucfirst($type), ': ';

            $task(function () {
                echo '.';
            });

            echo PHP_EOL;
        }
    }

    protected function importGroup($callback)
    {
        $group = $this->gql->query('query ($urlname: String!) {
            groupByUrlname(urlname: $urlname) {
                id name description link city country lat lon timezone foundedDate
                stats { memberCounts { all } eventRatings { average totalRatings } }
            }
        }', ['urlname' => $this->urlname])->groupByUrlname;

        $cached = $this->get('group') ?: (object) ['who' => 'Members', 'photos' => [], 'sponsors' => []];

        $cached->id = (int) $group->id;
        $cached->name = $group->name;
        $cached->urlname = $this->urlname;
        $cached->link = $group->link;
        $cached->members = $group->stats->memberCounts->all;
        $cached->rating = (object) [
            'average' => $group->stats->eventRatings->average,
            'count' => $group->stats->eventRatings->totalRatings
        ];

        $this->set('group', $cached);

        $callback();
    }

    /**
     * Unauthenticated, Meetup only gives first names and no photos, so members already
     * in redis keep their details, minus any photos Meetup no longer serves
     */
    protected function importMembers($callback)
    {
        $edges = $this->gql->all('query ($urlname: String!, $after: String) {
            groupByUrlname(urlname: $urlname) {
                memberships(first: 200, after: $after) {
                    pageInfo { hasNextPage endCursor }
                    edges {
                        metadata { joinTime lastAccessTime bio }
                        node { id name memberUrl memberPhoto { highResUrl standardUrl thumbUrl } }
                    }
                }
            }
        }', 'groupByUrlname.memberships', ['urlname' => $this->urlname]);

        $members = array_map('json_decode', $this->redis->hgetall('members'));
        $photos = [];

        foreach ($edges as $edge) {
            $node = $edge->node;
            $member = isset($members[$node->id]) ? $members[$node->id] : (object) [
                'id' => (int) $node->id,
                'name' => $node->name,
                'status' => 'active',
                'other_services' => (object) []
            ];

            $member->link = $member->profile_url = $node->memberUrl;
            $member->joined = strtotime($edge->metadata->joinTime) * 1000;
            $member->visited = strtotime($edge->metadata->lastAccessTime) * 1000;

            if ($edge->metadata->bio) {
                $member->bio = $edge->metadata->bio;
            }

            if ($node->memberPhoto) {
                $member->photo = (object) [
                    'highres_link' => $node->memberPhoto->highResUrl,
                    'photo_link' => $node->memberPhoto->standardUrl,
                    'thumb_link' => $node->memberPhoto->thumbUrl
                ];
            }

            $members[$node->id] = $member;
        }

        foreach ($members as $id => $member) {
            foreach (['highres_link', 'photo_link', 'thumb_link'] as $link) {
                if (isset($member->photo->$link)) {
                    $photos[$id] = $member->photo->$link;
                    break;
                }
            }
        }

        foreach ($this->deadUrls($photos) as $id) {
            unset($members[$id]->photo);
        }

        foreach ($members as $id => $member) {
            $this->hset('members', $id, $member);

            $callback();
        }
    }

    protected function importEvents($callback, $overwrite)
    {
        $fields = 'id title dateTime createdTime status eventUrl description
            venue { id name address city country lat lon }
            rsvps { yesCount totalCount }
            featuredEventPhoto { id highResUrl standardUrl thumbUrl }
            comments {
                edges { node {
                    id text created member { id name }
                    replies { edges { node { id text created inReplyTo member { id name } } } }
                } }
            }';

        $statuses = ['PAST' => 'past', 'ACTIVE' => 'upcoming', 'DRAFT' => 'draft', 'CANCELLED' => 'cancelled'];

        $group = $this->get('group');

        $feedback = $this->app['joindin.client']->get('http://api.joind.in/v2.1/events', ['tags' => 'phpsw'])->events;

        foreach ($statuses as $status => $legacyStatus) {
            $edges = $this->gql->all('query ($urlname: String!, $after: String) {
                groupByUrlname(urlname: $urlname) {
                    events(status: ' . $status . ', first: 50, after: $after, sort: ASC) {
                        pageInfo { hasNextPage endCursor }
                        edges { node { ' . $fields . ' } }
                    }
                }
            }', 'groupByUrlname.events', ['urlname' => $this->urlname]);

            foreach ($edges as $edge) {
                $node = $edge->node;

                if (!$overwrite && $this->redis->hexists('events', $node->id)) {
                    continue;
                }

                $photo = $node->featuredEventPhoto;

                $event = (object) [
                    'id' => $node->id,
                    'name' => $node->title,
                    'slug' => $this->slugify($node->title),
                    'status' => $legacyStatus,
                    'visibility' => 'public',
                    'time' => strtotime($node->dateTime) * 1000,
                    'created' => strtotime($node->createdTime) * 1000,
                    'utc_offset' => (new \DateTime($node->dateTime))->getOffset() * 1000,
                    'event_url' => $node->eventUrl,
                    'url' => $node->eventUrl,
                    'description' => $this->obfuscateEmails(Description::toHtml($node->description)),
                    'yes_rsvp_count' => $node->rsvps->yesCount ?: $node->rsvps->totalCount,
                    'venue' => $node->venue ? (object) [
                        'id' => (int) $node->venue->id,
                        'name' => $node->venue->name,
                        'address_1' => $node->venue->address,
                        'city' => $node->venue->city,
                        'country' => $node->venue->country,
                        'lat' => $node->venue->lat,
                        'lon' => $node->venue->lon
                    ] : null,
                    'group' => (object) [
                        'id' => $group->id,
                        'name' => $group->name,
                        'urlname' => $this->urlname,
                        'who' => isset($group->who) ? $group->who : 'Members'
                    ],
                    'photos' => $photo ? [(object) [
                        'id' => $photo->id,
                        'photo_id' => $photo->id,
                        'highres_link' => $photo->highResUrl,
                        'photo_link' => $photo->standardUrl,
                        'thumb_link' => $photo->thumbUrl
                    ]] : [],
                    'comments' => array_map(function ($edge) {
                        return $this->comment($edge->node, array_map(function ($edge) {
                            return $this->comment($edge->node);
                        }, $edge->node->replies->edges));
                    }, $node->comments->edges),
                    'rsvps' => [],
                    'talks' => [],
                    'feedback' => null
                ];

                foreach ($feedback as $joindin) {
                    if (preg_replace('#.*/(\d+)[-/].*#', '\1', $joindin->href) == $event->id) {
                        $event->feedback = (object) ['uri' => isset($joindin->humane_website_uri) ? $joindin->humane_website_uri : $joindin->website_uri];
                    }
                }

                $this->hset('events', $event->id, $event);

                $callback();
            }
        }
    }

    /**
     * Speakers and talks, the same way meetup:import:all built them, but from the
     * events in redis rather than the API
     */
    protected function importSpeakers($callback)
    {
        foreach (array_reverse($this->parser()->getEvents()) as $event) {
            foreach ($event->talks as $talk) {
                foreach ($talk->speakers as $speaker) {
                    $a = $this->hget('speakers', $speaker->slug);
                    $b = $speaker;

                    if (isset($a->member) && !isset($b->member)) {
                        $speaker = (object) array_replace_recursive((array) $b, (array) $a);
                    } elseif (!isset($a->member) && isset($b->member)) {
                        $speaker = (object) array_replace_recursive((array) $a, (array) $b);
                    } else {
                        $speaker = $b;
                    }

                    if (!isset($speaker->photos)) {
                        $speaker->photos = isset($a->photos) ? $a->photos : [];
                    }

                    $this->hset('speakers', $speaker->slug, $speaker);

                    $callback();
                }
            }
        }
    }

    protected function importTalks($callback)
    {
        foreach ($this->parser()->getEvents() as $event) {
            foreach ($event->talks as $talk) {
                $this->hset('talks', $talk->id, $talk);

                $callback();
            }
        }
    }

    /**
     * Twitter avatars for speakers and organisers, which used to come from avatars.phpsw.uk,
     * looked up through the FxTwitter API as Twitter's own needs paid access
     */
    protected function importAvatars($callback)
    {
        // speakers merge in their meetup profile, which can drop the handle an older talk linked
        $speakers = array_map('json_decode', $this->redis->hgetall('speakers'));

        foreach ($this->parser()->getEvents() as $event) {
            foreach ($event->talks as $talk) {
                $speakers = array_merge($speakers, isset($talk->speakers) ? $talk->speakers : []);
            }
        }

        $handles = array_filter(array_merge(
            array_map(function ($speaker) {
                return isset($speaker->twitter) ? $speaker->twitter : null;
            }, $speakers),
            array_map(function ($organiser) {
                return isset($organiser['twitter']) ? $organiser['twitter'] : null;
            }, $this->app['organisers'])
        ));

        foreach (array_unique(array_map('strtolower', $handles)) as $handle) {
            // fxtwitter rate limits, so pace requests and retry the ones that fail
            for ($attempt = 0; $attempt < 3; $attempt++) {
                usleep(500000 + $attempt * 2000000);

                $ch = curl_init('https://api.fxtwitter.com/' . urlencode($handle));

                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_USERAGENT => 'phpsw.uk' # 401s without one
                ]);

                $user = json_decode(curl_exec($ch));

                // it 404s real users when rate limiting too, so only a 200 counts
                if (isset($user->code) && $user->code == 200) break;
            }

            // a default avatar is a deleted handle someone else has since registered
            if (isset($user->user->avatar_url) && strpos($user->user->avatar_url, 'default_profile') === false) {
                $url = str_replace('_normal.', '_400x400.', $user->user->avatar_url);
                $image = @file_get_contents($url);

                // keep a copy, twitter image urls change whenever someone changes their avatar
                if ($image) {
                    $path = '/avatars/' . $handle . '.' . pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);

                    @mkdir($this->app['console.project_directory'] . '/web/avatars');
                    file_put_contents($this->app['console.project_directory'] . '/web' . $path, $image);

                    $this->redis->hset('avatars', $handle, $path);

                    $callback();
                }
            }
        }
    }

    /**
     * Iframe urls for Speaker Deck slides, from its oEmbed endpoint
     */
    protected function importEmbeds($callback)
    {
        $endpoints = [
            'speakerdeck.com' => 'https://speakerdeck.com/oembed.json?url='
        ];

        foreach (array_unique($this->redis->hgetall('slides')) as $url) {
            foreach ($endpoints as $domain => $endpoint) {
                if (strpos($url, $domain) === false) continue;

                $oembed = json_decode(@file_get_contents($endpoint . urlencode($url)));

                if (isset($oembed->html) && preg_match('#<iframe[^>]+src="([^"]+)"#', $oembed->html, $matches)) {
                    $this->redis->hset('embeds', $url, preg_replace('#^//#', 'https://', html_entity_decode($matches[1])));

                    $callback();
                }
            }
        }
    }

    /**
     * A client that reads events from redis and parses talks out of them, as dev does
     */
    protected function parser()
    {
        return new Client($this->app, $this->app['meetup'], false, true);
    }

    protected function comment($node, array $replies = [])
    {
        $member = $node->member ? $this->hget('members', $node->member->id) : null;

        return (object) [
            'id' => $node->id,
            'event_comment_id' => $node->id,
            'comment' => $node->text,
            'time' => strtotime($node->created) * 1000,
            'in_reply_to' => isset($node->inReplyTo) ? $node->inReplyTo : null,
            'member' => $member ?: (object) [
                'id' => $node->member ? (int) $node->member->id : null,
                'name' => $node->member ? $node->member->name : 'A Former Member'
            ],
            'replies' => $replies
        ];
    }

    /**
     * As meetup:import:all did
     */
    protected function obfuscateEmails($html)
    {
        return preg_replace(
            '#<a href="mailto:.*">(.*)@(.*)\.(.*)</a>#U',
            '<a href="mailto:\1 at \2 dot \3" class="email">\1 at \2 dot \3</a>',
            $html
        );
    }

    /**
     * Keys of $urls whose url no longer loads
     */
    protected function deadUrls(array $urls)
    {
        $dead = [];

        foreach (array_chunk($urls, 20, true) as $chunk) {
            $mh = curl_multi_init();
            $handles = [];

            foreach ($chunk as $key => $url) {
                $handles[$key] = $ch = curl_init($url);

                curl_setopt_array($ch, [
                    CURLOPT_NOBODY => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 20
                ]);

                curl_multi_add_handle($mh, $ch);
            }

            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh);
            } while ($running);

            foreach ($handles as $key => $ch) {
                if (curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200) {
                    $dead[] = $key;
                }

                curl_multi_remove_handle($mh, $ch);
            }

            curl_multi_close($mh);
        }

        return $dead;
    }

    protected function slugify($string)
    {
        return $this->parser()->slugify($string);
    }

    protected function get($key)
    {
        return json_decode($this->redis->get($key));
    }

    protected function set($key, $value)
    {
        return $this->redis->set($key, json_encode($value, JSON_PRETTY_PRINT));
    }

    protected function hget($key, $hkey)
    {
        return json_decode($this->redis->hget($key, $hkey));
    }

    protected function hset($key, $hkey, $hvalue)
    {
        return $this->redis->hset($key, $hkey, json_encode($hvalue, JSON_PRETTY_PRINT));
    }
}
