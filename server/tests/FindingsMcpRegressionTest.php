<?php
declare(strict_types=1);

namespace StretchEffect\Tests;

require_once __DIR__ . '/Support/FakeTestCase.php';

use StretchEffect\Tests\Support\FakeTestCase;

/** Fuzz finding: a tools/call rejected before it ran (unknown tool, arguments not an object) left no audit row. */
final class FindingsMcpRegressionTest extends FakeTestCase
{
    private function rejectedCount(): int
    {
        return (int) $this->svc->database->pdo->query("SELECT COUNT(*) FROM audit_log WHERE action IN ('tools/call','get_schedule') AND status = 'error'")->fetchColumn();
    }

    public function testRejectedToolsCallsAreAudited(): void
    {
        $c = $this->connect();
        $before = $this->rejectedCount();
        $d = $this->rpc($c['access'], 'tools/call', ['name' => "no_such_tool\x07", 'arguments' => (object) []]);
        $this->assertSame(-32602, $d['error']['code']);
        $this->assertSame($before + 1, $this->rejectedCount(), 'unknown tool is not audited');
        $r = $this->mcpRaw($c['access'], json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'get_schedule', 'arguments' => 'oops']]));
        $this->assertSame(200, $r->status);
        $this->assertSame($before + 2, $this->rejectedCount(), 'arguments that are not an object are not audited');
        $rows = $this->svc->database->pdo->query("SELECT action, args_json, summary FROM audit_log WHERE status = 'error'")->fetchAll();
        $this->assertStringNotContainsString('no_such_tool', json_encode($rows), 'the attacker-chosen tool name must not be logged');
    }
}
