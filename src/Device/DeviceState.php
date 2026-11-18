<?php

namespace Rishadblack\SyllooAppBase\Device;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Rishadblack\SyllooAppBase\Contracts\DeviceStore;
use Rishadblack\SyllooAppBase\Images\ImageCache;
use Rishadblack\SyllooAppBase\Rest\RestClient;
use Rishadblack\SyllooAppBase\Support\AppInfo;
use Throwable;

/**
 * What one activation of this device knows about itself and its business (../sylloo-erp-docs/erp/device-api.md):
 * kept in its DeviceStore from the activation answer, `GET device/api/v1/state` (and, on a till, the heartbeat's
 * `device` key), so the app also knows it offline. It decides which screen shows instead of the app's work
 * ({@see self::screen()}).
 *
 * The device key itself is kept encrypted by {@see ActivationKey} (key `activation_key`), the mode in `license_mode`.
 * An app adds its own keys and screens by extending this class ({@see self::activationSettings()},
 * {@see self::requiredSettings()}, {@see self::extraScreen()}).
 */
class DeviceState
{
    public const NOT_ACTIVATED = 'not_activated';

    public const RELEASING = 'releasing';

    public const LOCKED = 'locked';

    public const BUSINESS_SUSPENDED = 'business_suspended';

    public const PLAN_ENDED = 'plan_ended';

    public const UPDATE_NEEDED = 'update_needed';

    /** The employee panel or the employee app is switched off for the business (Employee mode, device-api.md §6.2). */
    public const EMPLOYEE_APP_OFF = 'employee_app_off';

    /** The office answers nothing and the device needs it (shown by an app or the state screen's check). */
    public const UNREACHABLE = 'unreachable';

    /** Values of `blocked` the office sends. */
    public const BLOCKED_REASONS = ['plan_ended', 'business_suspended', 'device_released', 'employee_app_off'];

    /** Days before the plan ends from which the plan bar shows (plan §5.5). */
    public const PLAN_BAR_DAYS = 14;

    /**
     * Keys of the activation: forgotten when the device is released or uses another code.
     *
     * @var list<string>
     */
    public const SETTINGS = [
        'activation_key', 'device_id', 'device_name', 'activation_code', 'activation_date',
        'license_mode', 'business_key', 'business_name', 'business_logo_url', 'business_logo_path', 'business_portal_url', 'business_hosts', 'business_host_url', 'business_rest_url', 'plan', 'blocked', 'min_version',
        'offline_login_days', 'is_locked', 'device_kind',
    ];

    /** Key that marks a released device that still sends what waits, then cleans itself (DeviceRelease). */
    public const RELEASING_SETTING = 'device_releasing';

    /** Key with the business a device was released from, for the line on the Activate screen. */
    public const RELEASED_FROM_SETTING = 'released_from';

    /** The random id of this install, sent at every activation (no hardware fingerprint). */
    public const DEVICE_UID_SETTING = 'device_uid';

    protected ActivationKey $activationKey;

    public function __construct(protected DeviceStore $store)
    {
        $this->activationKey = new ActivationKey($store);
    }

    public function store(): DeviceStore
    {
        return $this->store;
    }

    /**
     * The encrypted device key of this activation.
     */
    public function activationKey(): ActivationKey
    {
        return $this->activationKey;
    }

    /**
     * The device key to send in `X-Device-Key`, or null when there is none that can be read.
     */
    public function deviceKey(): ?string
    {
        return $this->activationKey->get();
    }

    public function isActivated(): bool
    {
        foreach ($this->requiredSettings() as $key) {
            if (! filled($this->get($key))) {
                return false;
            }
        }

        return ! $this->activationKey->isUnreadable();
    }

    /**
     * The screen this device shows instead of its pages, or null when it may work. In this order: releasing, not
     * activated, released at the office, locked from the office, business paused, plan ended (Live only), update needed,
     * then the app's own ({@see self::extraScreen()}).
     */
    public function screen(): ?string
    {
        if ($this->isReleasing()) {
            return self::RELEASING;
        }

        if (! $this->isActivated()) {
            return self::NOT_ACTIVATED;
        }

        return match (true) {
            $this->get('blocked') === 'device_released' => self::RELEASING,
            (int) $this->get('is_locked') === 1 => self::LOCKED,
            $this->get('blocked') === 'business_suspended' => self::BUSINESS_SUSPENDED,
            $this->get('blocked') === 'employee_app_off' => self::EMPLOYEE_APP_OFF,
            $this->planEnded() => self::PLAN_ENDED,
            $this->needsUpdate() => self::UPDATE_NEEDED,
            default => $this->extraScreen(),
        };
    }

    /**
     * Keep a new activation (device-api.md §1 `POST activate` answered 201): the key (encrypted), the device id and
     * name, the mode, the code and the day, and everything else the answer says ({@see self::remember()}).
     *
     * @param  array<string, mixed>  $answer
     */
    public function rememberActivation(array $answer, string $code, string $deviceName): void
    {
        $this->activationKey->put($answer['device_key']);
        $this->store->set('device_id', isset($answer['device_id']) ? (string) $answer['device_id'] : null);
        $this->store->set('device_name', $deviceName);
        $this->store->set('license_mode', $answer['mode']);
        $this->store->set('activation_code', $code);
        $this->store->set('activation_date', now()->toDateString());
        $this->store->set(self::RELEASED_FROM_SETTING, null);
        $this->store->set(self::RELEASING_SETTING, null);

        $this->remember($answer);
    }

    /**
     * Keep what the ERP says about this device: the answer of `activate`, `GET state` or the heartbeat's `device`.
     *
     * @param  array<string, mixed>  $state
     */
    public function remember(array $state): void
    {
        if (is_array($state['device'] ?? null)) {
            $device = $state['device'];

            if (is_string($device['name'] ?? null)) {
                $this->store->set('device_name', $device['name']);
            }

            if (in_array($device['mode'] ?? null, ['live', 'practice'], true)) {
                $this->store->set('license_mode', $device['mode']);
            }

            if (in_array($device['kind'] ?? null, ['pos', 'app', 'employee'], true)) {
                $this->store->set('device_kind', $device['kind']);
            }

            if (isset($device['id']) && is_scalar($device['id'])) {
                $this->store->set('device_id', (string) $device['id']);
            }

            // A device the office locked (Devices page); unlocked again when the office says so.
            if (is_bool($device['locked'] ?? null)) {
                $this->store->set('is_locked', $device['locked'] ? 1 : 2);
            }
        }

        if (is_array($state['business'] ?? null)) {
            $business = $state['business'];

            // The business's fixed key (`business.key`); an older ERP sends none and the kept one stays.
            if (array_key_exists('key', $business)) {
                $this->store->set('business_key', self::validKey($business['key']));
            }

            if (is_string($business['name'] ?? null) && $business['name'] !== '') {
                $this->store->set('business_name', $business['name']);
            }

            if (array_key_exists('logo_url', $business)) {
                $this->rememberLogo($business['logo_url']);
            }

            if (array_key_exists('portal_url', $business)) {
                $this->store->set('business_portal_url', self::isSafeUrl($business['portal_url']) ? $business['portal_url'] : null);
            }

            foreach (['host_url' => 'business_host_url', 'rest_url' => 'business_rest_url'] as $field => $setting) {
                if (array_key_exists($field, $business)) {
                    $this->store->set($setting, self::isSafeUrl($business[$field]) ? rtrim((string) $business[$field], '/') : null);
                }
            }

            if (is_array($business['hosts'] ?? null)) {
                $this->store->set('business_hosts', array_map(fn ($host) => is_string($host) ? $host : null, array_intersect_key($business['hosts'], ['live' => 1, 'practice' => 1])));
            }
        }

        if (is_array($state['plan'] ?? null)) {
            $plan = $state['plan'];

            $this->store->set('plan', [
                'active' => (bool) ($plan['active'] ?? false),
                'ends_on' => self::date($plan['ends_on'] ?? null),
                'in_grace' => (bool) ($plan['in_grace'] ?? false),
                'valid_until' => self::date($plan['valid_until'] ?? null),
            ]);
        }

        if (array_key_exists('blocked', $state)) {
            $blocked = in_array($state['blocked'], self::BLOCKED_REASONS, true) ? $state['blocked'] : null;
            $this->store->set('blocked', $blocked);

            if ($blocked === 'device_released') {
                $this->markReleasing();
            }
        }

        if (array_key_exists('min_version', $state)) {
            $this->store->set('min_version', is_string($state['min_version']) ? $state['min_version'] : null);
        }

        if (is_numeric($state['offline_login_days'] ?? null)) {
            $this->store->set('offline_login_days', (int) $state['offline_login_days']);
        }
    }

    /**
     * The ERP released this device: it stops working, sends what waits, then cleans itself (DeviceRelease).
     */
    public function markReleasing(): void
    {
        if ($this->get(self::RELEASING_SETTING) !== true) {
            $this->store->set(self::RELEASING_SETTING, true);
        }
    }

    public function isReleasing(): bool
    {
        return $this->get(self::RELEASING_SETTING) === true;
    }

    /**
     * Whether an ERP answer says the device key no longer works (401 `device_released`).
     */
    public static function isReleasedReply(Response $response): bool
    {
        return $response->status() === 401 && rescue(fn () => $response->json('code'), null, false) === 'device_released';
    }

    /**
     * The kind the office gave this device: `pos`, `app` (Staff mode) or `employee` (Employee mode), or null before
     * the office said.
     */
    public function kind(): ?string
    {
        $kind = $this->get('device_kind');

        return is_string($kind) && $kind !== '' ? $kind : null;
    }

    public function isEmployee(): bool
    {
        return $this->kind() === 'employee';
    }

    public function isPractice(): bool
    {
        return $this->get('license_mode') === 'practice';
    }

    public function businessName(): ?string
    {
        $name = $this->get('business_name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The business's fixed key the ERP sent (`business.key`): the same on Live and Practice and whatever host the device
     * uses, so two devices can tell they belong to one business (the till's phone scanner pairing). Null before an ERP
     * that sends it answered.
     */
    public function businessKey(): ?string
    {
        return self::validKey($this->get('business_key'));
    }

    public function deviceName(): ?string
    {
        $name = $this->get('device_name');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * The host the browser sign-in opens, for "We opened xyz.sylloo.app" (always the Live address, plan §5.3).
     */
    public function signInHost(): ?string
    {
        $hosts = $this->get('business_hosts');

        return is_array($hosts) && is_string($hosts['live'] ?? null) ? $hosts['live'] : null;
    }

    /**
     * The business's hosts the ERP sent (`business.hosts`): `live` and `practice`, each a host or null.
     *
     * @return array{live?: ?string, practice?: ?string}
     */
    public function hosts(): array
    {
        $hosts = $this->get('business_hosts');

        return is_array($hosts) ? $hosts : [];
    }

    /**
     * The business's address of this device's own side (`business.host_url`: Live or Practice, by mode), or null.
     */
    public function hostUrl(): ?string
    {
        $url = $this->get('business_host_url');

        return self::isSafeUrl($url) ? $url : null;
    }

    /**
     * The REST door the office named (`business.rest_url`, e.g. `https://xyz.sylloo.app/rest/v1`), where every REST
     * call of this activation goes ({@see RestClient::forDevice()}), or null.
     */
    public function restUrl(): ?string
    {
        $url = $this->get('business_rest_url');

        return self::isSafeUrl($url) ? $url : null;
    }

    /**
     * The portal page where the owner renews the plan ("Renew plan", the plan bar), as the ERP sent it, or null when it
     * sent none (the screen then says to open the Sylloo portal in the browser).
     */
    public function portalUrl(): ?string
    {
        $url = $this->get('business_portal_url');

        return self::isSafeUrl($url) ? $url : null;
    }

    /**
     * Keep the business logo address and a local copy of it (ImageCache: https, Sylloo hosts only), so the first
     * screens show it offline too. A new address replaces the old copy.
     */
    public function rememberLogo(mixed $url): void
    {
        $url = is_string($url) && ImageCache::isRemote($url) ? $url : null;
        $before = $this->get('business_logo_url');

        if ($url === $before && ($url === null || $this->get('business_logo_path') !== null)) {
            return;
        }

        $path = $url !== null ? rescue(fn () => app(ImageCache::class)->cache($url), null, false) : null;

        $this->store->set('business_logo_url', $url);
        $this->store->set('business_logo_path', $path);
    }

    /**
     * The local copy of the business logo to show, or null (the screens then show the first letter of the name).
     */
    public function logoSource(): ?string
    {
        $path = $this->get('business_logo_path');

        return is_string($path) && ImageCache::isCachedPath($path) ? ImageCache::url($path) : null;
    }

    /**
     * An address the app may open in the browser: https (http outside production).
     */
    public static function isSafeUrl(mixed $url): bool
    {
        if (! is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return $scheme === 'https' || ($scheme === 'http' && ! app()->isProduction());
    }

    /**
     * Days a member may sign in by PIN offline after the device last confirmed their sign-in online.
     */
    public function offlineLoginDays(): int
    {
        $days = $this->get('offline_login_days');

        return is_numeric($days) ? (int) $days : (int) config('erp.offline_login_days');
    }

    /**
     * @return array{active: bool, ends_on: ?string, in_grace: bool, valid_until: ?string}|null
     */
    public function plan(): ?array
    {
        $plan = $this->get('plan');

        return is_array($plan) ? $plan : null;
    }

    /**
     * Whether a Live device may not work because the plan ended: the ERP says so, or the device is past the last day it
     * may work offline (`plan.valid_until`, plan end + grace). Practice never ends (decision 17).
     */
    public function planEnded(): bool
    {
        if ($this->isPractice()) {
            return false;
        }

        if ($this->get('blocked') === 'plan_ended') {
            return true;
        }

        $validUntil = $this->plan()['valid_until'] ?? null;

        return $validUntil !== null && rescue(fn () => Carbon::parse($validUntil)->endOfDay()->isPast(), false, false);
    }

    /**
     * Days left of a Live plan when it ends within {@see self::PLAN_BAR_DAYS} or is in its days of grace, else null.
     * In grace it counts down to the last day the device may work.
     */
    public function planEndsInDays(): ?int
    {
        $plan = $this->plan();

        if ($this->isPractice() || $plan === null || $this->planEnded()) {
            return null;
        }

        $end = $plan['in_grace'] ? ($plan['valid_until'] ?? $plan['ends_on'] ?? null) : ($plan['ends_on'] ?? null);

        if ($end === null) {
            return null;
        }

        try {
            $days = (int) now()->startOfDay()->diffInDays(Carbon::parse($end)->startOfDay(), false);
        } catch (Throwable) {
            return null;
        }

        return $days >= 0 && $days <= self::PLAN_BAR_DAYS ? $days : null;
    }

    /**
     * Whether this version is older than the ERP's minimum (`min_version`); the version is AppInfo::version().
     */
    public function needsUpdate(): bool
    {
        $minimum = $this->get('min_version');

        return is_string($minimum) && $minimum !== ''
            && AppInfo::isOlderThan($minimum);
    }

    /**
     * The random id of this install (made once, kept by a release).
     */
    public function deviceUid(): string
    {
        $uid = $this->get(self::DEVICE_UID_SETTING);

        if (! is_string($uid) || $uid === '') {
            $uid = (string) Str::uuid();
            $this->store->set(self::DEVICE_UID_SETTING, $uid);
        }

        return $uid;
    }

    /**
     * Forget the activation and everything the ERP said about it (not the app's other data; the app's release clean-up
     * removes that).
     */
    public function forgetActivation(): void
    {
        $this->activationKey->put(null);

        foreach ($this->activationSettings() as $key) {
            if ($key !== ActivationKey::SETTING) {
                $this->store->set($key, null);
            }
        }

        $this->store->set(self::RELEASING_SETTING, null);
    }

    /**
     * A value of the store, or null when it can not be read (a page never breaks over it).
     */
    public function get(string $key): mixed
    {
        try {
            return $this->store->get($key);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Keys forgotten with the activation ({@see self::forgetActivation()}); an app adds its own.
     *
     * @return list<string>
     */
    protected function activationSettings(): array
    {
        return static::SETTINGS;
    }

    /**
     * Keys that must hold a value for the device to count as activated (besides a readable key).
     *
     * @return list<string>
     */
    protected function requiredSettings(): array
    {
        return [ActivationKey::SETTING];
    }

    /**
     * The app's own screen after the shared ones (e.g. a till without its outlet), or null.
     */
    protected function extraScreen(): ?string
    {
        return app(StateRegistry::class)->resolve($this);
    }

    /**
     * A business key as the ERP makes it (letters, digits, `-` and `_`, 16 to 128 long), or null.
     */
    protected static function validKey(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{16,128}$/', $value) === 1 ? $value : null;
    }

    protected static function date(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return rescue(fn () => Carbon::parse($value)->toDateString(), null, false);
    }
}
