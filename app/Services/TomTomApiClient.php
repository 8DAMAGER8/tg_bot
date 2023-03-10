<?php

namespace App\Services;

use App\DTO\TomTomDTO;
use Illuminate\Support\Facades\Http;

class TomTomApiClient
{
    const BASE_URL = 'https://api.tomtom.com';
    const API_VERSION = '2';

    const POI_SEARCH_ENDPOINT = '/poiSearch';
    const POI_SEARCH_LIMIT = '15';

    public function __construct(private string $apiKey)
    {
    }

    /**
     * @param TomTomDTO $tomTomDto
     * @return array|mixed
     */
    public function poiSearch(TomTomDTO $tomTomDto): mixed
    {
        $queryString = sprintf(
            '%s/search/%s%s/%s.json?key=%s&relatedpois=all&radius=%s&limit=%s&openingHours=nextSevenDays&language=ru-RU&lat=%s&lon=%s&timezone=iana',
            self::BASE_URL,
            self::API_VERSION,
            self::POI_SEARCH_ENDPOINT,
            $tomTomDto->getSearch(),
            $this->apiKey,
            $tomTomDto->getRadius(),
            self::POI_SEARCH_LIMIT,
            $tomTomDto->getLatitude(),
            $tomTomDto->getLongitude()
        );

        return Http::get($queryString)->json();
    }
}
