<?php

namespace Rishadblack\SyllooAppBase\Images;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Keeps a local copy of the images the ERP sends (absolute URLs), so the terminal shows them offline.
 *
 * A copy is named after the SHA-1 of the full URL, query included: the ERP adds `?v=<hash>` to a file's
 * URL, so a changed image gets a new name and is downloaded again, while an unchanged one never is.
 * Copies are served by the sessionless `erp_image` route, since the packaged app has no storage link.
 */
class ImageCache
{
    public const DISK = 'public';

    public const DIRECTORY = 'erp-images';

    /**
     * Largest image kept, in bytes.
     */
    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Image types kept, by content type, with the extension they are stored under.
     *
     * @var array<string, string>
     */
    public const CONTENT_TYPES = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/pjpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    public const EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    /** Longest side of the small copy the product grid shows (audit M5). */
    public const THUMB_SIZE = 200;

    /**
     * Set once a download could not reach the ERP, so a run over many records stops early.
     */
    protected bool $offline = false;

    public static function isRemote(?string $url): bool
    {
        return is_string($url) && Str::startsWith($url, ['http://', 'https://']);
    }

    /**
     * Only images of the ERP are downloaded: over https (plain http only while the ERP itself runs on http, in
     * development), from the ERP's host or a domain in config('erp.image_hosts') or one of their subdomains, never
     * from an IP address (e.g. a PC or router on the shop's network).
     */
    public static function isAllowedSource(string $url): bool
    {
        $scheme = Str::lower((string) parse_url($url, PHP_URL_SCHEME));
        $host = Str::lower(rtrim((string) parse_url($url, PHP_URL_HOST), '.'));
        $erpHost = Str::lower((string) parse_url((string) config('erp.base_url'), PHP_URL_HOST));
        $erpScheme = Str::lower((string) parse_url((string) config('erp.base_url'), PHP_URL_SCHEME));

        if ($host === '' || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return false;
        }

        if ($scheme !== 'https' && ! ($scheme === 'http' && $erpScheme === 'http')) {
            return false;
        }

        $allowedHosts = array_filter([$erpHost, ...array_map(fn ($domain) => Str::lower(trim((string) $domain, '. ')), (array) config('erp.image_hosts'))]);

        foreach ($allowedHosts as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the last download could not reach the server.
     */
    public function isOffline(): bool
    {
        return $this->offline;
    }

    /**
     * The local copy of an image: downloaded when there is none for this exact URL yet.
     * Returns the path on the public disk, or null when the URL is not an image that could be kept.
     * Never throws.
     */
    public function cache(?string $url): ?string
    {
        if (! self::isRemote($url)) {
            return null;
        }

        if (! self::isAllowedSource($url)) {
            Log::warning('Image cache: '.Str::limit($url, 120).' is not on an ERP host; not downloaded');

            return null;
        }

        try {
            $existing = $this->existingPath($url);

            if ($existing !== null) {
                return $existing;
            }

            // A redirect is never followed (it could lead off the ERP hosts), and the body is read as a stream so a large
            // file is refused at the cap instead of being read whole.
            $response = Http::connectTimeout(5)->timeout(15)
                ->withOptions(['allow_redirects' => false, 'stream' => true])
                ->get($url);

            if (! $response->successful()) {
                Log::warning("Image cache: {$url} answered {$response->status()}");

                return null;
            }

            $contentType = Str::lower(trim(Str::before((string) $response->header('Content-Type'), ';')));
            $extension = self::CONTENT_TYPES[$contentType] ?? null;

            if ($extension === null) {
                Log::warning("Image cache: {$url} is not an image ({$contentType})");

                return null;
            }

            $body = self::readCapped($response);

            if ($body === null || $body === '') {
                Log::warning("Image cache: {$url} is empty or larger than ".self::MAX_BYTES.' bytes');

                return null;
            }

            $path = self::DIRECTORY.'/'.sha1($url).'.'.$extension;

            if (! Storage::disk(self::DISK)->put($path, $body)) {
                Log::warning("Image cache: could not store {$url}");

                return null;
            }

            $this->thumbnail($path);

            return $path;
        } catch (ConnectionException $exception) {
            $this->offline = true;
            Log::info("Image cache: {$url} could not be reached: ".$exception->getMessage());

            return null;
        } catch (Throwable $exception) {
            Log::warning("Image cache: could not cache {$url}: ".$exception->getMessage());

            return null;
        }
    }

    /**
     * The body of the answer, or null when it says or turns out to be larger than {@see self::MAX_BYTES}: read in chunks
     * and given up as soon as the cap is passed.
     */
    protected static function readCapped(Response $response): ?string
    {
        $contentLength = $response->header('Content-Length');

        if (is_numeric($contentLength) && (int) $contentLength > self::MAX_BYTES) {
            return null;
        }

        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        while (! $stream->eof()) {
            $body .= $stream->read(65536);

            if (strlen($body) > self::MAX_BYTES) {
                $stream->close();

                return null;
            }
        }

        return $body;
    }

    /**
     * The stored copy of this exact URL, if there is one.
     */
    public function existingPath(string $url): ?string
    {
        $disk = Storage::disk(self::DISK);
        $name = self::DIRECTORY.'/'.sha1($url);

        foreach (array_unique(self::CONTENT_TYPES) as $extension) {
            if ($disk->exists("{$name}.{$extension}")) {
                return "{$name}.{$extension}";
            }
        }

        return null;
    }

    /**
     * True for a path this cache wrote (and may therefore delete).
     */
    public static function isCachedPath(?string $path): bool
    {
        return is_string($path)
            && preg_match('#^'.self::DIRECTORY.'/[0-9a-f]{40}(\.thumb)?\.('.implode('|', self::EXTENSIONS).')$#', $path) === 1;
    }

    /**
     * URL the app's windows load a cached copy from.
     */
    public static function url(string $path): string
    {
        // The app's sessionless image route when it has one (the till), else the public disk's address.
        return Route::has('erp_image')
            ? route('erp_image', ['file' => basename($path)])
            : Storage::disk(self::DISK)->url($path);
    }

    /**
     * Full path of a cached copy on disk, or null when there is none.
     */
    public static function absolutePath(string $file): ?string
    {
        $path = self::DIRECTORY.'/'.basename($file);

        if (! self::isCachedPath($path) || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        return Storage::disk(self::DISK)->path($path);
    }

    public function delete(?string $path): void
    {
        if (self::isCachedPath($path)) {
            Storage::disk(self::DISK)->delete([$path, self::thumbPathOf($path)]);
        }
    }

    /**
     * Where the small copy of a cached image is kept.
     */
    public static function thumbPathOf(string $path): string
    {
        return preg_replace('#(\.thumb)?\.[a-z]+$#', '', $path).'.thumb.jpg';
    }

    /**
     * The small copy of a cached image (audit M5: about 200 px, a few KB), made once; null when it can not be made (the
     * full copy is shown then).
     */
    public function thumbnail(string $path): ?string
    {
        $disk = Storage::disk(self::DISK);
        $thumb = self::thumbPathOf($path);

        if ($disk->exists($thumb)) {
            return $thumb;
        }

        if (! self::isCachedPath($path) || ! $disk->exists($path) || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        try {
            $image = @imagecreatefromstring((string) $disk->get($path));

            if ($image === false) {
                return null;
            }

            $width = imagesx($image);
            $height = imagesy($image);
            $scale = min(1, self::THUMB_SIZE / max($width, $height, 1));
            $small = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            imagefill($small, 0, 0, imagecolorallocate($small, 255, 255, 255));
            imagecopyresampled($small, $image, 0, 0, 0, 0, imagesx($small), imagesy($small), $width, $height);

            ob_start();
            imagejpeg($small, null, 80);
            $saved = $disk->put($thumb, (string) ob_get_clean());

            return $saved ? $thumb : null;
        } catch (Throwable $exception) {
            Log::info('Image cache: no small copy of '.$path.': '.$exception->getMessage());

            return null;
        }
    }

    /**
     * Every cached copy on disk.
     *
     * @return list<string>
     */
    public function files(): array
    {
        return array_values(array_filter(Storage::disk(self::DISK)->files(self::DIRECTORY), fn (string $path) => self::isCachedPath($path)));
    }
}
