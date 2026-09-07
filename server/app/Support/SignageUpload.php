<?php

namespace App\Support;

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
}
