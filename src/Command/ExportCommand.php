<?php

namespace PHPSW\Command;

use Knp\Command\Command,
    PHPSW\Meetup\Client,
    Symfony\Component\Console\Input\InputArgument,
    Symfony\Component\Console\Input\InputInterface,
    Symfony\Component\Console\Input\InputOption,
    Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes everything worth keeping, events, talks, speakers, slides and videos, to one JSON file
 */
class ExportCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('export:json')
            ->addArgument('path', InputArgument::OPTIONAL, 'Where to write it, relative to the project', 'web/export.json')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'The site\'s url, for links to its pages', 'https://phpsw.steve.ly')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $app = $this->getSilexApplication();

        $this->url = rtrim($input->getOption('url'), '/');

        // reads events from redis and parses talks out of them, as pages do outside prod
        $meetup = new Client($app, $app['meetup']);

        $events = array_values(array_reverse($meetup->getEvents()));
        $group = $meetup->getGroup();

        $talks = [];

        foreach ($events as $event) {
            foreach ($event->talks as $talk) {
                $talks[] = [
                    'id' => $talk->id,
                    'url' => $this->url . '/talks/' . $talk->slug,
                    'title' => html_entity_decode(strip_tags($talk->title)),
                    'event' => $event->id,
                    'date' => $event->date->format(\DateTime::ATOM),
                    'description' => $this->html(isset($talk->description) ? $talk->description : null),
                    'speakers' => array_map(function ($speaker) {
                        return $speaker->slug;
                    }, isset($talk->speakers) ? $talk->speakers : []),
                    'slides' => $talk->slides ? [
                        'url' => $talk->slides->url,
                        'type' => $talk->slides->type,
                        'embed' => $talk->slides->iframe
                    ] : null,
                    'image' => isset($talk->image) ? $talk->image : null,
                    'video' => $talk->video ? [
                        'url' => $talk->video->url,
                        'embed' => html_entity_decode($talk->video->embed)
                    ] : null,
                    'feedback' => isset($talk->feedback->uri) ? $talk->feedback->uri : null
                ];
            }
        }

        $speakers = array_map(function ($speaker) {
            $photo = isset($speaker->photo->url) ? $speaker->photo->url : null;

            return [
                'slug' => $speaker->slug,
                'url' => $this->url . '/speakers/' . $speaker->slug,
                'name' => $speaker->name,
                'bio' => $this->html(isset($speaker->bio) ? $speaker->bio : null),
                'twitter' => isset($speaker->twitter) ? $speaker->twitter : null,
                'meetup' => isset($speaker->member->id) ? 'https://www.meetup.com/members/' . $speaker->member->id . '/' : null,
                'organisation' => isset($speaker->organisation) ? $speaker->organisation : null,
                'photo' => $photo && strpos($photo, 'ui-avatars.com') === false ? $this->absolute($photo) : null,
                'photos' => $this->photos(isset($speaker->photos) ? $speaker->photos : []),
                'talks' => array_values(array_map(function ($talk) {
                    return $talk->id;
                }, $speaker->talks))
            ];
        }, $meetup->getSpeakers());

        $export = [
            'name' => $app['title'],
            'url' => $this->url,
            'export' => $this->url . '/export.json',
            'exported' => date(\DateTime::ATOM),
            'group' => [
                'meetup' => $app['meetup']['url'],
                'members' => isset($group->members) ? $group->members : null,
                'rating' => isset($group->rating) ? $group->rating : null,
                'photos' => $this->photos(array_map('json_decode', $app['redis']->hgetall('photos')))
            ],
            'events' => array_map(function ($event) {
                return [
                    'id' => $event->id,
                    'name' => $event->name,
                    'date' => $event->date->format(\DateTime::ATOM),
                    'status' => $event->status,
                    'url' => $this->url . '/events/' . $event->id . '-' . $event->slug,
                    'meetup' => $event->url,
                    'description' => $this->html($event->description),
                    'venue' => $event->venue ? [
                        'name' => $event->venue->name,
                        'address' => isset($event->venue->address_1) ? trim($event->venue->address_1) : null,
                        'city' => isset($event->venue->city) ? $event->venue->city : null,
                        'lat' => isset($event->venue->lat) ? $event->venue->lat : null,
                        'lon' => isset($event->venue->lon) ? $event->venue->lon : null
                    ] : null,
                    'attendees' => isset($event->yes_rsvp_count) ? $event->yes_rsvp_count : null,
                    'photos' => $this->photos(isset($event->photos) ? $event->photos : []),
                    'comments' => $this->comments(isset($event->comments) ? $event->comments : []),
                    'talks' => array_values(array_map(function ($talk) {
                        return $talk->id;
                    }, $event->talks)),
                    'feedback' => isset($event->feedback->uri) ? $event->feedback->uri : null
                ];
            }, $events),
            'talks' => $talks,
            'speakers' => $speakers,
            'sponsors' => array_map(function ($sponsors) {
                return array_map(function ($sponsor) {
                    $sponsor['logo'] = $this->absolute($sponsor['logo']);
                    unset($sponsor['contact']);

                    return $sponsor;
                }, $sponsors);
            }, $app['sponsors'])
        ];

        $path = realpath($app['console.project_directory']) . '/' . $input->getArgument('path');

        file_put_contents($path, json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $output->writeln(sprintf('%d events, %d talks, %d speakers to %s', count($export['events']), count($talks), count($speakers), $path));
    }

    protected function photos($photos)
    {
        return array_values(array_filter(array_map(function ($photo) {
            foreach (['highres_link', 'photo_link', 'thumb_link'] as $link) {
                if (isset($photo->$link)) return $this->absolute($photo->$link);
            }
        }, (array) $photos)));
    }

    protected function comments($comments)
    {
        return array_map(function ($comment) {
            return [
                'author' => isset($comment->member->name) ? $comment->member->name : null,
                'text' => isset($comment->comment) ? $comment->comment : null,
                'date' => isset($comment->date) ? $comment->date->format(\DateTime::ATOM) : null,
                'replies' => $this->comments(isset($comment->replies) ? $comment->replies : [])
            ];
        }, array_values((array) $comments));
    }

    protected function absolute($url)
    {
        $url = preg_replace('#^http://(photos\d\.|img\d\.|secure\.)?meetupstatic\.com/#', 'https://$1meetupstatic.com/', $url);

        return strpos($url, '/') === 0 && strpos($url, '//') !== 0 ? $this->url . $url : $url;
    }

    protected function html($html)
    {
        $html = trim(html_entity_decode((string) $html, ENT_NOQUOTES, 'UTF-8'));

        return $html === '' ? null : $html;
    }
}
