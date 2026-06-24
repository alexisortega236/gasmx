<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

class StationSearchCache
{
    private const VERSION_KEY = 'gas-mx:stations-cache-version';

    public static function version(): int
    {
        return (int) Cache::rememberForever(
            self::VERSION_KEY,
            fn () => 1
        );
    }

    public static function key(string $type, array $parameters): string
    {
        ksort($parameters);

        return sprintf(
            'gas-mx:stations:%s:v%d:%s',
            $type,
            self::version(),
            sha1(json_encode($parameters))
        );
    }

    public static function invalidate(): int
    {
        return Cache::increment(self::VERSION_KEY);
    }
}
