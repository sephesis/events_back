<?php

namespace App\Tools;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class HTTPClient
{

    private $client = [];

    public function __construct($url, $timeout = 2.0)
    {
        $this->client = new Client(['base_uri' => $url, 'timeout' => $timeout]);
    }

    public function get($query, $headers = [], $endpoint = ''): array
    {
        if ($this->client instanceof Client) {
            $response = $this->client->request(
                'GET',
                $endpoint,
                [
                    'headers' => $headers,
                    'query' => $query,
                ]
            );
            $body = $response->getBody();
            $data = json_decode($body, true);
            return $data;
        }

        return [];
    }

    public function post(array $data, $endpoint = '', $headers = []): array
    {
        if ($this->client instanceof Client) {
            $response = $this->client->request('POST', $endpoint, [
                'json' => $data,
                'headers' => $headers,
            ],);
            $body = $response->getBody();
            $data = json_decode($body, true);
            return $data;
        }

        return [];
    }
}