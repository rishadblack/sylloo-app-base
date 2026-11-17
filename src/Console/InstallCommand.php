<?php

namespace Rishadblack\SyllooAppBase\Console;

use Illuminate\Console\Command;

/**
 * `php artisan sylloo-app-base:install`: publishes the config, adds the base's style and script to the app's entry
 * files (once), and prints what is left to do by hand (npm packages, the app's bindings).
 */
class InstallCommand extends Command
{
    protected $signature = 'sylloo-app-base:install
        {--style=resources/scss/app.scss : The app\'s style entry file}
        {--script=resources/js/app.js : The app\'s script entry file}
        {--no-assets : Do not touch the entry files}';

    protected $description = 'Install the shared Sylloo app base: config, styles, scripts.';

    public function handle(): int
    {
        $this->callSilently('vendor:publish', ['--tag' => 'sylloo-app-base-config']);
        $this->components->info('Config published: config/sylloo-app-base.php');

        if (! $this->option('no-assets')) {
            $changed = (new Installer(base_path()))->addImports((string) $this->option('style'), (string) $this->option('script'));

            foreach ($changed as $file => $didChange) {
                $this->components->twoColumnDetail($file, $didChange ? '<fg=green>added</>' : '<fg=yellow>already there</>');
            }
        }

        $this->newLine();
        $this->components->bulletList([
            'npm install sweetalert2 and the icon font of config sylloo-app-base.icons.set (bootstrap-icons, or @tabler/icons-webfont for tabler), then npm run build',
            'Import the wire-modals script after Livewire and put Bootstrap on window.bootstrap (see the wire-modals readme); the layout of the shared screens already holds <livewire:wire-modals.host />',
            'Bind your storage in a service provider: Contracts\DeviceStore, Contracts\MemberDirectory, Contracts\ReleaseSteps (or Contracts\DeviceEntries for several businesses)',
            'Optionally bind Contracts\BrowserOpener, Contracts\AppUpdater and Contracts\Notifier',
            'Put the sylloo.device middleware on your own pages; the shared screens live under /'.config('sylloo-app-base.routes.prefix', 'device'),
            'php artisan migrate (the waiting list and saved-data tables)',
        ]);

        return self::SUCCESS;
    }
}
