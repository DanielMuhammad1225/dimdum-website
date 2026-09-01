<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Pengelola urutan (sort_order) hierarki lokasi.
 *
 * Urutan TIDAK PERNAH diminta dari pengguna. Saat record dibuat, sistem
 * menempatkannya di posisi terakhir dalam scope induknya; sesudah itu urutan
 * hanya diubah lewat drag-and-drop pada tabel.
 *
 * Alasannya bukan sekadar kenyamanan: nomor urut yang diketik manual berarti
 * request dapat menyisipkan angka apa pun, termasuk oleh Operator yang tidak
 * berwenang menata urutan. Dengan menghitungnya di server, nilai dari request
 * tidak pernah dipercaya.
 *
 * Setiap scope berdiri sendiri:
 *   Province        -> global
 *   LocationGroup   -> di dalam satu province_id
 *   LocationArea    -> di dalam satu location_group_id
 *   Location        -> di dalam satu location_area_id
 *   LocationImage   -> di dalam satu location_id
 *
 * Urutan global yang mencampur anak dari induk berbeda tidak pernah dibuat.
 */
class LocationOrderingService
{
    /**
     * Posisi berikutnya di dalam satu scope: maksimum + 1.
     *
     * Record terhapus (soft delete) IKUT dihitung supaya pemulihan tidak
     * menabrak nomor yang sudah dipakai record lain.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $scope
     */
    public function nextPosition(string $modelClass, array $scope): int
    {
        $query = $modelClass::query();

        if (method_exists($modelClass, 'bootSoftDeletes')) {
            $query->withTrashed();
        }

        foreach ($scope as $column => $value) {
            $query->where($column, $value);
        }

        /*
         | lockForUpdate menahan baris scope ini sampai transaction selesai,
         | sehingga dua create bersamaan tidak membaca maksimum yang sama dan
         | menghasilkan dua record bernomor kembar.
         |
         | SQLite tidak mengenal SELECT ... FOR UPDATE; driver Laravel
         | mengabaikannya dengan aman, dan pengujian tetap berjalan.
         */
        $max = (int) $query->lockForUpdate()->max('sort_order');

        return $max + 1;
    }

    /**
     * Tetapkan posisi terakhir pada record baru, di dalam satu transaction.
     *
     * @param  array<string, mixed>  $scope
     */
    public function assignLastPosition(Model $model, array $scope): void
    {
        DB::transaction(function () use ($model, $scope): void {
            $model->sort_order = $this->nextPosition($model::class, $scope);
            $model->save();
        });
    }

    /**
     * Jadikan urutan sebuah scope menjadi 1, 2, 3, ... tanpa celah maupun
     * duplikat.
     *
     * Ditulis lewat query builder, bukan save() per model, supaya normalisasi
     * satu scope tidak memicu puluhan event dan puluhan kenaikan versi cache.
     * Cache dinaikkan sekali di akhir oleh pemanggil.
     *
     * @param  class-string<Model>  $modelClass
     * @param  array<string, mixed>  $scope
     * @return int jumlah baris yang posisinya benar-benar berubah
     */
    public function normalize(string $modelClass, array $scope): int
    {
        $model = new $modelClass;
        $table = $model->getTable();
        $keyName = $model->getKeyName();

        return DB::transaction(function () use ($modelClass, $scope, $table, $keyName): int {
            $query = $modelClass::query();

            if (method_exists($modelClass, 'bootSoftDeletes')) {
                $query->withTrashed();
            }

            foreach ($scope as $column => $value) {
                $query->where($column, $value);
            }

            $rows = $query
                ->orderBy('sort_order')
                ->orderBy($keyName)
                ->lockForUpdate()
                ->get([$keyName, 'sort_order']);

            $position = 1;
            $changed = 0;

            foreach ($rows as $row) {
                if ((int) $row->sort_order !== $position) {
                    DB::table($table)
                        ->where($keyName, $row->getKey())
                        ->update(['sort_order' => $position]);
                    $changed++;
                }

                $position++;
            }

            return $changed;
        });
    }

    /**
     * Pindahkan record ke induk baru: tempatkan di urutan terakhir induk
     * tujuan, lalu rapikan urutan kedua scope.
     *
     * Seluruhnya berjalan dalam satu transaction supaya tidak pernah ada
     * kondisi di mana record sudah pindah tetapi urutannya belum benar.
     *
     * @param  array<string, mixed>  $previousScope
     * @param  array<string, mixed>  $newScope
     */
    public function moveToScope(Model $model, array $previousScope, array $newScope): void
    {
        DB::transaction(function () use ($model, $previousScope, $newScope): void {
            $model->sort_order = $this->nextPosition($model::class, $newScope);
            $model->save();

            $this->normalize($model::class, $previousScope);
            $this->normalize($model::class, $newScope);
        });

        LocationPageCatalogService::flushCache();
    }
}
