<?php

declare(strict_types=1);

namespace Kaleta\Tests\Site\ImportersBooking;

use Kaleta\Tests\Site\Support\Response;

/**
 * The state the old booking section (96) built up in its first lines: mail that fails (so every e-mail keeps its body in the queue and the
 * cancel link can be read from it), the Bookings feature switched on over MCP, a service, a person with weekly hours and a page with the
 * Booking element. Each test class that uses it gets its own copy (static state per class).
 */
trait BookingFixture
{
    private static bool $bookingReady = false;
    private static int $service = 0;
    private static int $staff = 0;
    private static int $page = 0;
    private static string $source = '';
    private static int $formTime = 0;
    private static string $signature = '';
    private static string $day = '';
    private static string $extensionsBefore = '';

    /** Prepares everything once per class; call it from the first line of every test that needs it. */
    private function bookingFixture(): void
    {
        if (self::$bookingReady) {
            return;
        }
        $this->bookingMail();
        $this->bookingSwitchOn();
        $this->bookingStaffAndService();
        $this->bookingPage();
        $this->bookingForm();
        self::$bookingReady = true;
    }

    /** Mail must fail, so e-mails keep their body in the queue; the token for cron is known. */
    private function bookingMail(): void
    {
        $site = $this->site();
        foreach (['mail_mode' => 'smtp', 'smtp_host' => '127.0.0.1', 'smtp_port' => '1', 'smtp_encryption' => 'zadne', 'smtp_user' => '', 'mail_from' => 'web@example.cz', 'tasks_token' => 'testtoken123', 'enquiries_months' => '24'] as $name => $value) {
            $site->setting($name, $value);
        }
    }

    /** Switched on the way Claude is told to: update_settings with "bookings" added to extensions. */
    private function bookingSwitchOn(): void
    {
        self::$extensionsBefore = $this->site()->settingValue('extensions');
        $this->site()->mcp('update_settings', ['settings' => ['extensions' => array_merge(explode(',', self::$extensionsBefore), ['bookings'])]]);
    }

    private function bookingStaffAndService(): string
    {
        $this->bookingText('save_booking_service', ['name' => 'Střih test', 'duration_min' => 30, 'buffer_min' => 10, 'price_text' => '450 Kč', 'description' => 'Mytí, střih, foukaná']);
        self::$service = $this->firstId($this->site()->value('SELECT id FROM ka_booking_services ORDER BY id DESC LIMIT 1'));
        $hours = array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], '9:00-17:00');
        $text = $this->bookingText('save_booking_staff', ['name' => 'Jana Rezervace', 'email' => 'jana-bk@example.cz', 'services' => [self::$service], 'hours' => $hours]);
        self::$staff = $this->firstId($this->site()->value('SELECT id FROM ka_booking_staff ORDER BY id DESC LIMIT 1'));

        return $text;
    }

    private function firstId(mixed $id): int
    {
        return (int) $id;
    }

    /** The text an MCP tool returned (raw). @param array<string, mixed> $arguments */
    private function bookingText(string $tool, array $arguments): string
    {
        return (string) ($this->site()->mcp($tool, $arguments)['result']['content'][0]['text'] ?? '');
    }

    /** The whole raw JSON-RPC answer as one string (the old tests grepped the response file). @param array<string, mixed> $arguments */
    private function bookingRaw(string $tool, array $arguments): string
    {
        return (string) json_encode($this->site()->mcp($tool, $arguments), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function bookingPage(): void
    {
        $this->site()->mcp('vytvor_stranku', ['title' => 'Rezervace test', 'adresa' => 'rezervace-test', 'visible' => true]);
        self::$page = (int) $this->site()->value("SELECT page_id FROM ka_pages WHERE slug = 'rezervace-test'");
        $this->site()->mcp('stavba_uloz', ['id' => self::$page, 'publikovat' => true, 'build' => ['v' => 1, 'deti' => [['type' => 'sekce', 'deti' => [
            ['type' => 'nadpis', 'znacka' => 'h1', 'obsah' => ['text' => 'Objednejte se']],
            ['id' => 'bk1', 'type' => 'rezervace', 'obsah' => new \stdClass()],
        ]]]]]);
    }

    /** Reads the signed form fields from the page; the time signature is made the way the server checks it. */
    private function bookingForm(): Response
    {
        $page = $this->site()->client()->get('/rezervace-test');
        self::$source = $page->field('source');
        self::$formTime = time() - 10;
        self::$signature = hash_hmac('sha256', 'rezervace|' . self::$source . '|bk1|' . self::$formTime, $this->site()->settingValue('secret_key'));
        self::$day = trim($this->site()->php('echo date("Y-m-d", strtotime("+3 days"));'));

        return $page;
    }

    /** A visitor's POST to /_booking; returns the redirect address (the result code is in it). @param array<string, string> $fields */
    private function book(array $fields): string
    {
        return $this->site()->client('visitor')->post('/_booking', [
            'source' => self::$source, 'element' => 'bk1', 'zpet' => '/rezervace-test', 'as_cas' => self::$formTime, 'as_podpis' => self::$signature,
            'service' => self::$service, 'staff' => 0,
        ] + $fields)->redirect;
    }

    private function slots(): Response
    {
        return $this->site()->client()->get('/_booking/slots?service=' . self::$service . '&staff=0&day=' . self::$day);
    }

    /** The cancel token in the confirmation e-mail of petr-bk (first or last one). */
    private function cancelToken(string $order, string $to = 'petr-bk@example.cz'): string
    {
        $body = (string) $this->site()->value("SELECT body FROM ka_mail WHERE recipient = ? ORDER BY mail_id $order LIMIT 1", [$to]);
        $text = (string) (json_decode($body, true)['text'] ?? '');

        return preg_match('#_booking/cancel/([a-f0-9]{32})#', $text, $m) === 1 ? $m[1] : '';
    }

    private function cancelBooking(string $token): Response
    {
        return $this->site()->client('visitor')->post('/_booking/cancel/' . ($token !== '' ? $token : '0000000000000000000000000000000a'), ['zrusit' => '1']);
    }

    private function q(string $sql): string
    {
        return (string) $this->site()->value($sql);
    }
}
