<?php

namespace PHPSW\Twig;

/**
 * Stands in for a Thumbor URL builder now there's no Thumbor server, resizing and filtering
 * images through wsrv.nl instead
 */
class WsrvUrl
{
    protected $params = [];

    public function __construct($url, ?callable $avatars = null)
    {
        // avatars.phpsw.uk is gone, use the twitter avatars meetup:import:graphql looked up, or initials
        if (preg_match('#^https?://avatars\.phpsw\.uk/twitter/([^?]+)#', $url, $matches)) {
            $url = ($avatars ? $avatars($matches[1]) : null) ?: avatar_url(name: $matches[1], size: 400);
        }

        // meetupstatic and most other image hosts serve https now
        $this->url = preg_replace('#^http://#', 'https://', $url);
    }

    public function resize($width, $height)
    {
        // twice the css size, for retina screens
        $this->params += ['w' => $width, 'h' => $height, 'fit' => 'cover', 'dpr' => 2];

        return $this;
    }

    public function addFilter($filter, ...$args)
    {
        switch ($filter) {
            case 'brightness': $this->params['mod'] = 1 + $args[0] / 100; break;
            case 'contrast':   $this->params['con'] = $args[0]; break;
            case 'grayscale':  $this->params['filt'] = 'greyscale'; break;
        }

        return $this;
    }

    public function __call($method, $args)
    {
        return $this;
    }

    public function __toString()
    {
        // local files and generated svg initials are already the right size
        if (!preg_match('#^https?://#', $this->url) || strpos($this->url, 'ui-avatars.com') !== false) {
            return (string) $this->url;
        }

        return 'https://wsrv.nl/?' . http_build_query(['url' => $this->url] + $this->params);
    }
}
