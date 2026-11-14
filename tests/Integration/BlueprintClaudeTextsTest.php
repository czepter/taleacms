<?php

declare(strict_types=1);

namespace Talea\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use Talea\Core\Blueprint;
use Talea\Tests\Support\DatabaseTestCase;

/** The instructions of the applied blueprints come in the order of their keys, not of applied_at (a tie of one second flips on PostgreSQL), so the fingerprint is stable (#334). */
#[CoversClass(Blueprint::class)]
final class BlueprintClaudeTextsTest extends DatabaseTestCase
{
    public function testTheTextsAreSortedByKeyWhateverTheRowOrder(): void
    {
        foreach (['zeta' => 0, 'alpha' => 5, 'mid' => 3] as $key => $seconds) { // applied in an order that is not the key order
            $at = date('Y-m-d H:i:s', time() - 60 + $seconds);
            $manifest = ['talea_blueprint' => 1, 'key' => $key, 'name' => ucfirst($key), 'group' => 'other', 'presets' => [], 'facts' => [], 'questions' => [], 'audit' => [], 'claude' => 'Text of ' . $key . '.'];
            $this->db()->run('INSERT INTO {blueprints} (bkey, name, manifest, applied_at) VALUES (?, ?, ?, ?)', [$key, ucfirst($key), json_encode($manifest), $at]);
        }
        $this->assertSame(['alpha', 'mid', 'zeta'], array_keys(Blueprint::claudeTexts($this->db())));
    }
}
