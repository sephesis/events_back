<?php

namespace App\Services;

use App\Tools\HTTPClient;

class KudaGo
{
  private const API_URL = 'https://kudago.com/public-api/v1.4/';

  private const ENDPOINTS = [
    'events' => 'events/',
    'categories' => 'event-categories/',
  ];

  public static function getEvents(string $city = 'msk', string $categories, array $params = []): array
  {
    $client = new HTTPClient(self::API_URL, 5.0);

    $headers = [
      'Accept' => 'application/json',
    ];

    $fields = ['id', 'tagline', 'location', 'description', 'place', 'title', 'categories'];

    if ($params) {
      $fields = array_merge($params, $fields);
    }

    $query = [
      'fields' => implode(',', $fields),
      // 'location' alone leaves "place" as a bare {"id": ...} stub with no
      // coordinates — we need "place" expanded too to get the venue's coords.
      'expand' => 'location,place',
      'location' => $city,
      'page_size' => 20,
      'lang' => 'ru',
    ];

    if ($categories) {
      $query['categories'] = $categories;
    }

    $info = $client->get($query, $headers, self::ENDPOINTS['events']);

    return $info ?? [];
  }

  public static function getCategories($lang = 'ru')
  {
    $client = new HTTPClient(self::API_URL, 5.0);

    $headers = [
      'Accept' => 'application/json',
    ];

    $query = [
      'lang' => $lang,
    ];

    $cats = $client->get($query, $headers, self::ENDPOINTS['categories']);

    return $cats ?? [];
  }
}
