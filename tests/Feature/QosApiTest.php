<?php

namespace Tests\Feature;

use App\Models\QosEvent;
use App\Models\QosTest;
use App\Support\AdminToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QosApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['qos.enabled' => true]);
        config(['admin.password_hash' => bcrypt('x')]);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer ' . AdminToken::issue()];
    }

    private function makeTest(string $type = 'direct', ?array $nodes = null): int
    {
        $nodes ??= ['NODE01', 'BASE'];
        $res = $this->postJson('/api/qos/tests', [
            'name' => 'T', 'topology_type' => $type, 'nodes' => $nodes,
        ], $this->auth());
        $res->assertStatus(201);
        return $res->json('data.id');
    }

    private function event(string $uuid, array $over = []): array
    {
        return array_merge([
            'event_uuid' => $uuid, 'node_id' => 'BASE', 'event_type' => 'PACKET_RX',
            'packet_type' => 'DATA', 'source_id' => 'NODE01', 'destination_id' => 'BASE',
            'boot_id' => 1, 'sequence_number' => 1, 'has_radio_metrics' => true,
            'rssi' => -80, 'snr' => 7.5, 'collected_at' => '2026-11-19 03:00:00',
            'raw_event' => 'RX DATA NODE01 seq=1',
        ], $over);
    }

    public function test_module_disabled_returns_404(): void
    {
        config(['qos.enabled' => false]);
        $this->getJson('/api/qos/status')->assertStatus(404);
        $this->getJson('/api/qos/tests', $this->auth())->assertStatus(404);
    }

    public function test_requires_admin_token(): void
    {
        $this->getJson('/api/qos/tests')->assertStatus(401);
    }

    public function test_create_session_generates_code_and_nodes(): void
    {
        $res = $this->postJson('/api/qos/tests', [
            'name' => 'T', 'topology_type' => 'two_relays',
            'nodes' => ['NODE01', 'NODE02', 'NODE03', 'BASE'],
        ], $this->auth());

        $res->assertStatus(201)->assertJsonPath('data.status', 'pending');
        $this->assertMatchesRegularExpression('/^QOS-\d{8}-001$/', $res->json('data.test_code'));
        $this->assertSame(
            ['source', 'relay', 'relay', 'destination'],
            collect($res->json('data.nodes'))->pluck('node_role')->all()
        );
    }

    public function test_node_count_must_match_topology(): void
    {
        $this->postJson('/api/qos/tests', [
            'name' => 'T', 'topology_type' => 'direct', 'nodes' => ['NODE01', 'NODE02', 'BASE'],
        ], $this->auth())->assertStatus(422);
    }

    public function test_start_then_start_again_is_conflict(): void
    {
        $id = $this->makeTest();
        $this->postJson("/api/qos/tests/$id/start", [], $this->auth())
            ->assertOk()->assertJsonPath('data.status', 'recording');
        $this->postJson("/api/qos/tests/$id/start", [], $this->auth())->assertStatus(409);
    }

    public function test_only_one_session_can_record_at_a_time(): void
    {
        $a = $this->makeTest();
        $b = $this->makeTest();
        $this->postJson("/api/qos/tests/$a/start", [], $this->auth())->assertOk();
        $this->postJson("/api/qos/tests/$b/start", [], $this->auth())->assertStatus(409);
        $this->assertSame('pending', QosTest::find($b)->status);
    }

    public function test_stop_requires_recording_and_completes_session(): void
    {
        $id = $this->makeTest();
        $this->postJson("/api/qos/tests/$id/stop", [], $this->auth())->assertStatus(409);

        $this->postJson("/api/qos/tests/$id/start", [], $this->auth())->assertOk();
        $this->postJson("/api/qos/tests/$id/stop", [], $this->auth())
            ->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertNotNull(QosTest::find($id)->duration_seconds);
    }

    public function test_events_rejected_when_not_recording(): void
    {
        $id = $this->makeTest();
        $this->postJson("/api/qos/tests/$id/events", ['events' => [$this->event('u1')]])
            ->assertStatus(409);
        $this->assertSame(0, QosEvent::count());
    }

    public function test_events_stop_after_session_stopped(): void
    {
        $id = $this->makeTest();
        $this->postJson("/api/qos/tests/$id/start", [], $this->auth());
        $this->postJson("/api/qos/tests/$id/stop", [], $this->auth());
        $this->postJson("/api/qos/tests/$id/events", ['events' => [$this->event('u1')]])
            ->assertStatus(409);
    }

    public function test_event_ingestion_dedup_and_rejection(): void
    {
        $id = $this->makeTest();
        $this->postJson("/api/qos/tests/$id/start", [], $this->auth());

        $this->postJson("/api/qos/tests/$id/events", ['events' => [$this->event('u1')]])
            ->assertStatus(201)->assertJsonPath('accepted', 1);

        $this->postJson("/api/qos/tests/$id/events", ['events' => [$this->event('u1')]])
            ->assertJsonPath('accepted', 0)->assertJsonPath('duplicates', 1);

        $bad = $this->event('u2');
        unset($bad['raw_event']);
        $res = $this->postJson("/api/qos/tests/$id/events", ['events' => [$bad, $this->event('u3')]]);
        $res->assertJsonPath('accepted', 1);
        $this->assertCount(1, $res->json('rejected'));

        $this->assertSame(2, QosEvent::count());
    }

    public function test_radio_metrics_are_null_when_flag_is_false(): void
    {
        $id = $this->makeTest();
        $this->postJson("/api/qos/tests/$id/start", [], $this->auth());
        $this->postJson("/api/qos/tests/$id/events", ['events' => [
            $this->event('u1', ['has_radio_metrics' => false, 'rssi' => -50, 'snr' => 3]),
        ]])->assertJsonPath('accepted', 1);

        $e = QosEvent::first();
        $this->assertNull($e->rssi);
        $this->assertNull($e->snr);
    }

    public function test_events_are_immutable(): void
    {
        $id = $this->makeTest();
        $this->postJson("/api/qos/tests/$id/start", [], $this->auth());
        $this->postJson("/api/qos/tests/$id/events", ['events' => [$this->event('u1')]]);

        $e = QosEvent::first();
        $this->expectException(\LogicException::class);
        $e->update(['node_id' => 'X']);
    }

        public function test_events_index_returns_counts_and_latest(): void
    {
        $id = $this->makeTest();
        $this->postJson("/api/qos/tests/$id/start", [], $this->auth());
        $this->postJson("/api/qos/tests/$id/events", ['events' => [
            $this->event('u1'),
            $this->event('u2', ['packet_type' => 'HELLO', 'sequence_number' => null]),
            $this->event('u3', ['sequence_number' => 2]),
        ]])->assertJsonPath('accepted', 3);

        $res = $this->getJson("/api/qos/tests/$id/events?limit=2", $this->auth());
        $res->assertOk()->assertJsonPath('data.total', 3);
        $this->assertCount(2, $res->json('data.latest'));
        $this->assertSame(3, collect($res->json('data.by_type'))->sum('total'));

        $this->getJson("/api/qos/tests/$id/events")->assertStatus(401);
        $this->getJson('/api/qos/tests/99999/events', $this->auth())->assertStatus(404);
    }

    public function test_stop_calculates_results(): void
    {
        $id = $this->makeTest();
        $this->postJson("/api/qos/tests/$id/start", [], $this->auth());
        $this->postJson("/api/qos/tests/$id/events", ['events' => [$this->event('u1')]]);

        $this->postJson("/api/qos/tests/$id/stop", [], $this->auth())
            ->assertOk()->assertJsonPath('calculated', true);

        $this->assertGreaterThan(0, \App\Models\QosResult::where('qos_test_id', $id)->count());
    }

    public function test_active_test_endpoint(): void
    {
        $this->getJson('/api/qos/active-test')->assertOk()->assertJsonPath('active', false);

        $id = $this->makeTest();
        $this->postJson("/api/qos/tests/$id/start", [], $this->auth())->assertOk();

        $this->getJson('/api/qos/active-test')
            ->assertOk()->assertJsonPath('active', true)->assertJsonPath('data.id', $id);
    }

    public function test_results_are_grouped_and_na_is_flagged(): void
    {
        $id = $this->makeTest();
        $this->postJson("/api/qos/tests/$id/start", [], $this->auth());
        $this->postJson("/api/qos/tests/$id/events", ['events' => [$this->event('u1')]]);
        $this->postJson("/api/qos/tests/$id/stop", [], $this->auth());

        $res = $this->getJson("/api/qos/tests/$id/results", $this->auth())->assertOk();
        $res->assertJsonPath('data.calculated', true);

        $perf = collect($res->json('data.groups.performance'))->keyBy('key');
        $this->assertFalse($perf['e2e_delay']['available']);
        $this->assertNull($perf['e2e_delay']['value']);
        $this->assertNotEmpty($perf['e2e_delay']['reason']);

        $delivery = collect($res->json('data.groups.delivery'))->keyBy('key');
        $this->assertTrue($delivery['delivered_packets']['available']);
        $this->assertSame([], $res->json('data.groups.other'));
    }

    public function test_results_before_calculation_and_recalculate_rules(): void
    {
        $id = $this->makeTest();

        $this->getJson("/api/qos/tests/$id/results", $this->auth())
            ->assertOk()->assertJsonPath('data.calculated', false);

        $this->postJson("/api/qos/tests/$id/calculate", [], $this->auth())->assertStatus(409);

        $this->postJson("/api/qos/tests/$id/start", [], $this->auth());
        $this->postJson("/api/qos/tests/$id/stop", [], $this->auth());

        $before = \App\Models\QosResult::where('qos_test_id', $id)->count();
        $this->postJson("/api/qos/tests/$id/calculate", [], $this->auth())->assertOk();
        $this->assertSame($before, \App\Models\QosResult::where('qos_test_id', $id)->count());
    }

    public function test_results_require_auth_and_existing_test(): void
    {
        $this->getJson('/api/qos/tests/1/results')->assertStatus(401);
        $this->getJson('/api/qos/tests/99999/results', $this->auth())->assertStatus(404);
    }

    public function test_invalid_lora_bandwidth_is_rejected(): void
    {
        $this->postJson('/api/qos/tests', [
            'name' => 'T', 'topology_type' => 'direct', 'nodes' => ['NODE01', 'BASE'],
            'radio_config' => ['sf' => 7, 'bw' => 16, 'cr' => 5, 'preamble' => 8],
        ], $this->auth())->assertStatus(422);

        $this->postJson('/api/qos/tests', [
            'name' => 'T', 'topology_type' => 'direct', 'nodes' => ['NODE01', 'BASE'],
            'radio_config' => ['sf' => 7, 'bw' => 125, 'cr' => 5, 'preamble' => 8],
        ], $this->auth())->assertStatus(201);
    }
}