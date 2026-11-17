<?php

namespace Rishadblack\SyllooAppBase\Screens;

use Rishadblack\SyllooAppBase\Support\AppInfo;

/**
 * Businesses: the activations this app holds ("XYZ Trading · Staff", "ABC Shop · Employee"). Tap one to work in it
 * (its own sign-in and PIN); "Add a business" starts a new activation (apps with `app.multi_business` only; a till
 * holds one).
 *
 * Step: `list`. Hook place: `businesses.bottom`.
 */
class BusinessesScreen extends Screen
{
    public function name(): string
    {
        return 'businesses';
    }

    public function start(array $input = []): ScreenState
    {
        return $this->begin('list');
    }

    public function view(ScreenState $state): ScreenView
    {
        $tiles = [];
        $current = $this->entry()->id;

        foreach ($this->entries->all() as $entry) {
            if ($entry->state->isActivated()) {
                $tiles[$entry->id] = [
                    'label' => $entry->label(),
                    'hint' => $entry->state->isPractice() ? $this->t('mode_practice') : $this->t('mode_live'),
                    'photo' => $entry->state->logoSource(),
                    'current' => $entry->id === $current,
                ];
            }
        }

        return new ScreenView(
            title: $this->t('businesses_title'),
            text: $tiles === [] ? $this->t('businesses_none') : $this->t('businesses_text'),
            fields: $tiles !== [] ? [new Field('business', 'tiles', $this->t('businesses_title'), null, $tiles, 'pick')] : [],
            buttons: AppInfo::isMultiBusiness() ? [new Button('add', $this->t('businesses_add'), 'outline-primary', 'bi bi-plus-lg')] : [],
            notices: $this->notices($state),
            places: ['businesses.bottom'],
        );
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function doPick(ScreenState $state, array $fields, ?string $value): void
    {
        $ids = array_map(fn ($entry) => $entry->id, $this->entries->all());

        if (! is_string($value) || ! in_array($value, $ids, true)) {
            return;
        }

        if ($value !== $this->entry()->id) {
            $this->presence->leave();
            $this->entries->select($value);
        }

        $state->goTo($this->presence->isSignedIn($value) ? 'home' : 'sign_in');
    }

    protected function doAdd(ScreenState $state): void
    {
        $this->presence->leave();
        $this->entries->select($this->entries->add()->id);
        $state->goTo('activate');
    }
}
