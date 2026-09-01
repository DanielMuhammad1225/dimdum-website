<?php

namespace App\Services;

/**
 * Structured data halaman wilayah.
 *
 * Prinsipnya satu: HANYA FAKTA YANG ADA DI DATABASE.
 * Tidak ada telepon, jam buka, rating, harga, atau koordinat yang dikarang.
 * Setiap properti dibuang bila sumbernya kosong, sehingga Google tidak
 * pernah menerima field kosong atau nilai tebakan.
 */
class LocationStructuredData
{
    /**
     * Flag encoding aman untuk JSON-LD di dalam <script>.
     *
     * HEX_TAG/AMP/APOS/QUOT membuat '<', '>', '&', kutip satu, dan kutip
     * ganda tercetak sebagai escape unicode, sehingga isi data tidak mungkin
     * menutup tag script atau menyuntik markup.
     */
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT;

    /**
     * @param  array<string, mixed>  $area
     * @param  array<string, mixed>  $brand
     * @return array<string, mixed>
     */
    public function forArea(array $area, array $brand, string $canonical): array
    {
        $items = [];
        $position = 1;

        foreach ($area['locations'] ?? [] as $location) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'item' => $this->foodEstablishment($location, $brand),
            ];
        }

        $graph = [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => $area['headline'],
            'description' => $area['description'],
            'url' => $canonical,
            'numberOfItems' => count($items),
        ];

        if ($items !== []) {
            $graph['itemListElement'] = $items;
        }

        return $graph;
    }

    /**
     * @param  array<string, mixed>  $location
     * @param  array<string, mixed>  $brand
     * @return array<string, mixed>
     */
    protected function foodEstablishment(array $location, array $brand): array
    {
        $entry = [
            '@type' => 'FoodEstablishment',
            'name' => $brand['name'].' - '.$location['name'],
        ];

        $address = $this->postalAddress($location);

        if ($address !== []) {
            $entry['address'] = $address;
        }

        // Koordinat hanya bila keduanya valid -- sudah diperiksa service.
        if (isset($location['latitude'], $location['longitude'])) {
            $entry['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => $location['latitude'],
                'longitude' => $location['longitude'],
            ];
        }

        // hasMap hanya bila URL-nya lolos allowlist Google.
        if (! empty($location['maps_url'])) {
            $entry['hasMap'] = $location['maps_url'];
        }

        /*
         | Jam operasional SENGAJA tidak dimasukkan. Yang tersimpan adalah
         | kalimat bebas ("Setiap hari 09.00-21.00"), sedangkan Schema.org
         | menuntut format terstruktur. Mengubahnya berarti menebak, jadi
         | informasinya cukup tampil di HTML yang terlihat pengunjung.
         |
         | telephone, priceRange, dan aggregateRating juga tidak ada karena
         | datanya memang belum dikumpulkan.
         */

        if (! empty($location['images'])) {
            $entry['image'] = array_values(array_map(
                fn (array $image): string => asset($image['src']),
                array_slice($location['images'], 0, 4),
            ));
        }

        return $entry;
    }

    /**
     * PostalAddress hanya berisi field yang benar-benar terisi.
     *
     * @param  array<string, mixed>  $location
     * @return array<string, mixed>
     */
    protected function postalAddress(array $location): array
    {
        $map = [
            'streetAddress' => $location['full_address'] ?? null,
            'addressLocality' => $location['city_regency'] ?? $location['district'] ?? null,
            'addressRegion' => $location['province'] ?? null,
            'postalCode' => $location['postal_code'] ?? null,
        ];

        $address = [];

        foreach ($map as $key => $value) {
            if (is_string($value) && trim($value) !== '') {
                $address[$key] = trim($value);
            }
        }

        if ($address === []) {
            return [];
        }

        return ['@type' => 'PostalAddress', 'addressCountry' => 'ID', ...$address];
    }
}
