<?php

namespace PHPSW\Meetup;

/**
 * Meetup's REST API is gone, but its GraphQL API still answers public queries without a key
 */
class Graphql
{
    protected $url = 'https://api.meetup.com/gql-ext';

    public function query($query, array $variables = [])
    {
        $ch = curl_init($this->url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['query' => $query, 'variables' => (object) $variables]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120
        ]);

        $response = json_decode(curl_exec($ch));

        if (!$response || isset($response->errors)) {
            throw new \Exception('Meetup GraphQL error: ' . json_encode($response ? $response->errors : curl_error($ch)));
        }

        return $response->data;
    }

    /**
     * Fetch every node of a connection, $query must take an $after variable and
     * $path picks the connection out of the response, e.g. groupByUrlname.events
     */
    public function all($query, $path, array $variables = [])
    {
        $nodes = [];
        $after = null;

        do {
            $connection = $this->query($query, array_merge($variables, ['after' => $after]));

            foreach (explode('.', $path) as $key) {
                $connection = $connection->$key;
            }

            foreach ($connection->edges as $edge) {
                $nodes[] = $edge;
            }

            $after = $connection->pageInfo->endCursor;
        } while ($connection->pageInfo->hasNextPage);

        return $nodes;
    }
}
