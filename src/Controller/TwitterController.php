<?php

namespace PHPSW\Controller;

use Silex\Application,
    Symfony\Component\HttpFoundation\Request,
    Twitter;

class TwitterController
{
    public function tweetsAction(Request $request, Application $app)
    {
        $twitter = new Twitter(
            $app['twitter']['api']['key'],
            $app['twitter']['api']['secret'],
            $app['twitter']['access_token'],
            $app['twitter']['access_token_secret']
        );

        $tweets = $twitter->load(Twitter::ME);

        $tweets = array_map(
            function ($tweet) {
                // create xhtml safe text (mostly to be safe of ampersands)
                $tweet->html = htmlentities(html_entity_decode($tweet->text, ENT_NOQUOTES, 'UTF-8'), ENT_NOQUOTES, 'UTF-8');

                // for tweets, let's extract the urls from the entities object
                foreach ($tweet->entities->urls as $url) {
                    $old_url        = $url->url;
                    $expanded_url   = (empty($url->expanded_url))   ? $url->url : $url->expanded_url;
                    $display_url    = (empty($url->display_url))    ? $url->url : $url->display_url;
                    $replacement    = '<a href="' . $expanded_url . '" rel="external">' . $display_url . '</a>';
                    $tweet->html    = str_ireplace($old_url, $replacement, $tweet->html);
                }

                // let's extract the hashtags from the entities object
                foreach ($tweet->entities->hashtags as $hashtags) {
                    $hashtag        = '#' . $hashtags->text;
                    $replacement    = '<a href="https://twitter.com/search?q=%23' . $hashtags->text . '" rel="external">' . $hashtag . '</a>';
                    $tweet->html    = str_ireplace($hashtag, $replacement, $tweet->html);
                }

                // let's extract the usernames from the entities object
                foreach ($tweet->entities->user_mentions as $user_mentions) {
                    $username       = '@' . $user_mentions->screen_name;
                    $replacement    = '<a href="https://twitter.com/' . $user_mentions->screen_name . '" rel="external" title="' . $user_mentions->name . ' on Twitter">' . $username . '</a>';
                    $tweet->html    = str_ireplace($username, $replacement, $tweet->html);
                }

                // if we have media attached, let's extract those from the entities as well
                if (isset($tweet->entities->media)) {
                    foreach ($tweet->entities->media as $key => $media) {
                        $media->expanded_url_https = preg_replace('#^http://#', 'https://', $media->expanded_url);

                        $old_url        = $media->url;
                        $replacement    = '<a href="' . $media->expanded_url_https . '" class="twitter-media" data-media="' . $media->media_url_https . '" rel="external">' . $media->display_url . '</a>';
                        $tweet->html    = str_ireplace($old_url, $replacement, $tweet->html);

                        $tweet->entities->media[$key] = $media;
                    }
                }

                $tweet->url = 'https://twitter.com/' . $tweet->user->screen_name . '/status/' . $tweet->id;

                $tweet->created_date = new \DateTime($tweet->created_at);

                return $tweet;
            },
            $tweets
        );

        return $app['twig']->render('twitter/tweets.html.twig', [
            'tweets' => $tweets
        ]);
    }
}
