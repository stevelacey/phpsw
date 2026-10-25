<?php

namespace PHPSW\Meetup;

/**
 * Meetup now returns event descriptions as plain text/markdown, with links flattened to
 * "text (url)", this turns them back into the html the old API returned, so that
 * Client::parseEvent can pick out talks and speakers like it used to, i.e.
 *
 *   <p>- <b>Talk title</b><br/> <a href="speaker url">Speaker</a>, Job at <a href="org url">Org</a></p>
 */
class Description
{
    const URL = 'https?://[^\s()<>]+(?:\([^\s()<>]*\))?[^\s()<>.,;:!?\'"]*';

    public static function toHtml($text)
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", (string) $text));

        if ($text === '') return null;

        // markdown escapes, e.g. \- \* \~
        $text = preg_replace('#\\\\([\\\\`*_{}\[\]()\#+\-.!~>|])#', '$1', $text);

        $paragraphs = preg_split('#\n\s*\n#', $text);

        // "- Title" on its own, followed by the speaker line in the next paragraph
        for ($i = 0; $i < count($paragraphs) - 1; $i++) {
            if (self::isTalk($paragraphs[$i]) && strpos($paragraphs[$i], "\n") === false) {
                $paragraphs[$i] .= "\n" . $paragraphs[$i + 1];
                array_splice($paragraphs, $i + 1, 1);
            }
        }

        $html = array_map(function ($paragraph) {
            $lines = explode("\n", $paragraph);

            if (self::isTalk($paragraph)) {
                $title = trim(preg_replace('#^-\s*#', '', array_shift($lines)));
                $title = '- <b>' . self::inline($title, false) . '</b>';

                if ($lines) {
                    $lines[0] = self::inline($lines[0], true);
                }

                foreach (array_slice($lines, 1, null, true) as $i => $line) {
                    $lines[$i + 1] = self::inline($line, false);
                }

                array_unshift($lines, $title);
            } else {
                $lines = array_map(function ($line) {
                    return self::inline(preg_replace('#^\#+\s*#', '', $line), false);
                }, $lines);
            }

            return '<p>' . implode('<br/>' . PHP_EOL, $lines) . '</p>';
        }, $paragraphs);

        return implode(' ', $html);
    }

    protected static function isTalk($paragraph)
    {
        return (bool) preg_match('#^-\s*[^-\s]#', $paragraph);
    }

    /**
     * Escape a line and turn its links back into anchors, on a speaker line the first
     * link's text is everything before it, e.g. "Jane Doe (https://twitter.com/jd), ..."
     */
    protected static function inline($line, $speaker)
    {
        $anchors = [];

        $anchor = function ($text, $url) use (&$anchors) {
            $key = "\0" . count($anchors) . "\0";
            $anchors[$key] = sprintf('<a href="%s">%s</a>', htmlspecialchars(self::normaliseUrl($url)), htmlspecialchars(trim($text), ENT_NOQUOTES));

            return $key;
        };

        // markdown links, [text](url)
        $line = preg_replace_callback('#\[([^\]]+)\]\((' . self::URL . ')\)#', function ($m) use ($anchor) {
            return $anchor($m[1], $m[2]);
        }, $line);

        // "text (url)"
        $line = preg_replace_callback('#(^|.*?)\s*\((' . self::URL . ')\)#', function ($m) use ($anchor, &$speaker) {
            $before = $m[1];

            if ($speaker && strpos($before, "\0") === false && strpos($before, ',') === false || preg_match('#^View on #', $before)) {
                $speaker = false;

                return $anchor($before, $m[2]);
            }

            $speaker = false;

            // link text is the run of capitalised words (and little joining words) before the url
            if (preg_match('#^(.*?)((?:[\p{Lu}\d@][^\s]*)(?:\s+(?:for|the|de|&|–|-|[\p{Lu}\d][^\s]*))*)$#u', $before, $words) && $words[2] !== '') {
                return $words[1] . $anchor($words[2], $m[2]);
            }

            if (preg_match('#^(.*?)(\S+)$#u', $before, $words)) {
                return $words[1] . $anchor($words[2], $m[2]);
            }

            return $before . $anchor($m[2], $m[2]);
        }, $line);

        // bare urls
        $line = preg_replace_callback('#(?<![">])\b' . self::URL . '#', function ($m) use ($anchor) {
            return $anchor($m[0], $m[0]);
        }, $line);

        $line = htmlspecialchars($line, ENT_NOQUOTES);
        $line = preg_replace('#\*\*(.+?)\*\*#', '<b>$1</b>', $line);

        return strtr($line, $anchors);
    }

    /**
     * Point speaker links at the urls Client::parseEvent recognises
     */
    protected static function normaliseUrl($url)
    {
        $url = preg_replace('#^https?://(www\.)?x\.com/#', 'https://twitter.com/', $url);
        $url = preg_replace('#^https?://(www\.)?twitter\.com/#', 'https://twitter.com/', $url);
        $url = preg_replace('#^https?://(www\.)?meetup\.com/(php-sw/)?members/(\d+)/?.*$#', 'http://www.meetup.com/php-sw/members/$3', $url);

        return $url;
    }
}
