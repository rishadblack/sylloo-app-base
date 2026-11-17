<?php

namespace Rishadblack\SyllooAppBase\Erp;

/**
 * Every refusal code the office sends to a device (../sylloo-erp-docs/erp/device-api.md §1, §6) and the REST door
 * (plan §8.2), as a named result. The app reads the code, never the message; the sentence is the base's text
 * `sylloo-app-base::device.refused_<code>`.
 */
enum RefusalCode: string
{
    // Activation
    case InvalidCode = 'invalid_code';
    case TooManyAttempts = 'too_many_attempts';
    case PlanEnded = 'plan_ended';
    case LiveNotAvailable = 'live_not_available';
    case BusinessSuspended = 'business_suspended';
    case NotInPlanPos = 'not_in_plan_pos';
    case NotInPlanApp = 'not_in_plan_app';
    case NotInPlanEmployee = 'not_in_plan_employee';
    case TooManyPending = 'too_many_pending';
    case DeviceLimitReachedPos = 'device_limit_reached_pos';
    case DeviceLimitReachedApp = 'device_limit_reached_app';
    case EmployeePhoneOnly = 'employee_phone_only';
    case EmployeeAppOff = 'employee_app_off';
    case AppTooOld = 'app_too_old';
    case SyncRequired = 'sync_required';

    // Sign-in
    case SignInPending = 'sign_in_pending';
    case SignInRefused = 'sign_in_refused';
    case SignInExpired = 'sign_in_expired';
    case MemberNotAllowed = 'member_not_allowed';
    case NotAllowedOnTerminal = 'not_allowed_on_terminal';
    case EmployeeNotAllowed = 'employee_not_allowed';
    case PhoneBelongsToAnother = 'phone_belongs_to_another';
    case PhoneNeedsApproval = 'phone_needs_approval';
    case SessionExpired = 'session_expired';
    case SignedInElsewhere = 'signed_in_elsewhere';

    // Any call
    case DeviceReleased = 'device_released';
    case ValidationFailed = 'validation_failed';
    case Refused = 'refused';
    case NotFound = 'not_found';
    case Forbidden = 'forbidden';

    // The REST door (../sylloo-erp-docs/erp/rest-door.md §4, §6)
    case NoPermission = 'no_permission';
    case FeatureOff = 'feature_off';
    case KeyRequired = 'key_required';
    case KeyReused = 'key_reused';
    case CallInProgress = 'call_in_progress';
    case TooManyRequests = 'too_many_requests';

    /**
     * The device's sign-in is over: the person signs in with the browser again.
     */
    public function endsSignIn(): bool
    {
        return in_array($this, [self::SessionExpired, self::SignedInElsewhere], true);
    }

    /**
     * The device may not work now: its state screen says why.
     */
    public function blocksDevice(): bool
    {
        return in_array($this, [self::PlanEnded, self::BusinessSuspended, self::EmployeeAppOff, self::DeviceReleased], true);
    }

    /**
     * Nothing was done and the same call may simply be sent again later (the first send still runs, or too much at
     * once): a waiting write keeps waiting.
     */
    public function triesLater(): bool
    {
        return in_array($this, [self::CallInProgress, self::TooManyRequests], true);
    }

    /**
     * The door's gate refused the device or the person, not the call: nothing gets through until the app deals with
     * it (sign in again, renew, update), so a waiting write keeps waiting.
     */
    public function isGate(): bool
    {
        return $this->endsSignIn() || $this->blocksDevice() || in_array($this, [self::AppTooOld, self::BusinessSuspended], true);
    }

    /**
     * The plan's places of a kind are all in use (the All devices in use list).
     */
    public function isDeviceLimit(): bool
    {
        return in_array($this, [self::DeviceLimitReachedPos, self::DeviceLimitReachedApp], true);
    }
}
