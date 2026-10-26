<?php

namespace PHPSW\Meetup;

use Symfony\Component\DomCrawler\Crawler;

class Client
{
    protected $app;
    protected $config;
    protected $events;
    protected $group;
    protected $members;
    protected $organisers;
    protected $posts;
    protected $reviews;
    protected $speakers;
    protected $talks;

    public function __construct($app, array $config)
    {
        $this->app = $app;
        $this->config = $config;
        $this->redis = $app['redis'];
        $this->organisers = $app['organisers'];
        $this->sponsors = $app['sponsors'];
    }

    public function getGroup()
    {
        if ($this->group === null) {
            $this->group = json_decode($this->redis->get('group'));
        }

        return $this->group;
    }

    public function getEvents()
    {
        // parsing every event's talks on each request is slow, so keep the result until the next import
        if ($this->events === null && $cached = $this->redis->get('cache:events')) {
            $this->events = unserialize($cached);
        }

        if ($this->events === null) {
            $this->events = $this->getEventsFromCache();

            $sponsor_ids = [];

            foreach ($this->sponsors as $type => $sponsors) {
                foreach ($sponsors as $sponsor) {
                    if (isset($sponsor['contact']['meetup'])) {
                        $sponsor_ids[] = $sponsor['contact']['meetup'];
                    }
                }
            }

            $this->events = array_map(
                function ($event) use ($sponsor_ids) {
                    $event = $this->parseEvent($event);
                    $event = $this->parseTalks($event);

                    $event->date = \DateTime::createFromFormat('U', $event->time / 1000);

                    $speaker_ids = [];

                    foreach ($event->talks as $talk) {
                        foreach ($talk->speakers as $speaker) {
                            $speaker_ids[] = isset($speaker->member) ? $speaker->member->id : $speaker->name;
                        }
                    }

                    reset($event->talks);

                    $event->comments = array_map(
                        function ($comment) use ($event, $speaker_ids, $sponsor_ids) {
                            $comment->date = \DateTime::createFromFormat('U', $comment->time / 1000);

                            $comment->speaker = in_array($comment->member->id, $speaker_ids) || in_array($comment->member->name, $speaker_ids);
                            $comment->sponsor = in_array($comment->member->id, $sponsor_ids);

                            $comment->replies = array_map(
                                function ($reply) use ($event, $speaker_ids, $sponsor_ids) {
                                    $reply->date = \DateTime::createFromFormat('U', $reply->time / 1000);

                                    $reply->speaker = in_array($reply->member->id, $speaker_ids) || in_array($reply->member->name, $speaker_ids);
                                    $reply->sponsor = in_array($reply->member->id, $sponsor_ids);

                                    return $reply;
                                },
                                (isset($comment->replies) ? $comment->replies : [])
                            );

                            return $comment;
                        },
                        (isset($event->comments) ? $event->comments : [])
                    );

                    return $event;
                },
                $this->events
            );

            uasort($this->events, function($a, $b) {
                return $b->date <=> $a->date;
            });

            $this->redis->set('cache:events', serialize($this->events));
        }

        return $this->events;
    }

    public function getEvent($id)
    {
        $events = array_values(array_reverse($this->getEvents()));

        foreach ($events as $i => $event) {
            if ($event->id == $id) {
                $event->prev = array_key_exists($i - 1, $events) ? $events[$i - 1] : null;
                $event->next = array_key_exists($i + 1, $events) ? $events[$i + 1] : null;

                return $event;
            }
        }
    }

    public function getDraftEvents()
    {
        return array_reverse(
            array_filter($this->getEvents(), function ($event) {
                return $event->status == 'draft';
            })
        );
    }

    public function getPastEvents()
    {
        return array_filter($this->getEvents(), function ($event) {
            return $event->status == 'past';
        });
    }

    public function getUpcomingEvents()
    {
        return array_reverse(
            array_filter($this->getEvents(), function ($event) {
                return $event->status == 'upcoming';
            })
        );
    }

    public function getPosts()
    {
        if ($this->posts === null) {
            $this->posts = $this->getPostsFromCache();

            $this->posts = array_map(
                function ($post) {
                    $post->last_post->created_date = \DateTime::createFromFormat('U', $post->last_post->created / 1000);

                    return $post;
                },
                $this->posts
            );

            uasort($this->posts, function($a, $b) {
                return $b->last_post->created_date <=> $a->last_post->created_date;
            });
        }

        return $this->posts;
    }

    public function getMember($id)
    {
        if (array_key_exists($id, $this->getMembers())) {
            $member = $this->getMembers()[$id];
        } else {
            $member = (object) [
                'id' => null,
                'name' => 'A Former Member',
                'bio' => '',
                'url' => null,
                'photo' => (object) [
                    'highres_link' => 'https://img1.meetupstatic.com/img/501554713870081192606960/nobody_50.png',
                    'photo_link'   => 'https://img1.meetupstatic.com/img/501554713870081192606960/nobody_50.png',
                    'thumb_link'   => 'https://img1.meetupstatic.com/img/501554713870081192606960/nobody_50.png',
                    'url'          => 'https://img1.meetupstatic.com/img/501554713870081192606960/nobody_50.png'
                ],
                'organiser' => false
            ];
        }

        return $member;
    }

    public function getMembers()
    {
        if ($this->members === null) {
            $this->members = array_map(
                function ($member) {
                    $member = json_decode($member);

                    $member->joined = \DateTime::createFromFormat('U', $member->joined / 1000);

                    return $member;
                },
                $this->redis->hgetall('members')
            );

            $organisers = array_map(
                function ($organiser) {
                    return $organiser['meetup'];
                },
                $this->organisers
            );

            $this->members = array_combine(
                array_map(
                    function ($member) {
                        $member = (object) $member;

                        return $member->id;
                    },
                    $this->members
                ),
                array_map(
                    function ($member) use ($organisers) {
                        $member = (object) $member;

                        $member->organiser = in_array($member->id, $organisers);

                        if (isset($member->photo)) {
                            $photo = $member->photo = (object) $member->photo;

                            if (isset($photo->highres_link)) {
                                $member->photo->url = $photo->highres_link;
                            } elseif (isset($photo->photo_link)) {
                                $member->photo->url = $photo->photo_link;
                            } elseif (isset($photo->thumb_link)) {
                                $member->photo->url = $photo->thumb_link;
                            }
                        }

                        $member->other_services = array_map(
                            function ($service) {
                                return (object) $service;
                            },
                            (array) $member->other_services
                        );

                        $member->url = $member->profile_url;

                        $member->visited_date = \DateTime::createFromFormat('U', ($member->visited / 1000));

                        return $member;
                    },
                    $this->members
                )
            );
        }

        return $this->members;
    }

    public function getReviews()
    {
        if ($this->reviews === null) {
            $this->reviews = array_map(
                function ($review) {
                    $review = json_decode($review);

                    return $review;
                },
                $this->redis->hgetall('reviews')
            );

            $this->reviews = array_map(
                function ($review) {
                    $review = (object) $review;

                    $review->created_date = new \DateTime($review->created);

                    return $review;
                },
                $this->reviews
            );
        }

        return $this->reviews;
    }

    public function getSpeakers()
    {
        if ($this->speakers === null) {
            $this->speakers = array_map(
                function ($speaker) {
                    $speaker = json_decode($speaker);

                    $speaker->talks = array_filter(
                        $this->getTalks(),
                        function ($talk) use ($speaker) {
                            return in_array(
                                $speaker->id,
                                array_map(
                                    function ($s) {
                                        return $s->id;
                                    },
                                    isset($talk->speakers) ? $talk->speakers : []
                                )
                            );
                        }
                    );

                    uasort($speaker->talks, function($a, $b) {
                        return $b->event->date <=> $a->event->date;
                    });

                    return $speaker;
                },
                $this->redis->hgetall('speakers')
            );

            usort($this->speakers, function ($a, $b) {
                return $a->name > $b->name ? 1 : -1;
            });
        }

        return $this->speakers;
    }

    public function getSpeaker($slug)
    {
        foreach ($this->getSpeakers() as $speaker) {
            if ($speaker->slug == $slug) {
                return $speaker;
            }
        }
    }

    public function getTalk($slug)
    {
        $talks = [];

        foreach ($this->getEvents() as $event) {
            foreach ($event->talks as $talk) {
                $talk->event = $event;
                $talks[] = $talk;
            }
        }

        foreach ($talks as $i => $talk) {
            if ($talk->slug == $slug) {
                $talk->event->talks = array_values(array_filter(
                    $talk->event->talks,
                    function ($other) use ($talk) {
                        return $other->slug != $talk->slug;
                    }
                ));

                $talk->speakers = array_map(
                    function ($speaker) use ($talk) {
                        $speaker = $this->getSpeaker($speaker->slug);

                        $speaker->talks = array_values(array_filter(
                            $speaker->talks,
                            function ($other) use ($talk) {
                                return $other->slug != $talk->slug;
                            }
                        ));

                        return $speaker;
                    },
                    $talk->speakers
                );

                $talk->prev = array_key_exists($i + 1, $talks) ? $talks[$i + 1] : null;
                $talk->next = array_key_exists($i - 1, $talks) ? $talks[$i - 1] : null;

                return $talk;
            }
        }
    }

    public function getTalks()
    {
        if ($this->talks === null) {
            $this->talks = array_map(
                function ($talk) {
                    $talk = json_decode($talk);

                    $talk->event = $this->getEvent($talk->event);

                    return $talk;
                },
                $this->redis->hgetall('talks')
            );
        }

        return $this->talks;
    }

    protected function getEventsFromCache()
    {
        return array_map(
            function ($event) {
                $event = json_decode($event);

                $event->talks = (array) $event->talks;

                return $event;
            },
            $this->redis->hgetall('events')
        );
    }

    protected function getPostsFromCache()
    {
        return array_map(
            function ($post) {
                $post = json_decode($post);

                return $post;
            },
            $this->redis->hgetall('posts')
        );
    }

    protected function crawl($html)
    {
        $crawler = new Crawler;

        $crawler->addHTMLContent($html, 'UTF-8');

        return $crawler;
    }

    protected function parseEvent($event)
    {
        $crawler = $this->crawl($event->description);

        if ($crawler->count()) {
            $crawler = $crawler->children()->first();

            $event->description = $crawler->html();

            $crawler = $crawler
                ->filter('p')
                ->reduce(function (Crawler $node) {
                    return (boolean) preg_match('#^-[^-]#', $node->text());
                })
            ;

            foreach ($crawler as $i => $node) {
                $node = new Crawler($node);

                $talk = (object) [
                    'description' => null
                ];

                $titleNode = $node->filter('b')->first();

                if ($titleNode->count()) {
                    $talk->title = preg_replace('#\s+#u', ' ', $titleNode->html());
                    $talk->slug = $this->slugify(preg_replace('#\s+#u', ' ', $titleNode->text()));

                    if ($event->id == 220161444) {
                        $talk->title = str_replace('Untitled', 'Boost your website by running PHP on Nginx', $talk->title);
                        $talk->slug = str_replace('untitled', 'boost-your-website-by-running-php-on-nginx', $talk->slug);
                    }

                    $talk->id = $event->id . '-' . $talk->slug;
                    $talk->event = $event->id;

                    $nodesAfterTitleNode = $titleNode->nextAll()->filter('a');

                    /* speaker(s) */

                    $nodeHtml = preg_replace('#\s+#u', ' ', $node->html());
                    $titleHtml = preg_replace('#\s+#u', ' ', $titleNode->html());

                    $htmls = trim(str_replace('<br>' , '', preg_replace('#- <b>' . preg_quote($titleHtml) . '</b>#u', '', $nodeHtml)));
                    $htmls = preg_split('# and (?=<a href="https?://(www\.meetup|twitter)\.com)#', $htmls);

                    foreach ($htmls as $html) {
                        try {
                            /* bio start */

                            $speakerNodes = $this->crawl($html);
                            $text = $speakerNodes->text();
                            $speakerAndOrg = explode(',', $text, 2);

                            $speaker = (object) [
                                'id' => $this->slugify($speakerAndOrg[0]),
                                'name' => $speakerAndOrg[0],
                                'slug' => $this->slugify($speakerAndOrg[0])
                            ];

                            $speakerExplode = explode(', ', $html, 2);

                            if (isset($speakerExplode[1])) {
                                $speaker->bio = $speakerExplode[1];
                            }

                            $aliases = ['stu-herbert' => 'Stuart Herbert'];

                            if (isset($aliases[$speaker->id])) {
                                $speaker->name = $aliases[$speaker->id];
                                $speaker->id = $speaker->slug = $this->slugify($speaker->name);
                            }

                            if (strpos($speaker->id, 'dave-liddament')) {
                                $speaker->id = 'dave-liddament';
                                $speaker->bio = 'Director and developer at <a href="https://www.lampbristol.com/">Lamp Bristol</a>.';
                                $speaker->name = 'Dave Liddament';
                                $speaker->slug = 'dave-liddament';
                            }

                            /* end */

                            if ($speakerNodes->count()) {
                                $speaker->url = $speakerNodes->filter('a')->first()->attr('href');

                                if ($speaker->id == 'billie-thompson') {
                                    $speaker->url = str_replace('BillieAThompson', 'PurpleBooth', $speaker->url);
                                } elseif ($speaker->id == 'dave-liddament') {
                                    $speaker->url = 'https://www.meetup.com/php-sw/members/104883962';
                                } elseif ($speaker->id == 'tom-adam') {
                                    $speaker->url = 'https://www.meetup.com/php-sw/members/96221542';
                                }

                                if (preg_match('#https?://www\.meetup\.com/php-sw/members/([^\/]+)#', $speaker->url, $matches)) {
                                    $speaker->member = $this->getMember($matches[1]);

                                    if ($speaker->member->id === null) {
                                        unset($speaker->member); # not in members, meetup only lists so many now
                                    } elseif (isset($speaker->member->photo)) {
                                        $photo = $speaker->photo = $speaker->member->photo;

                                        if (isset($photo->highres_link)) {
                                            $speaker->url = $photo->highres_link;
                                        } elseif (isset($photo->photo_link)) {
                                            $speaker->url = $photo->photo_link;
                                        } elseif (isset($photo->thumb_link)) {
                                            $speaker->url = $photo->thumb_link;
                                        }
                                    }

                                    foreach ((isset($speaker->member->other_services) ? $speaker->member->other_services : []) as $key => $service) {
                                        $speaker->$key = preg_replace('#^@#', '', basename($service->identifier));
                                    }
                                }

                                if (preg_match('#https?://twitter\.com/([^\/]+)#', $speaker->url, $matches)) {
                                    $speaker->twitter = $matches[1];

                                    $speaker->photo = (object) [
                                        'thumb_link' => "https://avatars.phpsw.uk/twitter/{$speaker->twitter}?size=bigger",
                                        'photo_link' => "https://avatars.phpsw.uk/twitter/{$speaker->twitter}?size=original",
                                        'highres_link' => "https://avatars.phpsw.uk/twitter/{$speaker->twitter}?size=original",
                                        'url' => "https://avatars.phpsw.uk/twitter/{$speaker->twitter}?size=original"
                                    ];
                                }
                            }

                            // a twitter avatar meetup:import:graphql couldn't find gets initials of their name
                            if (isset($speaker->photo->url) && strpos($speaker->photo->url, 'avatars.phpsw.uk') && !$this->redis->hget('avatars', strtolower($speaker->twitter))) {
                                unset($speaker->photo);
                            }

                            // e.g. an old talk linking a dead twitter account, use the photo their other talks found
                            if (!isset($speaker->photo) && $known = json_decode($this->redis->hget('speakers', $speaker->slug))) {
                                if (isset($known->photo->url) && strpos($known->photo->url, 'ui-avatars.com') === false) {
                                    $speaker->photo = $known->photo;
                                }
                            }

                            if (!isset($speaker->photo)) {
                                $initials = avatar_url(name: $speaker->name, size: 400);

                                $speaker->photo = (object) [
                                    'thumb_link' => $initials,
                                    'photo_link' => $initials,
                                    'highres_link' => $initials,
                                    'url' => $initials
                                ];
                            }

                            $orgNode = $nodesAfterTitleNode->eq(1);

                            if ($orgNode->count()) {
                                $speaker->organisation = (object) [
                                    'name' => $orgNode->text(),
                                    'url' => $orgNode->attr('href')
                                ];
                            }

                            $talk->speakers[] = $speaker;
                        } catch (\Exception $e) {
                            continue;
                        }
                    }

                    $parsed = false;

                    try {
                        $talkDescriptionNode = $nodesAfterTitleNode->first()->parents()->first()->nextAll()->filter('p');

                        $talkDescriptionNode->each(function ($node) use ($event, $talk, &$parsed) {
                            if (preg_match('#^\s*\-#', $node->text())) $parsed = true; # break if is next speaker or hr
                            if (strpos($node->html(), '<a') !== false) $parsed = true; # break if has links
                            if (preg_match('#BaseKit|Brightpearl|Doors#', $node->text())) $parsed = true; # sad hacks to fix some edge cases

                            if (!$parsed) {
                                $event->description = str_replace('<p>' . $node->html() . '</p>', '', $event->description);
                                $talk->description .= '<p>' . preg_replace('#^\s*<br>#', '', preg_replace('#<br>\s*$#', '', $node->html())) . '</p>' . PHP_EOL;
                            }
                        });
                    } catch (\Exception $e) {
                        continue;
                    }

                    $event->description = str_replace('<p>' . $node->html() . '</p>', '', $event->description);

                    $event->talks[$talk->id] = $talk;
                }
            }

            // strip blank p's
            $event->description = preg_replace('#<p>\s*</p>#u', '', $event->description);

            // strip leading and trailings br's and whitespace in p's
            $event->description = preg_replace(['#<p>\s*(<br>)*#u', '#(<br>)*\s*</p>#u'], ['<p>', '</p>'], $event->description);

            // hr up any dashes
            $event->description = preg_replace('#<p>--</p>#', '<hr>', $event->description);

            // html entityify everything
            $event->description = htmlentities($event->description, ENT_NOQUOTES, 'UTF-8', false);

            // html de-entityify tags
            $event->description = str_replace(['&lt;', '&gt;'], ['<', '>'], $event->description);

            // switch website links for meetup links
            $event->description = preg_replace(
                '#(<a href=")[^"]+(">View on )the PHPSW website(</a>)#',
                sprintf('\1%s\2%s\3', $event->url, 'Meetup'),
                $event->description
            );
        } else {
            // strip crazy </p> descriptions meetup returns
            $event->description = null;
        }

        return $event;
    }

    protected function parseTalks($event)
    {
        // inject talks manually added into redis db
        foreach ($this->redis->hgetall('talks') as $id => $talk) {
            $talk = json_decode($talk);

            if ($event->id == $talk->event && !array_key_exists($talk->id, $event->talks)) {
                $event->talks[$talk->id] = $talk;
            }
        }

        foreach ($event->talks as $talk) {
            $talk->feedback = json_decode($this->redis->hget('feedback', $talk->id));

            $url = $this->redis->hget('slides', $talk->id);

            // slides.com only embeds over https, and slid.es is now slides.com
            $url = preg_replace('#^https?://(slid\.es|slides\.com)/#', 'https://slides.com/', $url);

            if ($url) {
                switch (true) {
                    case !!strpos($url, 'google.com/p'): $type = 'Google Docs';  break;
                    case substr($url, -4) == '.pdf':     $type = 'PDF';          break;
                    case !!strpos($url, 'slides.com'):   $type = 'slides.com';   break;
                    case !!strpos($url, 'slid.es'):      $type = 'slid.es';      break;
                    case !!strpos($url, 'slideshare'):   $type = 'SlideShare';   break;
                    case !!strpos($url, 'speakerdeck'):  $type = 'Speaker Deck'; break;
                    default:                                $type = null;
                }

                // embedly gets a bot challenge from SlideShare now, and so do SlideShare's own embeds
                // without third party cookies, so link to those, and embed Speaker Deck with the
                // iframe url its oEmbed endpoint gives, which meetup:import:graphql stores
                $embed = $type == 'SlideShare' ? null : $this->redis->hget('embeds', $url);

                $embedlyable = !$embed && $type == 'Speaker Deck';
                $iframeable = !$embedlyable && strpos($url, 'https://') === 0 || $embed;

                if ($embed) {
                    $iframe = $embed;
                } elseif ($iframeable) {
                    if (in_array($type, ['Google Docs', 'slides.com', 'slid.es'])) {
                        $iframe = str_replace('/pub', '', $url) . '/embed';
                    } elseif ($type == 'PDF') {
                        $iframe = 'https://mozilla.github.io/pdf.js/web/viewer.html?file=' . $url . '#zoom=page-fit';
                    } else {
                        $iframe = $url;
                    }
                }

                $talk->slides = (object) [
                    'embedly' => $embedlyable ? $url : null,
                    'iframe'  => $iframeable ? $iframe : null,
                    'type'    => $type,
                    'url'     => $url,
                ];
            } else {
                $talk->slides = null;
            }

            $video = $this->redis->hget('videos', $talk->id);

            if (preg_match('#https://www\.youtube\.com/watch\?v=(.*)#', $video, $matches)) {
                $talk->image = "https://i.ytimg.com/vi/{$matches[1]}/maxresdefault.jpg";

                $talk->video = (object) [
                    'embed' => "https://www.youtube.com/embed/{$matches[1]}?rel=0&amp;showinfo=0",
                    'type'  => 'YouTube',
                    'url'   => $video
                ];
            } else {
                $talk->image = null;
                $talk->video = null;
            }
        }

        reset($event->talks);

        return $event;
    }

    public function slugify($string)
    {
        return $this->app['slugify']->slugify(
            preg_replace(['#\'#', '#\s*&\s#'], ['', ' and '], $string)
        );
    }
}
