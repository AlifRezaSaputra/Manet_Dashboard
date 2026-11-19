<?php

namespace Tests\Feature;

use App\Models\QosEvent;
use App\Models\QosResult;
use App\Models\QosTest;
use App\Services\QosCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QosCalculationTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    private function makeSession(): QosTest
    {
        return QosTest::create([
            'test_code' => 'QOS-TEST-' . (++$this->n), 'name' => 'T',
            'topology_type' => 'direct', 'source_node_id' => 'NODE01',
            'destination_node_id' => 'BASE', 'topology_config' => ['path' => ['NODE01', 'BASE']],
            'status' => 'completed',
        ]);
    }

    private function ev(QosTest $t, string $node, string $type, string $pkt, ?int $seq, array $over = []): void
    {
        QosEvent::create(array_merge([
            'qos_test_id' => $t->id, 'event_uuid' => 'u-' . (++$this->n),
            'node_id' => $node, 'event_type' => $type, 'packet_type' => $pkt,
            'source_id' => 'NODE01', 'destination_id' => 'BASE',
            'boot_id' => 1, 'sequence_number' => $seq,
            'raw_event' => 'x', 'collected_at' => '2026-11-19 03:00:00',
        ], $over));
    }

    private function metric(QosTest $t, string $key): QosResult
    {
        return QosResult::where('qos_test_id', $t->id)->where('metric_key', $key)->firstOrFail();
    }

    public function test_pdr_with_tx_events_ignores_duplicates_relays_and_hello(): void
    {
        $t = $this->makeSession();
        foreach (range(1, 10) as $s) $this->ev($t, 'NODE01', 'PACKET_TX', 'DATA', $s);
        foreach (range(1, 8) as $s) $this->ev($t, 'BASE', 'PACKET_RX', 'DATA', $s, ['hop_count' => $s % 2 ? 1 : 2]);
        $this->ev($t, 'BASE', 'PACKET_RX', 'DATA', 1);               // duplikat
        $this->ev($t, 'NODE02', 'PACKET_RX', 'DATA', 9);             // di relay, bukan tujuan
        $this->ev($t, 'BASE', 'PACKET_RX', 'HELLO', null);           // bukan DATA

        (new QosCalculationService)->calculate($t);

        $this->assertEqualsWithDelta(80.0, $this->metric($t, 'pdr')->metric_value, 0.0001);
        $this->assertEqualsWithDelta(20.0, $this->metric($t, 'packet_loss')->metric_value, 0.0001);
        $this->assertEquals(8, $this->metric($t, 'delivered_packets')->metric_value);
        $this->assertEquals(10, $this->metric($t, 'generated_packets')->metric_value);
        $this->assertEquals(2, $this->metric($t, 'lost_packets')->metric_value);
        $this->assertSame('tx_events', $this->metric($t, 'pdr')->metadata['denominator_method']);
        $this->assertEqualsWithDelta(1.5, $this->metric($t, 'avg_hop_count')->metric_value, 0.0001);
    }

    public function test_pdr_falls_back_to_sequence_gap_estimate(): void
    {
        $t = $this->makeSession();
        foreach ([1, 2, 4, 5] as $s) $this->ev($t, 'BASE', 'PACKET_RX', 'DATA', $s);

        (new QosCalculationService)->calculate($t);

        $pdr = $this->metric($t, 'pdr');
        $this->assertEqualsWithDelta(80.0, $pdr->metric_value, 0.0001);
        $this->assertSame('sequence_gap_estimate', $pdr->metadata['denominator_method']);
        $this->assertNotEmpty($pdr->metadata['note']);
    }

    public function test_no_data_gives_na_not_zero(): void
    {
        $t = $this->makeSession();
        (new QosCalculationService)->calculate($t);

        $this->assertNull($this->metric($t, 'pdr')->metric_value);
        $this->assertNull($this->metric($t, 'avg_hop_count')->metric_value);
        $this->assertNotEmpty($this->metric($t, 'pdr')->metadata['reason']);
    }

    public function test_tx_without_delivery_is_zero_pdr_not_na(): void
    {
        $t = $this->makeSession();
        foreach (range(1, 5) as $s) $this->ev($t, 'NODE01', 'PACKET_TX', 'DATA', $s);

        (new QosCalculationService)->calculate($t);

        $this->assertSame(0.0, $this->metric($t, 'pdr')->metric_value);
        $this->assertEquals(100.0, $this->metric($t, 'packet_loss')->metric_value);
    }

    public function test_inconsistent_data_gives_na(): void
    {
        $t = $this->makeSession();
        $this->ev($t, 'NODE01', 'PACKET_TX', 'DATA', 1);
        foreach ([1, 2, 3] as $s) $this->ev($t, 'BASE', 'PACKET_RX', 'DATA', $s);

        (new QosCalculationService)->calculate($t);

        $this->assertNull($this->metric($t, 'pdr')->metric_value);
    }

    public function test_recalculation_updates_instead_of_duplicating(): void
    {
        $t = $this->makeSession();
        $this->ev($t, 'BASE', 'PACKET_RX', 'DATA', 1);

        $svc = new QosCalculationService;
        $svc->calculate($t);
        $count = QosResult::where('qos_test_id', $t->id)->count();
        $svc->calculate($t);

        $this->assertSame($count, QosResult::where('qos_test_id', $t->id)->count());
    }
}