<?php

namespace Rishadblack\SyllooAppBase\Screens;

use Rishadblack\SyllooAppBase\Language\Language;

/**
 * Language: English, Bangla or Finnish (config `sylloo-app-base.languages`), for the whole app, before or after
 * activation. Step: `choose`.
 */
class LanguageScreen extends Screen
{
    public function name(): string
    {
        return 'language';
    }

    public function start(array $input = []): ScreenState
    {
        return $this->begin('choose', ['language' => Language::current()]);
    }

    public function view(ScreenState $state): ScreenView
    {
        return new ScreenView(
            title: $this->t('language_title'),
            text: $this->t('language_text'),
            fields: [new Field('language', 'select', $this->t('language_label'), null, Language::choices())],
            buttons: [new Button('save', $this->t('save'), 'primary', 'bi bi-translate')],
            notices: $this->notices($state),
        );
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    protected function doSave(ScreenState $state, array $fields): void
    {
        $code = $fields['language'] ?? null;

        if (! Language::isChoice($code)) {
            $state->error('language', $this->t('language_invalid'));

            return;
        }

        Language::choose((string) $code);
        $state->data['language'] = $code;
        $state->toast($this->t('language_saved'));
        $state->goTo('back');
    }
}
