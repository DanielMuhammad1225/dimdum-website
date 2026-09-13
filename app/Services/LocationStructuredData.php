<?php

namespace App\Services;

/**
 * Structured data Halaman Slug Lokasi.
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
     * Halaman Slug Lokasi: daftar gerobak sebagai FoodEstablishment.
     *
     * TIDAK ada BreadcrumbList. Halaman ini dibuka dari Bio dan navigasinya
     * hanya tombol Kembali ke /bio -- tanpa remah roti dan tanpa tautan ke
     * Beranda -- sehingga breadcrumb di structured data tidak lagi sesuai
     * dengan yang terlihat pengunjung.
     *
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $brand
     * @return array<string, mixed>
     */
    public function forPage(array $page, array $brand, string $canonical): array
    {
        $items = [];
        $position = 1;

        foreach ($page['locations'] ?? [] as $location) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'item' => $this->foodEstablishment($location, $brand, $location['province_name'] ?? null),
            ];
        }

        $list = [
            '@type' => 'ItemList',
            'name' => $page['seo_title'],
            'description' => $page['seo_description'],
            'url' => $canonical,
            'numberOfItems' => count($items),
        ];

        if ($items !== []) {
            $list['itemListElement'] = $items;
        }

        return $this->graph([$list]);
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
