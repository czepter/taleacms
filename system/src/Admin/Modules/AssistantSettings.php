<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Core\Assistant;
use Talea\Core\Response;

/**
 * The writing assistant's provider, key and model (formerly folded into Features – the settings keys stay). Shown while the
 * feature is on; the model list comes from the provider (Core\Assistant::loadModels).
 */
final class AssistantSettings extends Settings
{
    public const string IDENT = 'assistant_settings';
    public const string NAME = 'Writing assistant';
    public const string EXTENSION = 'assistant';

    protected function tab(string $tab): string
    {
        return 'assistant';
    }

    protected function view(string $template, string $heading, array $data = []): Response
    {
        $assistant = new Assistant($this->app->settings());
        $data['modelsError'] = '';
        if ($this->app->settings()->get('ai_key') !== '' && $assistant->models() === []) {
            // a key saved before the list came from the provider (or under another provider): load it once
            $data['modelsError'] = (string) $assistant->verifyKey();
        }
        $data['models'] = $assistant->models();
        $data['modelsTime'] = $assistant->modelsTime();

        return parent::view($template, $heading, $data);
    }
}
