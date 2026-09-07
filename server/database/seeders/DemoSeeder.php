<?php

namespace Database\Seeders;

use App\Models\Device;
use App\Models\Playlist;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        Storage::disk('public')->makeDirectory('signage');

        $slides = [
            ['thongkee-1.png', 'THONGKEE', 'Downstairs wall · screen A', 18, 18, 24],
            ['thongkee-2.png', 'LUNCH SET', 'Noodles · rice · drinks', 122, 28, 28],
            ['thongkee-3.png', 'TODAY', 'Same playlist on 4 TVs', 20, 83, 45],
        ];

        foreach ($slides as [$name, $title, $subtitle, $r, $g, $b]) {
            $this->writeSlide(Storage::disk('public')->path('signage/'.$name), $title, $subtitle, $r, $g, $b);
        }

        $videoRel = 'signage/loop.mp4';
        $videoAbs = Storage::disk('public')->path($videoRel);
        $this->writeDemoVideo($videoAbs);

        $playlist = Playlist::query()->create([
            'name' => 'Thongkee Downstairs',
            'start_at' => now()->addSeconds(12),
        ]);

        $order = 1;
        foreach ($slides as [$name, $title]) {
            $playlist->items()->create([
                'type' => 'image',
                'path' => 'signage/'.$name,
                'duration_ms' => 8000,
                'sort_order' => $order++,
            ]);
        }

        if (File::exists($videoAbs)) {
            $playlist->items()->create([
                'type' => 'video',
                'path' => $videoRel,
                'duration_ms' => 6000,
                'sort_order' => $order,
            ]);
        }

        $codes = ['TV01AA', 'TV02BB', 'TV03CC', 'TV04DD'];
        foreach ($codes as $i => $code) {
            Device::query()->create([
                'name' => 'TV '.($i + 1),
                'pairing_code' => $code,
                'playlist_id' => $playlist->id,
                'status' => 'waiting',
            ]);
        }
    }

    private function writeSlide(string $path, string $title, string $subtitle, int $r, int $g, int $b): void
    {
        $image = imagecreatetruecolor(1920, 1080);
        $bg = imagecolorallocate($image, $r, $g, $b);
        $fg = imagecolorallocate($image, 255, 255, 255);
        $muted = imagecolorallocate($image, 230, 230, 230);
        imagefilledrectangle($image, 0, 0, 1920, 1080, $bg);

        $font = '/System/Library/Fonts/Supplemental/Arial.ttf';
        if (is_file($font)) {
            imagettftext($image, 92, 0, 160, 460, $fg, $font, $title);
            imagettftext($image, 36, 0, 160, 560, $muted, $font, $subtitle);
            imagettftext($image, 22, 0, 160, 940, $muted, $font, 'Foodtale signage MVP');
        } else {
            imagestring($image, 5, 160, 400, $title, $fg);
            imagestring($image, 4, 160, 430, $subtitle, $muted);
        }

        imagepng($image, $path);
        imagedestroy($image);
    }

    private function writeDemoVideo(string $path): void
    {
        $cmd = sprintf(
            'ffmpeg -y -f lavfi -i color=c=0x111827:s=1280x720:d=6 -pix_fmt yuv420p %s 2>/dev/null',
            escapeshellarg($path)
        );
        exec($cmd, $output, $code);
        if ($code !== 0 && ! File::exists($path)) {
            $this->command?->warn('ffmpeg did not write demo video; images only.');
        }
    }
}
