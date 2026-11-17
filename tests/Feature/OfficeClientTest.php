<?php

use Illuminate\Support\Sleep;
use Rishadblack\SyllooAppBase\Erp\OfficeClient;
use Rishadblack\SyllooAppBase\Erp\OfficeOutcome;
use Rishadblack\SyllooAppBase\Erp\RefusalCode;
use Rishadblack\SyllooAppBase\SignIn\SignedInPerson;
use Rishadblack\SyllooAppBase\Testing\OfficeExamples;
use Rishadblack\SyllooAppBase\Testing\PretendOffice;

beforeEach(function () {
    Sleep::fake();
    $this->office = PretendOffice::start();
    $this->client = OfficeClient::for(activatedDevice(), 'member-token');
});

it('reads every example answer of the collection as a complete answer', function () {
    expect($this->client->state()->ok())->toBeTrue()
        ->and($this->client->signInStart(str_repeat('a', 64))->ok())->toBeTrue()
        ->and($this->client->signInFinish('r1', 'v1')->ok())->toBeTrue()
        ->and($this->client->me()->ok())->toBeTrue()
        ->and($this->client->setPin('1357')->ok())->toBeTrue()
        ->and($this->client->pushToken('fcm-token')->ok())->toBeTrue();
});

it('names a pending sign-in, a refusal and no internet', function () {
    $this->office->pending();
    expect($this->client->signInFinish('r1', 'v1')->outcome)->toBe(OfficeOutcome::Pending);

    $this->office->refuse('sign-in/finish', 'phone_needs_approval');
    $answer = $this->client->signInFinish('r1', 'v1');

    expect($answer->isRefused())->toBeTrue()
        ->and($answer->is(RefusalCode::PhoneNeedsApproval))->toBeTrue()
        ->and($answer->refusal()?->endsSignIn())->toBeFalse()
        ->and($answer->text())->toBe('HR must approve this phone first. Ask HR, then try again.');

    $this->office->down();
    expect($this->client->state()->isUnreachable())->toBeTrue();
});

it('calls an answer without what the app needs unexpected', function () {
    $this->office->answer('sign-in/finish', ['token' => 'x']);

    expect($this->client->signInFinish('r1', 'v1')->outcome)->toBe(OfficeOutcome::Unexpected);
});

it('reads an employee and a member from the sign-in answer', function () {
    $employee = SignedInPerson::fromAnswer(OfficeExamples::answer('Sign-in Finish'));
    $member = SignedInPerson::fromAnswer($this->office->asMember()->example('sign-in/finish'));

    expect($employee?->isEmployee())->toBeTrue()
        ->and($employee?->code)->toBe('EMP-0041')
        ->and($employee?->offlineLoginDays)->toBe(7)
        ->and($member?->kind)->toBe(SignedInPerson::MEMBER)
        ->and($member?->name)->toBe('Karim Hossain');
});

it('has the same examples as the API collection', function () {
    // SYLLOO_API_COLLECTION overrides; the default is the collection beside the package folders (seven levels up).
    $folder = getenv('SYLLOO_API_COLLECTION') ?: dirname(__DIR__, 7).'/sylloo-project/sylloo-erp-api-collection';
    $folder = rtrim($folder, '/\\').'/Employee mode';

    if (! is_dir($folder)) {
        $this->markTestSkipped('The API collection is not on this computer.');
    }

    expect(OfficeExamples::fromCollection($folder))->toEqual(OfficeExamples::all());
});
