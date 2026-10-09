<?php

namespace App\Providers;

use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Generate absolute URLs from APP_URL rather than the incoming
         * request.
         *
         * The deployment sits behind Cloudflare and a host reverse proxy
         * that forwards to the container without a Host header, so
         * Request::getHost() returns an empty string and route() produced
         * "https:/api/files/1/download" — a single slash, which is an
         * unusable URL. X-Forwarded-Proto is honoured, which is why only the
         * host was missing.
         *
         * APP_URL is the public address of this API and is set per
         * environment, so pinning the root to it makes every generated URL
         * correct regardless of what the proxy forwards.
         */
        $url = config('app.url');

        if (is_string($url) && $url !== '') {
            $url = rtrim($url, '/');
            URL::forceRootUrl($url);

            // forceRootUrl pins the host but not the scheme, which would
            // still be taken from the request. Derive it from APP_URL so a
            // plain-HTTP hop inside the container cannot downgrade a
            // generated https URL to http.
            $scheme = parse_url($url, PHP_URL_SCHEME);

            if (is_string($scheme) && $scheme !== '') {
                URL::forceScheme($scheme);
            }
        }
    }
}
