<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SignageUpload
{
    public static function maxKilobytes(): int
    {
        return max(1024, (int) config('signage.upload_max_kb', 524288));
    }

    public static function maxMegabytes(): int
    {
        return (int) ceil(self::maxKilobytes() / 1024);
    }

    /**
     * @return list<string>
     */
    public static function phpServeDirectives(): array
    {
        $mb = self::maxMegabytes();
        $post = $mb + 16;

        return [
            "upload_max_filesize={$mb}M",
            "post_max_size={$post}M",
            "memory_limit={$post}M",
            'max_execution_time=0',
            'max_input_time=600',
        ];
    }

    public static function rejectIfPhpRejected(Request $request, string $key = 'media'): void
    {
        $file = $request->file($key);
        if ($file && $file->isValid()) {
            return;
        }

        $limit = ini_get('upload_max_filesize') ?: '?';
        $code = $file?->getError() ?? 'none';

        throw ValidationException::withMessages([
            $key => "PHP rejected the file (code {$code}). This process only allows {$limit}. Stop the current server and run: php artisan signage:serve --host=0.0.0.0 --port=8000",
        ]);
    }

    public static function assertAccepted(UploadedFile $file, bool $videoOnly = false): string
    {
        $ext = strtolower((string) $file->getClientOriginalExtension());
        $mime = strtolower((string) ($file->getMimeType() ?: $file->getClientMimeType() ?: ''));

        $isMp4 = $ext === 'mp4';
        $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);

        if ($videoOnly) {
            if (! $isMp4) {
                throw ValidationException::withMessages([
                    'media' => 'Need an .mp4 file (got .'.$ext.' / '.$mime.'). Filename must end in .mp4.',
                ]);
            }

            return 'video';
        }

        if ($isMp4) {
            return 'video';
        }
        if ($isImage) {
            return 'image';
        }

        throw ValidationException::withMessages([
            'media' => 'Need mp4, jpg, png, or webp (got .'.$ext.' / '.$mime.').',
        ]);
    }

    public static function storePlayable(UploadedFile $file, string $type): string
    {
        if ($type !== 'video') {
            $ext = strtolower($file->getClientOriginalExtension() ?: 'bin');
            if ($ext === 'jpeg') {
                $ext = 'jpg';
            }

            return $file->storeAs('signage', Str::random(40).'.'.$ext, 'public');
        }

        $relative = 'signage/'.Str::random(40).'.mp4';
        $dest = Storage::disk('public')->path($relative);
        $dir = dirname($dest);
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw ValidationException::withMessages([
                'media' => 'Could not create the signage storage folder.',
            ]);
        }

        self::writePhoneSafeMp4((string) $file->getRealPath(), $dest);

        return $relative;
    }

    public static function rewritePublicVideo(string $relative): string
    {
        $src = Storage::disk('public')->path($relative);
        if (! is_file($src)) {
            return $relative;
        }

        $destRelative = 'signage/'.Str::random(40).'.mp4';
        $dest = Storage::disk('public')->path($destRelative);
        self::writePhoneSafeMp4($src, $dest);
        if ($destRelative !== $relative) {
            Storage::disk('public')->delete($relative);
        }

        return $destRelative;
    }

    public static function videoNeedsTranscode(string $absolutePath): bool
    {
        $probe = self::ffprobeVideo($absolutePath);
        if ($probe === null) {
            return true;
        }

        $head = (string) file_get_contents($absolutePath, false, null, 0, 64);
        $quickTime = str_contains($head, 'qt  ')
            || str_ends_with(strtolower($absolutePath), '.mov');

        return $probe['codec'] !== 'h264'
            || $probe['width'] > 1920
            || $probe['height'] > 1080
            || $quickTime;
    }

    /**
     * @return array{codec:string, width:int, height:int, format:string}|null
     */
    public static function ffprobeVideo(string $absolutePath): ?array
    {
        $ffprobe = trim((string) shell_exec('command -v ffprobe 2>/dev/null'));
        if ($ffprobe === '' || ! is_file($absolutePath)) {
            return null;
        }

        $raw = trim((string) shell_exec(
            $ffprobe.' -v error -select_streams v:0 -show_entries stream=codec_name,width,height -show_entries format=format_name -of json '.escapeshellarg($absolutePath)
        ));
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            return null;
        }

        $stream = $data['streams'][0] ?? [];
        $format = strtolower((string) ($data['format']['format_name'] ?? ''));

        return [
            'codec' => strtolower((string) ($stream['codec_name'] ?? '')),
            'width' => (int) ($stream['width'] ?? 0),
            'height' => (int) ($stream['height'] ?? 0),
            'format' => $format,
        ];
    }

    public static function writePhoneSafeMp4(string $src, string $dest): void
    {
        $ffmpeg = trim((string) shell_exec('command -v ffmpeg 2>/dev/null'));
        if ($ffmpeg === '') {
            if (! copy($src, $dest)) {
                throw ValidationException::withMessages([
                    'media' => 'Could not store the video (ffmpeg is not installed).',
                ]);
            }

            return;
        }

        self::runFfmpeg($ffmpeg, [
            '-y', '-i', $src,
            '-vf', "scale='min(1920,iw)':'min(1080,ih)':force_original_aspect_ratio=decrease:force_divisible_by=2",
            '-c:v', 'libx264', '-profile:v', 'baseline', '-level', '4.0',
            '-pix_fmt', 'yuv420p', '-preset', 'veryfast', '-crf', '23',
            '-g', '15', '-keyint_min', '15', '-bf', '0',
            '-x264-params', 'keyint=15:min-keyint=15:scenecut=0',
            '-c:a', 'aac', '-ac', '2', '-b:a', '128k',
            '-movflags', '+faststart', '-brand', 'mp42',
            $dest,
        ]);

        if (! is_file($dest) || filesize($dest) < 32) {
            throw ValidationException::withMessages([
                'media' => 'ffmpeg wrote an empty MP4.',
            ]);
        }
    }

    /**
     * @param  list<string>  $args
     */
    private static function runFfmpeg(string $ffmpeg, array $args): void
    {
        $cmd = array_merge([$ffmpeg], $args);
        $escaped = implode(' ', array_map('escapeshellarg', $cmd));
        $output = [];
        $code = 0;
        exec($escaped.' 2>&1', $output, $code);
        if ($code !== 0) {
            throw ValidationException::withMessages([
                'media' => 'Could not make a phone-safe MP4 (ffmpeg '.$code.'). Cheap Android decoders reject QuickTime / oversize / High-profile files.',
            ]);
        }
    }
}
