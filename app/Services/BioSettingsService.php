<?php

namespace App\Services;

use App\Enums\BioButton;
use App\Enums\BioLocationMode;
use App\Models\BioSetting;
use App\Support\WhatsAppNumber;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Baris singleton Pengaturan Bio dan aturan normalisasinya.
 *
 * Dipakai DUA pihak dengan aturan yang sama persis:
 *   - halaman admin, saat mengisi dan menyimpan form;
 *   - BioCatalogService, saat membangun payload publik.
 *
 * Aturan yang sama dijalankan ulang saat render -- bukan hanya saat validasi
 * form -- supaya baris yang terlanjur berisi nilai aneh (ditulis langsung
 * lewat SQL, atau tersisa dari versi lama) tetap tidak pernah menghasilkan
 * tombol asing, nomor tidak sah, atau mode yang tidak dikenal.
 */
class BioSettingsService
{
    public const MAX_LABEL_LENGTH = 40;

    /**
     * Baris singleton dari database, tanpa cache. Null bila belum ada atau
     * tabelnya belum dibuat.
     */
    public function record(): ?BioSetting
    {
        try {
            return BioSetting::query()->where('key', BioSetting::SINGLETON_KEY)->first();
        } catch (QueryException $exception) {
            if (! $this->isMissingTable($exception)) {
                throw $exception;
            }

            Log::warning('Tabel pengaturan Bio belum tersedia, halaman Bio dianggap nonaktif.');

            return null;
        }
    }

    /**
     * Baris singleton, dibuat bila belum ada.
     *
     * Baris baru SELALU nonaktif: membuka halaman pengaturan tidak boleh
     * sekaligus menerbitkan halaman Bio.
     */
    public function recordOrCreate(): BioSetting
    {
        $record = $this->record();

        if ($record instanceof BioSetting) {
            return $record;
        }

        $record = new BioSetting([
            'is_active' => false,
            'location_mode' => BioLocationMode::AllActiveLocations->value,
            'buttons' => self::defaultButtons(),
        ]);

        // 'key' tidak fillable supaya singleton tidak bisa dipindah lewat form.
        $record->key = BioSetting::SINGLETON_KEY;
        $record->save();

        return $record;
    }

    /**
     * @return list<array{key: string, label: string, visible: bool}>
     */
    public static function defaultButtons(): array
    {
        return array_map(
            fn (BioButton $button): array => [
                'key' => $button->value,
                'label' => $button->defaultLabel(),
                'visible' => true,
            ],
            BioButton::defaultOrder(),
        );
    }

    /**
     * Rapikan daftar tombol menjadi bentuk tunggal yang boleh disimpan.
     *
     *   - Key di luar BioButton dibuang.
     *   - Key kembar hanya dihitung sekali; urutan tetap deterministik.
     *   - Label kosong jatuh ke label bawaan; label dipotong ke batas wajar.
     *   - Tombol yang hilang dari payload ditambahkan kembali di belakang
     *     dalam keadaan TERSEMBUNYI -- payload yang rusak tidak boleh
     *     menghapus tombol selamanya, tetapi juga tidak boleh diam-diam
     *     memunculkannya.
     *
     * Tidak ada URL di sini. Tujuan tombol selalu ditentukan kode.
     *
     * @return list<array{key: string, label: string, visible: bool}>
     */
    public static function normalizeButtons(mixed $buttons): array
    {
        $normalized = [];
        $seen = [];

        foreach (is_array($buttons) ? $buttons : [] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $button = is_string($entry['key'] ?? null) ? BioButton::tryFrom($entry['key']) : null;

            if ($button === null || isset($seen[$button->value])) {
                continue;
            }

            $seen[$button->value] = true;

            $normalized[] = [
                'key' => $button->value,
                'label' => self::cleanLabel($entry['label'] ?? null, $button),
                'visible' => (bool) filter_var($entry['visible'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        foreach (BioButton::defaultOrder() as $button) {
            if (! isset($seen[$button->value])) {
                $normalized[] = [
                    'key' => $button->value,
                    'label' => $button->defaultLabel(),
                    // Payload baru dan kosong = belum pernah disimpan: tampil.
                    // Payload yang ada tetapi kehilangan tombol = sembunyikan.
                    'visible' => $buttons === null,
                ];
            }
        }

        return $normalized;
    }

    protected static function cleanLabel(mixed $label, BioButton $button): string
    {
        $label = is_string($label) ? trim(preg_replace('/\s+/u', ' ', $label) ?? '') : '';

        if ($label === '') {
            return $button->defaultLabel();
        }

        return mb_substr($label, 0, self::MAX_LABEL_LENGTH);
    }

    /**
     * Mode lokasi yang sah. Nilai tidak dikenal jatuh ke mode bawaan.
     */
    public static function resolveLocationMode(mixed $mode): BioLocationMode
    {
        if ($mode instanceof BioLocationMode) {
            return $mode;
        }

        return (is_string($mode) ? BioLocationMode::tryFrom($mode) : null)
            ?? BioLocationMode::AllActiveLocations;
    }

    /**
     * URL chat WhatsApp, atau null bila nomornya bukan nomor seluler
     * Indonesia yang sah. Diperiksa ulang meskipun nilainya sudah
     * dinormalisasi saat disimpan.
     */
    public static function whatsappUrl(mixed $number, mixed $message): ?string
    {
        $digits = WhatsAppNumber::normalizeIndonesian(is_string($number) ? $number : null);

        return WhatsAppNumber::toIndonesianChatUrl($digits, is_string($message) ? $message : null);
    }

    protected function isMissingTable(QueryException $exception): bool
    {
        if (($exception->errorInfo[0] ?? null) === '42S02') {
            return true;
        }

        return str_contains($exception->getMessage(), 'no such table');
    }
}
