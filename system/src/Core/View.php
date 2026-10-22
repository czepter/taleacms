<?php

declare(strict_types=1);

namespace Talea\Core;

/**
 * Templates are plain PHP files. Variables from $data are available directly in the template,
 * output is escaped with the function e().
 */
final class View
{
    /** @var array<string, string> */
    private array $slots = [];

    /** @param list<string> $dirs directories searched in the given order (e.g. the site layout, then the system templates) */
    public function __construct(private readonly array $dirs)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $file = $this->find($template);

        return (static function (string $__file, array $__data, View $view): string {
            extract($__data, EXTR_SKIP);
            ob_start();
            try {
                require $__file;

                return (string) ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
        })($file, $data, $this);
    }

    /**
     * A piece of HTML a template hands to a template rendered later (a list fills the count and the
     * buttons next to the admin heading): with $html it stores and returns '', without it returns what is stored.
     */
    public function slot(string $name, ?string $html = null): string
    {
        if ($html === null) {
            return $this->slots[$name] ?? '';
        }
        $this->slots[$name] = $html;

        return '';
    }

    public function exists(string $template): bool
    {
        try {
            $this->find($template);

            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    private function find(string $template): string
    {
        if (!preg_match('#^[a-z0-9_\-/]+$#i', $template)) {
            throw new \RuntimeException("Invalid template name: {$template}");
        }
        foreach ($this->dirs as $dir) {
            $file = $dir . '/' . $template . '.php';
            if (is_file($file)) {
                return $file;
            }
        }
        throw new \RuntimeException("Template not found: {$template}");
    }
}
