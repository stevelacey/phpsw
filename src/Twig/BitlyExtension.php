<?php

namespace PHPSW\Twig;

class BitlyExtension extends \Twig_Extension
{
    public function __construct($api) {
        $this->api = (object) $api;
    }

    public function getFilters()
    {
        return [
            'bitly' => new \Twig_Filter_Method($this, 'bitly')
        ];
    }

    public function bitly($url)
    {
        $ch = curl_init(sprintf('http://api.bitly.com/v3/shorten?login=%s&apiKey=%s&longUrl=%s',
            $this->api->login,
            $this->api->key,
            urlencode($url)
        ));

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = json_decode(curl_exec($ch));

        if (!isset($response->status_code) || $response->status_code != 200) {
            return $url; # bit.ly v3 API is gone, fall back to the long url
        }

        return $response->data->url;
    }

    public function getName()
    {
        return 'bitly';
    }
}
