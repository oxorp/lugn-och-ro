<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OverpassService
{
    private string $endpoint = 'https://overpass-api.de/api/interpreter';

    private int $timeout = 180;

    private int $maxRetries = 3;

    /**
     * Query OSM data for Sweden by tags.
     *
     * @param  array<string, list<string>>  $tags  e.g. ['shop' => ['supermarket', 'convenience']]
     * @return Collection<int, array{lat: float, lng: float, osm_id: int, osm_type: string, name: ?string, tags: array}>
     */
    public function querySweden(array $tags): Collection
    {
        $tagFilters = $this->buildTagFilters($tags);

        $query = "[out:json][timeout:{$this->timeout}];
            area['ISO3166-1'='SE']->.sweden;
            (
                {$tagFilters}
            );
            out center;";

        Log::info('Overpass query', ['query_length' => strlen($query), 'tags' => $tags]);

        $response = null;
        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            $response = Http::timeout($this->timeout + 30)
                ->asForm()
                ->post($this->endpoint, ['data' => $query]);

            if ($response->successful()) {
                break;
            }

            if ($attempt < $this->maxRetries && in_array($response->status(), [429, 504])) {
                $waitSeconds = $attempt * 30;
                Log::warning("Overpass returned {$response->status()}, retrying in {$waitSeconds}s (attempt {$attempt}/{$this->maxRetries})");
                sleep($waitSeconds);

                continue;
            }

            throw new \RuntimeException('Overpass query failed: '.$response->status());
        }

        $elements = $response->json('elements', []);

        Log::info('Overpass response', ['elements' => count($elements)]);

        return collect($elements)->map(fn (array $el) => [
            'lat' => $el['lat'] ?? $el['center']['lat'] ?? null,
            'lng' => $el['lon'] ?? $el['center']['lon'] ?? null,
            'osm_id' => $el['id'],
            'osm_type' => $el['type'],
            'name' => $el['tags']['name'] ?? null,
            'tags' => $el['tags'] ?? [],
        ])->filter(fn (array $el) => $el['lat'] !== null && $el['lng'] !== null)->values();
    }

    /**
     * Build Overpass tag filter strings from an associative array.
     *
     * @param  array<string, list<string>>  $tags
     */
    private function buildTagFilters(array $tags): string
    {
        $filters = [];
        foreach ($tags as $key => $values) {
            $valueRegex = implode('|', $values);
            $filters[] = "nwr[\"{$key}\"~\"{$valueRegex}\"](area.sweden);";
        }

        return implode("\n", $filters);
    }
}
