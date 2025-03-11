<?php

namespace App\Services;

use App\Tools\HTTPClient;

class KudaGo
{
  private const API_URL = 'https://kudago.com/public-api/v1.4/';

  private const ENDPOINTS = [
    'events' => 'events/',
  ];

  public static function getEvents(string $city = 'msk', array $params = [], array $placeIds = []): array
  {
    $client = new HTTPClient(self::API_URL, 5.0);

    $headers = [
      'Accept' => 'application/json',
    ];

    $fields = ['id', 'location', 'description', 'place', 'title', 'categories'];

    if ($params) {
      $fields = array_merge($params, $fields);
    }

    $query = [
      'fields' => implode(',', $fields),
      'expand' => 'location',
      'location' => $city,
      'page_size' => 20,
      'lang' => 'ru',
    ];

    if ($placeIds) {
      $query['place_id'] = implode(',', $placeIds);
    }

  
    $info = $client->get($query, $headers, self::ENDPOINTS['events']);
   
    return $info ?? [];
  }
}
