<?php

declare(strict_types=1);

namespace Talea\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Talea\Core\MailServices;

final class MailServicesTest extends TestCase
{
    public function testChoicesMatchTheProviders(): void
    {
        $choices = explode('|', MailServices::CHOICES);
        $this->assertSame(MailServices::OTHER, array_shift($choices));
        $this->assertSame(array_keys(MailServices::PROVIDERS), $choices);
    }

    public function testDetectsTheServiceFromTheHost(): void
    {
        $this->assertSame('brevo', MailServices::detect('SMTP-RELAY.brevo.com.'));
        $this->assertSame('ses', MailServices::detect('email-smtp.eu-west-1.amazonaws.com'));
        $this->assertNull(MailServices::detect('smtp.example.com'));
        $this->assertNull(MailServices::detect(''));
    }

    public function testAServerThatAlreadyIsTheServicesStaysAsItIs(): void
    {
        $this->assertNull(MailServices::server('brevo', 'smtp-relay.brevo.com'));
        $this->assertNull(MailServices::server('other', 'smtp.example.com'));
        $this->assertSame(['host' => 'smtp-relay.brevo.com', 'port' => 587, 'encryption' => 'tls'], MailServices::server('brevo', 'smtp.example.com'));
    }

    public function testAnotherSesRegionGivesANewEndpoint(): void
    {
        $this->assertSame('email-smtp.us-east-1.amazonaws.com', MailServices::server('ses', 'email-smtp.eu-west-1.amazonaws.com', 'us-east-1')['host']);
        $this->assertSame('email-smtp.eu-central-1.amazonaws.com', MailServices::host('ses', 'not a region'));
    }
}
