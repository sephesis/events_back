<?php

namespace App\Integration\Services;

use App\Tools\HTTPClient;

class KudaGo
{
    private const API_URL = 'https://kudago.com/public-api/v1.4/';

    private const ENDPOINTS = [
        'events' => 'events/',
    ];

    public static function get()
    {

      $client = new HTTPClient(self::API_URL, 5.0);

      $headers = [
          'Accept' => 'application/json',
      ];

      $query = [
          'fields' => 'location,dates,place,age_restriction,images,short_title,title',
          'expand' => 'location',
          'location' => 'msk',
          'page_size' => 20,
          'order_by' => '-publication_date'
      ];

      $info = $client->get($query, $headers, self::ENDPOINTS['events']);

    //   echo '<pre>';
    //   var_dump(EventFormatter::prepare($info['results']));
    }
}