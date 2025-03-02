<?php

namespace App\Services;

use App\Tools\HTTPClient;

class Timepad
{
    private const TOKEN = '7784e77912482f252d49f01394472bfeb4df9345';

    private const API_URL = 'https://api.timepad.ru/v1/';

    private const ENDPOINTS = [
        'events' => 'events.json',
    ];


    public static function getEvents($location = 'Москва')
    {
        $client = new HTTPClient(self::API_URL, 5.0);

        $headers = [
            'Authorization' => 'Bearer ' . self::TOKEN,
            'Accept'        => 'application/json',
        ];

        $query = [
            'cities' => $location,
            'fields' => 'location',
            'sort' => '+starts_at'
        ];

        $info = $client->get($query, $headers, self::ENDPOINTS['events']);
    
        return $info ?? [];
    }
}