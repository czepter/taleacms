<?php

declare(strict_types=1);

namespace Talea\Core;

final class Session
{
    /** The name of the session cookie (the cookie table of Core\Privacy lists it). */
    public const string COOKIE = 'talea';

    private bool $started = false;

    public function __construct(
        private readonly bool $secure,
        private readonly string $cookiePath = '/',
        private readonly string $owner = '',
        private readonly string $install = '',
    ) {
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        session_name(self::COOKIE);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $this->cookiePath,
            'secure' => $this->secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        // a login survives 8 hours of inactivity (the admin also keeps it alive while a page is open, image/admin.js)
        ini_set('session.gc_maxlifetime', '28800');
        session_start();
        $this->started = true;
        // several owners (docs/specs/multisite.md): the store is shared by the install, so a session belongs to the owner that made it. The id
        // of an owner's session presented on another owner's host (the cookie is host-only, but a header is not) starts empty there. A session
        // from before owners existed has no marker and is the install owner's.
        if ($this->owner !== '' && ($_SESSION['_owner'] ?? $this->install) !== $this->owner) {
            $_SESSION = [];
        }
        if ($this->owner !== '' && $this->owner !== $this->install) {
            $_SESSION['_owner'] = $this->owner;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    /** New session ID - call on both login and logout. */
    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    /** A cookie of its own next to the session one, with the same path and flags (the "stay signed in" cookie). */
    public function cookie(string $name, string $value, int $expires): void
    {
        if (!headers_sent()) {
            setcookie($name, $value, ['expires' => $expires, 'path' => $this->cookiePath, 'secure' => $this->secure, 'httponly' => true, 'samesite' => 'Lax']);
        }
    }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];
        session_destroy();
        $this->started = false;
    }

    /** One-time message shown after a redirect. Type: "ok" | "error" | "info". */
    public function flash(string $type, string $message): void
    {
        $this->start();
        $_SESSION['_flash'][] = ['type' => $type, 'text' => $message];
    }

    /** @return list<array{typ:string, text:string}> */
    public function takeFlashes(): array
    {
        $this->start();
        $flashes = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return $flashes;
    }

    public function csrfToken(): string
    {
        $token = $this->get('_csrf');
        if (!is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $this->set('_csrf', $token);
        }

        return $token;
    }

    public function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e($this->csrfToken()) . '">';
    }

    public function csrfValid(Request $request): bool
    {
        return hash_equals($this->csrfToken(), $request->post('_csrf'));
    }
}
