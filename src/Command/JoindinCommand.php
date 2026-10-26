<?php

namespace PHPSW\Command;

use Knp\Command\Command,
    Symfony\Component\Console\Input\InputArgument,
    Symfony\Component\Console\Input\InputInterface,
    Symfony\Component\Console\Input\InputOption,
    Symfony\Component\Console\Output\OutputInterface;

class JoindinCommand extends Command
{
    protected function configure()
    {
        $this->setName('joindin:import:all');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $app = $this->getSilexApplication();

        $this->joindin = $app['joindin.client'];
        $this->meetup  = $app['meetup.client'];
        $this->redis   = $app['redis'];

        $tasks = [
            'feedback' => function ($callback) {
                $events = $this->joindin->get('https://api.joind.in/v2.1/events', ['tags' => 'phpsw'])->events;

                // most joind.in events link to phpsw.uk without the meetup id, so fall back to the date
                $dates = [];

                foreach ($this->redis->hgetall('events') as $id => $meetup) {
                    $dates[date('Y-m-d', json_decode($meetup)->time / 1000)] = $id;
                }

                foreach ($events as $event) {
                    if (preg_match('#/(\d+)[-/]#', $event->href, $matches)) {
                        $eventId = $matches[1];
                    } elseif (isset($dates[substr($event->start_date, 0, 10)])) {
                        $eventId = $dates[substr($event->start_date, 0, 10)];
                    } else {
                        continue;
                    }

                    // joind.in sometimes fails a request, skip that event's talks rather than the whole import
                    $comments = $this->joindin->get($event->all_talk_comments_uri)->comments ?? null;
                    $talks = $this->joindin->get($event->talks_uri)->talks ?? null;

                    if ($comments === null || $talks === null) {
                        continue;
                    }

                    foreach ($talks as $talk) {
                        $id = $eventId . '-' . $this->meetup->slugify($talk->talk_title);

                        $this->redis->hset('feedback', $id, json_encode((object) [
                            'comments' => array_filter(
                                $comments,
                                function ($comment) use ($talk) {
                                    return $comment->talk_uri == $talk->uri;
                                }
                            ),
                            'duration' => $talk->duration,
                            'rating'   => $talk->average_rating,
                            'starred'  => $talk->starred_count,
                            'uri'      => str_replace('/talk/view', '', $talk->website_uri)
                        ]));

                        $callback();
                    }
                }
            }
        ];

        foreach ($tasks as $type => $task) {
            echo ucfirst($type), ': ';

            $task(function () {
                echo '.';
            });

            echo PHP_EOL;
        }

        // talks show their feedback, so drop the parsed events the site caches
        $this->redis->del('cache:events');
    }

}
