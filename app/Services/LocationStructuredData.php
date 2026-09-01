<?php

namespace App\Services;

/**
 * Structured data halaman lokasi.
 *
 * Prinsipnya satu: HANYA FAKTA YANG ADA DI DATABASE.
 * Tidak ada telepon, jam buka, rating, harga, atau koordinat yang dikarang.
 * Setiap properti dibuang bila sumbernya kosong, sehingga Google tidak
 * pernah menerima field kosong atau nilai tebakan.
 *
 * Alamat pos SELALU dibangun dari field gerobak, TIDAK PERNAH dari nama
 * induk hierarki. Satu Kota/Grup bertipe pemasaran boleh mencakup beberapa
 * kota/kabupaten, jadi menyusun alamat dari nama grup akan menghasilkan
 * alamat yang salah tepat pada kasus perbatasan.
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
     * Indeks lokasi: daftar provinsi + breadcrumb.
     *
     * @param  list<array<string, mixed>>  $provinces
     * @return array<string, mixed>
     */
    public function forIndex(array $provinces, string $name, string $description, string $canonical): array
    {
        $items = [];
        $position = 1;

        foreach ($provinces as $province) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $province['name'],
                'item' => $province['url'],
            ];
        }

        $list = [
            '@type' => 'ItemList',
            'name' => $name,
            'description' => $description,
            'url' => $canonical,
            'numberOfItems' => count($items),
        ];

        if ($items !== []) {
            $list['itemListElement'] = $items;
        }

        return $this->graph([
            $this->breadcrumbs([['Lokasi Gerobak DIMDUM', $canonical]]),
            $list,
        ]);
    }

    /**
     * Halaman provinsi: daftar Area (menembus grup) + breadcrumb.
     *
     * Kota/Grup TIDAK muncul di breadcrumb karena ia tidak punya URL sendiri;
     * mencantumkannya tanpa tautan hanya akan membingungkan mesin pencari.
     *
     * @param  array<string, mixed>  $province
     * @return array<string, mixed>
     */
    public function forProvince(array $province, string $canonical): array
    {
        $items = [];
        $position = 1;

        foreach ($province['groups'] ?? [] as $group) {
            foreach ($group['areas'] as $area) {
                $items[] = [
                    '@type' => 'ListItem',
                    'position' => $position++,
                    'name' => $area['name'],
                    'item' => $area['url'],
                ];
            }
        }

        $list = [
            '@type' => 'ItemList',
            'name' => $province['headline'],
            'description' => $province['description'],
            'url' => $canonical,
            'numberOfItems' => count($items),
        ];

        if ($items !== []) {
            $list['itemListElement'] = $items;
        }

        return $this->graph([
            $this->breadcrumbs([
                ['Lokasi Gerobak DIMDUM', route('locations.index')],
                [$province['name'], $canonical],
            ]),
            $list,
        ]);
    }

    /**
     * Halaman Area: daftar gerobak sebagai FoodEstablishment + breadcrumb.
     *
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
                'item' => $this->foodEstablishment($location, $brand, $area['province_name'] ?? null),
            ];
        }

        $list = [
            '@type' => 'ItemList',
            'name' => $area['headline'],
            'description' => $area['description'],
            'url' => $canonical,
            'numberOfItems' => count($items),
        ];

        if ($items !== []) {
            $list['itemListElement'] = $items;
        }

        return $this->graph([
            $this->breadcrumbs([
                ['Lokasi Gerobak DIMDUM', route('locations.index')],
                [$area['province_name'], $area['province_url']],
                [$area['name'], $canonical],
            ]),
            $list,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, mixed>
     */
    protected function graph(array $nodes): array
    {
        return [
            '@context' => 'https://schema.org',
            '@graph' => array_values($nodes),
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $trail
     * @return array<string, mixed>
     */
    protected function breadcrumbs(array $trail): array
    {
        $items = [];
        $position = 1;

        foreach ($trail as [$name, $url]) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $name,
                'item' => $url,
            ];
        }

        return [
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }

    /**
     * @param  array<string, mixed>  $location
     * @param  array<string, mixed>  $brand
     * @return array<string, mixed>
     */
    protected function foodEstablishment(array $location, array $brand, ?string $provinceName = null): array
    {
        $entry = [
            '@type' => 'FoodEstablishment',
            'name' => $brand['name'].' - '.$location['name'],
        ];

        $address = $this->postalAddress($location, $provinceName);

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
     * streetAddress, addressLocality, dan postalCode SELALU berasal dari
     * field alamat gerobak -- tidak pernah dari nama induk. Kota/Grup bertipe
     * pemasaran boleh mencakup beberapa kota/kabupaten, jadi menyusun kota
     * dari nama grup akan salah tepat pada kasus perbatasan.
     *
     * addressRegion adalah satu-satunya pengecualian, dan hanya karena
     * strukturnya menjaminnya: sebuah Kota/Grup wajib berada di tepat satu
     * Provinsi (divalidasi server-side), sehingga provinsi induk sebuah
     * gerobak SELALU merupakan provinsi administratif yang benar. Nilainya
     * tetap dibuang bila induknya tidak diketahui.
     *
     * @param  array<string, mixed>  $location
     * @return array<string, mixed>
     */
    protected function postalAddress(array $location, ?string $provinceName = null): array
    {
        $map = [
            'streetAddress' => $location['full_address'] ?? null,
            'addressLocality' => $location['city_regency'] ?? $location['district'] ?? null,
            'addressRegion' => $provinceName,
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
