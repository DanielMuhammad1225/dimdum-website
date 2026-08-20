<?php

/*
|--------------------------------------------------------------------------
| Builder aset brand DIMDUM (non-destruktif)
|--------------------------------------------------------------------------
| Menghasilkan turunan aset web dari file master di
| storage/app/private/brand-masters/. File master TIDAK PERNAH diubah.
|
| Sumber: "LOGO VARIASI DIMDUM.png" (1254x1254) adalah lembar variasi logo
| berisi tiga lockup resmi tanpa tagline:
|   - Versi utama (primary lockup)
|   - Versi horizontal
|   - Versi kompak / icon
|
| Script hanya MEMOTONG region lembar tersebut dan membuat background cream
| lembar menjadi transparan lewat flood fill dari tepi. Tidak ada perubahan
| bentuk, warna, proporsi, maupun teks pada logo.
|
| Jalankan: php tools/build-brand-assets.php
*/

$root = dirname(__DIR__);
$master = $root.'/storage/app/private/brand-masters/logo-variasi-dimdum.png';
$out = $root.'/public/images/brand';

if (! is_file($master)) {
    fwrite(STDERR, "Master tidak ditemukan: {$master}\n");
    exit(1);
}

if (! is_dir($out)) {
    mkdir($out, 0777, true);
}

/*
| Region hasil analisis lembar variasi (koordinat piksel pada master).
| 'pad' memberi ruang background di sekeliling region agar flood fill
| punya titik mulai di keempat sisi.
*/
$regions = [
    'primary' => ['x' => 224, 'y' => 126, 'w' => 806, 'h' => 497, 'pad' => 20],
    'horizontal' => ['x' => 72, 'y' => 977, 'w' => 524, 'h' => 132, 'pad' => 20],
    'icon' => ['x' => 835, 'y' => 928, 'w' => 260, 'h' => 263, 'pad' => 20],
];

$sheet = imagecreatefrompng($master);

/**
 * Potong region dari master menjadi image bertransparansi penuh.
 */
function cropRegion($sheet, array $r): GdImage
{
    $w = $r['w'] + $r['pad'] * 2;
    $h = $r['h'] + $r['pad'] * 2;

    $im = imagecreatetruecolor($w, $h);
    imagealphablending($im, false);
    imagesavealpha($im, true);
    imagecopy($im, $sheet, 0, 0, $r['x'] - $r['pad'], $r['y'] - $r['pad'], $w, $h);

    return $im;
}

/**
 * Jadikan background lembar transparan.
 *
 * Memakai flood fill dari tepi supaya area cream DI DALAM logo (badan mascot
 * berwarna krem) tidak ikut terhapus. Piksel tepi transisi diberi alpha
 * proporsional agar tidak bergerigi.
 */
function knockOutBackground(GdImage $im, int $tolerance = 46, int $feather = 96): void
{
    $w = imagesx($im);
    $h = imagesy($im);

    // Warna background diambil dari rata-rata sudut.
    $samples = [[0, 0], [$w - 1, 0], [0, $h - 1], [$w - 1, $h - 1]];
    $sr = $sg = $sb = 0;
    foreach ($samples as [$sx, $sy]) {
        $c = imagecolorat($im, $sx, $sy);
        $sr += ($c >> 16) & 0xFF;
        $sg += ($c >> 8) & 0xFF;
        $sb += $c & 0xFF;
    }
    $bgR = intdiv($sr, 4);
    $bgG = intdiv($sg, 4);
    $bgB = intdiv($sb, 4);

    $dist = function (int $c) use ($bgR, $bgG, $bgB): int {
        return abs((($c >> 16) & 0xFF) - $bgR)
            + abs((($c >> 8) & 0xFF) - $bgG)
            + abs(($c & 0xFF) - $bgB);
    };

    $outside = new SplFixedArray($w * $h);
    $stack = [];

    // Semua piksel tepi yang mirip background menjadi titik awal flood fill.
    for ($x = 0; $x < $w; $x++) {
        foreach ([0, $h - 1] as $y) {
            $stack[] = [$x, $y];
        }
    }
    for ($y = 0; $y < $h; $y++) {
        foreach ([0, $w - 1] as $x) {
            $stack[] = [$x, $y];
        }
    }

    while ($stack) {
        [$x, $y] = array_pop($stack);
        if ($x < 0 || $y < 0 || $x >= $w || $y >= $h) {
            continue;
        }
        $i = $y * $w + $x;
        if ($outside[$i]) {
            continue;
        }
        if ($dist(imagecolorat($im, $x, $y)) > $tolerance) {
            continue;
        }
        $outside[$i] = true;
        $stack[] = [$x + 1, $y];
        $stack[] = [$x - 1, $y];
        $stack[] = [$x, $y + 1];
        $stack[] = [$x, $y - 1];
    }

    // Terapkan alpha: background penuh transparan, piksel transisi di
    // sekeliling background diberi alpha proporsional terhadap jaraknya.
    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $i = $y * $w + $x;
            $c = imagecolorat($im, $x, $y);
            $r = ($c >> 16) & 0xFF;
            $g = ($c >> 8) & 0xFF;
            $b = $c & 0xFF;

            if ($outside[$i]) {
                imagesetpixel($im, $x, $y, imagecolorallocatealpha($im, $r, $g, $b, 127));

                continue;
            }

            // Bertetangga dengan background? Haluskan tepinya.
            $touches = false;
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1], [1, 1], [-1, -1], [1, -1], [-1, 1]] as [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                if ($nx < 0 || $ny < 0 || $nx >= $w || $ny >= $h) {
                    continue;
                }
                if ($outside[$ny * $w + $nx]) {
                    $touches = true;
                    break;
                }
            }

            if (! $touches) {
                continue;
            }

            $d = $dist($c);
            $ratio = min(1.0, $d / $feather);
            $alpha = (int) round(127 * (1 - $ratio));
            if ($alpha > 0) {
                imagesetpixel($im, $x, $y, imagecolorallocatealpha($im, $r, $g, $b, $alpha));
            }
        }
    }
}

/**
 * Buang margin transparan sehingga aset rapat ke konten.
 */
function trimAlpha(GdImage $im): GdImage
{
    $w = imagesx($im);
    $h = imagesy($im);
    $minX = $w;
    $minY = $h;
    $maxX = -1;
    $maxY = -1;

    for ($y = 0; $y < $h; $y++) {
        for ($x = 0; $x < $w; $x++) {
            $a = (imagecolorat($im, $x, $y) >> 24) & 0x7F;
            if ($a < 120) {
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
        }
    }

    $nw = $maxX - $minX + 1;
    $nh = $maxY - $minY + 1;

    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopy($dst, $im, 0, 0, $minX, $minY, $nw, $nh);

    return $dst;
}

/** Resize menjaga rasio, tanpa distorsi. */
function scaleTo(GdImage $im, int $targetW): GdImage
{
    $w = imagesx($im);
    $h = imagesy($im);
    if ($targetW >= $w) {
        return $im;
    }
    $targetH = (int) round($h * ($targetW / $w));

    $dst = imagecreatetruecolor($targetW, $targetH);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $targetW, $targetH, $w, $h);

    return $dst;
}

/** Kanvas persegi transparan, logo di tengah (untuk icon/favicon). */
function squarePad(GdImage $im): GdImage
{
    $w = imagesx($im);
    $h = imagesy($im);
    $s = max($w, $h);

    $dst = imagecreatetruecolor($s, $s);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagealphablending($dst, true);
    imagecopy($dst, $im, intdiv($s - $w, 2), intdiv($s - $h, 2), 0, 0, $w, $h);
    imagealphablending($dst, false);

    return $dst;
}

function savePng(GdImage $im, string $path): void
{
    imagesavealpha($im, true);
    imagepng($im, $path, 9);
    printf("  %-46s %5dx%-5d %7.1f KB\n", basename($path), imagesx($im), imagesy($im), filesize($path) / 1024);
}

function saveWebp(GdImage $im, string $path, int $q = 92): void
{
    imagesavealpha($im, true);
    imagewebp($im, $path, $q);
    printf("  %-46s %5dx%-5d %7.1f KB\n", basename($path), imagesx($im), imagesy($im), filesize($path) / 1024);
}

echo "== Ekstraksi lockup dari lembar variasi ==\n";

$built = [];
foreach ($regions as $name => $r) {
    $im = cropRegion($sheet, $r);
    knockOutBackground($im);
    $im = trimAlpha($im);
    $built[$name] = $im;
    printf("  %-12s -> %dx%d\n", $name, imagesx($im), imagesy($im));
}

echo "\n== Aset web ==\n";

// Logo horizontal: header + footer.
savePng($built['horizontal'], $out.'/dimdum-logo-horizontal.png');
saveWebp($built['horizontal'], $out.'/dimdum-logo-horizontal.webp');

// Logo utama: hero.
savePng(scaleTo($built['primary'], 720), $out.'/dimdum-logo-primary.png');
saveWebp(scaleTo($built['primary'], 720), $out.'/dimdum-logo-primary.webp');

// Icon kompak: favicon + aksen kecil.
$icon = squarePad($built['icon']);
savePng($icon, $out.'/dimdum-icon.png');
saveWebp($icon, $out.'/dimdum-icon.webp');

foreach ([16, 32, 48, 180, 192] as $size) {
    $s = imagecreatetruecolor($size, $size);
    imagealphablending($s, false);
    imagesavealpha($s, true);
    imagecopyresampled($s, $icon, 0, 0, 0, 0, $size, $size, imagesx($icon), imagesy($icon));
    savePng($s, $out."/dimdum-icon-{$size}.png");
    if (! in_array($size, [16, 32, 48], true)) {
        continue;
    }
    $icoParts[$size] = $out."/dimdum-icon-{$size}.png";
}

// favicon.ico multi-size berisi PNG (didukung semua browser modern).
$entries = [];
$blobs = [];
foreach ($icoParts as $size => $path) {
    $blobs[$size] = file_get_contents($path);
}
$offset = 6 + 16 * count($blobs);
$dir = pack('vvv', 0, 1, count($blobs));
$data = '';
foreach ($blobs as $size => $blob) {
    $dir .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, strlen($blob), $offset);
    $data .= $blob;
    $offset += strlen($blob);
}
file_put_contents(dirname($out, 2).'/favicon.ico', $dir.$data);
printf("  %-46s %s %7.1f KB\n", 'favicon.ico', '(16/32/48)', filesize(dirname($out, 2).'/favicon.ico') / 1024);

/*
| OG image 1200x630: logo utama resmi diletakkan di tengah kanvas Warm Cream.
| Tidak ada teks tambahan, tidak ada perubahan pada logo.
*/
$og = imagecreatetruecolor(1200, 630);
imagefill($og, 0, 0, imagecolorallocate($og, 0xFF, 0xF1, 0xD6));
$logo = scaleTo($built['primary'], 720);
imagealphablending($og, true);
imagecopy($og, $logo, intdiv(1200 - imagesx($logo), 2), intdiv(630 - imagesy($logo), 2), 0, 0, imagesx($logo), imagesy($logo));
imagejpeg($og, $out.'/dimdum-og.jpg', 88);
printf("  %-46s %5dx%-5d %7.1f KB\n", 'dimdum-og.jpg', 1200, 630, filesize($out.'/dimdum-og.jpg') / 1024);

echo "\nSelesai. File master tidak diubah.\n";
