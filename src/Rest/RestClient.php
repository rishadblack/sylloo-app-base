<?php

namespace Rishadblack\SyllooAppBase\Rest;

use Closure;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Rishadblack\SyllooAppBase\Device\DeviceState;
use Rishadblack\SyllooAppBase\Erp\ApiService;
use Rishadblack\SyllooAppBase\Erp\OfficeOutcome;
use Throwable;

/**
 * One client for the office's REST door (../sylloo-erp-docs/erp/rest-door.md): read a `list` (pages, "changed since"),
 * `run` an action with its fields and `upload` a file, each write with a one-use key. The device key, the person's
 * token, the app version, the retries and the "office is down" breaker are {@see ApiService}'s; the wire format is
 * {@see RestWire}'s.
 *
 *     $rest = RestClient::forDevice($device, $token);                      // the activation's business.rest_url
 *     $rest = RestClient::for('https://xyz.sylloo.app', $device, $token);   // or a host the app names
 *     $rest->read('home');
 *     $rest->run('attendance/punch', ['latitude' => …]);
 *     $rest->list('leave-requests', since: $savedSince);
 *     $rest->upload(storage_path('app/selfie.jpg'))->fileId();
 *
 * @phpstan-consistent-constructor
 */
class RestClient
{
    public function __construct(protected ApiService $api) {}

    /**
     * A client for the REST door of one business host (`https://xyz.sylloo.app`; a trailing `/rest/v1` is dropped),
     * of one activation, as one person.
     */
    public static function for(string $hostUrl, ?DeviceState $device = null, ?string $token = null): static
    {
        $host = (string) preg_replace('#/'.preg_quote(RestWire::PREFIX, '#').'$#', '', rtrim($hostUrl, '/'));

        return new static((new ApiService($device, $token))->onBaseUrl($host.'/'));
    }

    /**
     * A client for the REST door the office named for this activation (`business.rest_url` of the activation and state
     * answers: the business's host of the device's own side, Live or Practice). Null when the office has named none
     * yet (an older office, or before the first state check).
     */
    public static function forDevice(DeviceState $device, ?string $token = null): ?static
    {
        $url = $device->restUrl();

        return $url !== null ? static::for($url, $device, $token) : null;
    }

    /**
     * A gathered answer (`home`, `roster`): worked out on each call, no `since`, no pages.
     *
     * @param  array<string, scalar|null>  $filters
     */
    public function read(string $name, array $filters = []): RestResult
    {
        return $this->call(fn () => $this->api->get(RestWire::readPath($name), $filters !== [] ? ['filter' => $filters] : []));
    }

    /**
     * One page of a list.
     *
     * @param  array<string, scalar|null>  $filters  a few named filters the list offers
     */
    public function list(string $name, ?string $since = null, int $page = 1, array $filters = []): RestResult
    {
        $perPage = (int) config('sylloo-app-base.rest.page_size', 200);

        return $this->call(fn () => $this->api->get(RestWire::listPath($name), RestWire::listQuery($since, $page, $perPage, $filters)));
    }

    /**
     * Run an action (`<area>/<name>`). The one-use key makes a second send of the same write do nothing at the office
     * (it answers the first answer again); a new key is made when none is given. Since the key guards it, a send
     * that timed out is tried again like a read.
     *
     * @param  array<string, mixed>  $fields
     */
    public function run(string $action, array $fields = [], ?string $key = null): RestResult
    {
        $path = RestWire::actionPath($action);
        $headers = RestWire::writeHeaders($key ?? RestWire::newKey());

        return $this->call(fn () => $this->api->withHeaders($headers)->postIdempotent($path, $fields));
    }

    /**
     * Keep a file (a picture or a PDF) at the office; {@see RestResult::fileId()} is the id an action then takes in
     * its `<field>_file_id`.
     */
    public function upload(string $path, ?string $key = null, ?string $filename = null): RestResult
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException("No file to upload at {$path}.");
        }

        return $this->uploadContents((string) file_get_contents($path), $filename ?? basename($path), $key);
    }

    /**
     * Keep a file given as its contents.
     */
    public function uploadContents(string $contents, string $filename, ?string $key = null): RestResult
    {
        $headers = RestWire::writeHeaders($key ?? RestWire::newKey());

        return $this->call(fn () => $this->api->withHeaders($headers)->upload(RestWire::uploadPath(), RestWire::UPLOAD_FIELD, $contents, $filename));
    }

    /**
     * @param  Closure(): Response  $send
     */
    protected function call(Closure $send): RestResult
    {
        try {
            return RestWire::read($send());
        } catch (HttpClientException $exception) {
            Log::info('REST call: not reached: '.$exception->getMessage());

            return RestResult::unreachable();
        } catch (Throwable $exception) {
            Log::warning('REST call failed: '.$exception->getMessage());

            return new RestResult(OfficeOutcome::Unexpected);
        }
    }
}
