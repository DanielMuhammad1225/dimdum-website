<?php

namespace App\Filament\Resources\SocialLinks\Schemas;

use App\Enums\SocialIcon;
use App\Rules\SafeLink;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Form tautan social media.
 *
 * Tidak ada input HTML maupun SVG. Ikon DIPILIH dari allowlist SocialIcon,
 * dan markupnya hidup di kode. Tidak ada pula input urutan: tautan baru
 * ditempatkan terakhir dan urutannya diatur dengan seret-dan-lepas di tabel.
 */
class SocialLinkForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Tautan')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nama')
                            ->helperText('Teks yang tampil di tombol, misalnya "Instagram" atau "@dimdum.id".')
                            ->required()
                            ->maxLength(60),

                        Select::make('icon')
                            ->label('Ikon')
                            ->helperText('Pilih "Tautan umum" untuk platform yang belum punya ikon sendiri.')
                            ->options(SocialIcon::options())
                            ->default(SocialIcon::Link->value)
                            ->required()
                            ->native(false)
                            // Nilai di luar allowlist ditolak server-side.
                            ->in(SocialIcon::values()),

                        TextInput::make('url')
                            ->label('URL')
                            ->placeholder('https://www.instagram.com/...')
                            ->helperText('Diawali http:// atau https://. Tautan dibuka di tab baru.')
                            ->required()
                            ->maxLength(2048)
                            // Userinfo, backslash, karakter kontrol, dan skema
                            // berbahaya (javascript:, data:, ...) ditolak.
                            ->rule(SafeLink::web())
                            ->columnSpanFull(),

                        Toggle::make('is_active')
                            ->label('Aktif')
                            ->helperText('Tautan nonaktif tidak tampil di halaman Bio, tetapi tetap tersimpan.')
                            ->default(true),
                    ]),
            ]);
    }
}
