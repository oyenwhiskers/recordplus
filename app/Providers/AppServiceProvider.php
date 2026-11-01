<?php

namespace App\Providers;

use Illuminate\Support\Env;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $port = self::serverPortFromAppUrl(Env::get('SERVER_PORT'), Env::get('APP_URL'));
        if ($port === null) {
            return;
        }

        putenv("SERVER_PORT={$port}");
        $_ENV['SERVER_PORT'] = $port;
    }

    /**
     * php artisan serve reads SERVER_PORT; derive it from APP_URL when unset.
     */
    public static function serverPortFromAppUrl(mixed $serverPort, mixed $appUrl): ?string
    {
        if (filled($serverPort)) {
            return null;
        }

        $port = parse_url((string) $appUrl, PHP_URL_PORT);
        if ($port === null || $port === false) {
            return null;
        }

        return (string) $port;
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
