<?php

namespace Rishadblack\SyllooAppBase\Erp;

/**
 * How a call to the office ended.
 */
enum OfficeOutcome: string
{
    /** The office answered and the answer holds what the app needs. */
    case Ok = 'ok';

    /** Not yet (409 `sign_in_pending`): ask again later. */
    case Pending = 'pending';

    /** The office said no, with a code ({@see RefusalCode}) and a sentence. */
    case Refused = 'refused';

    /** The office could not be reached (no internet, the breaker is open, a gateway error). */
    case Unreachable = 'unreachable';

    /** The office answered something the app can not use. */
    case Unexpected = 'unexpected';
}
