<?php

namespace Tests\Feature;

use App\Models\QosEvent;
use App\Models\QosResult;
use App\Models\QosTest;
use App\Services\QosCalculationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Services\QosAirtimeCalculator;

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

        public function test_radio_stats_use_only_valid_rx_at_destination(): void
    {
        $t = $this->makeSession();
        $this->ev($t, 'BASE', 'PACKET_RX', 'DATA', 1, ['has_radio_metrics' => true, 'rssi' => -80, 'snr' => 6]);
        $this->ev($t, 'BASE', 'PACKET_RX', 'DATA', 2, ['has_radio_metrics' => true, 'rssi' => -90, 'snr' => 8]);
        $this->ev($t, 'BASE', 'PACKET_RX', 'DATA', 3, ['has_radio_metrics' => false, 'rssi' => null, 'snr' => null]);
        $this->ev($t, 'NODE02', 'PACKET_RX', 'DATA', 4, ['has_radio_metrics' => true, 'rssi' => -10, 'snr' => 1]);

        (new QosCalculationService)->calculate($t);

        $this->assertEqualsWithDelta(-85.0, $this->metric($t, 'avg_rssi')->metric_value, 0.0001);
        $this->assertEquals(-90, $this->metric($t, 'min_rssi')->metric_value);
        $this->assertEquals(-80, $this->metric($t, 'max_rssi')->metric_value);
        $this->assertEqualsWithDelta(7.0, $this->metric($t, 'avg_snr')->metric_value, 0.0001);
        $this->assertSame(2, (int) $this->metric($t, 'avg_rssi')->sample_count);
    }

    public function test_radio_stats_are_na_without_valid_samples(): void
    {
        $t = $this->makeSession();
        $this->ev($t, 'BASE', 'PACKET_RX', 'DATA', 1, ['has_radio_metrics' => false]);

        (new QosCalculationService)->calculate($t);

        $this->assertNull($this->metric($t, 'avg_rssi')->metric_value);
        $this->assertNull($this->metric($t, 'min_snr')->metric_value);
    }

    public function test_throughput_counts_unique_delivered_payload(): void
    {
        $t = $this->makeSession();
        $t->update(['duration_seconds' => 10]);
        $this->ev($t, 'BASE', 'PACKET_RX', 'DATA', 1, ['payload_length' => 20]);
        $this->ev($t, 'BASE', 'PACKET_RX', 'DATA', 1, ['payload_length' => 20]); // duplikat
        $this->ev($t, 'BASE', 'PACKET_RX', 'DATA', 2, ['payload_length' => 30]);

        (new QosCalculationService)->calculate($t->fresh());

        // (20 + 30) * 8 / 10 = 40 bps
        $this->assertEqualsWithDelta(40.0, $this->metric($t, 'throughput')->metric_value, 0.0001);
    }

    public function test_throughput_is_na_without_duration_and_retransmission_is_na(): void
    {
        $t = $this->makeSession();
        $this->ev($t, 'BASE', 'PACKET_RX', 'DATA', 1, ['payload_length' => 20]);

        (new QosCalculationService)->calculate($t);

        $this->assertNull($this->metric($t, 'throughput')->metric_value);
        $this->assertNull($this->metric($t, 'retransmission_count')->metric_value);
    }

    public function test_event_counts(): void
    {
        $t = $this->makeSession();
        $this->ev($t, 'NODE01', 'PACKET_TX', 'DATA', 1);
        $this->ev($t, 'NODE02', 'PACKET_TX', 'DATA', 1);                 // forwarding
        $this->ev($t, 'BASE', 'PACKET_RX', 'DATA', 1);
        $this->ev($t, 'BASE', 'PACKET_RX', 'DATA', 1);                   // duplikat
        $this->ev($t, 'NODE01', 'PACKET_TX', 'DATA', 2, ['event_status' => 'failed', 'drop_reason' => 'timeout']);

        (new QosCalculationService)->calculate($t);

        $this->assertEquals(3, $this->metric($t, 'data_tx_count')->metric_value);
        $this->assertEquals(2, $this->metric($t, 'data_rx_count')->metric_value);
        $this->assertEquals(1, $this->metric($t, 'forwarding_count')->metric_value);
        $this->assertEquals(1, $this->metric($t, 'duplicate_count')->metric_value);
        $this->assertEquals(1, $this->metric($t, 'dropped_count')->metric_value);
    }

        public function test_airtime_matches_semtech_reference(): void
    {
        $cfg = QosAirtimeCalculator::config(['sf' => 7, 'bw' => 125, 'cr' => 5, 'preamble' => 8]);
        // 20 byte, SF7, BW125, CR4/5, header eksplisit, CRC aktif = 56.576 ms
        $this->assertEqualsWithDelta(56.576, QosAirtimeCalculator::packetSeconds(20, $cfg) * 1000, 0.001);
    }

    public function test_airtime_config_incomplete_returns_null(): void
    {
        $this->assertNull(QosAirtimeCalculator::config(['sf' => 7]));
        $this->assertNull(QosAirtimeCalculator::config(null));
    }

    public function test_overhead_and_airtime_from_tx_events(): void
    {
        $t = $this->makeSession();
        $t->update(['radio_config' => ['sf' => 7, 'bw' => 125, 'cr' => 5, 'preamble' => 8]]);
        $this->ev($t, 'NODE01', 'PACKET_TX', 'DATA', 1, ['payload_length' => 20]);
        $this->ev($t, 'NODE02', 'PACKET_TX', 'DATA', 1, ['payload_length' => 20]);
        $this->ev($t, 'NODE01', 'PACKET_TX', 'HELLO', null, ['payload_length' => 10]);
        $this->ev($t, 'BASE', 'PACKET_TX', 'ACK', null, ['payload_length' => 5]);

        (new QosCalculationService)->calculate($t->fresh());

        $this->assertEquals(2, $this->metric($t, 'control_packet_count')->metric_value);
        $this->assertEquals(15, $this->metric($t, 'control_packet_bytes')->metric_value);
        $this->assertEqualsWithDelta(1.0, $this->metric($t, 'control_to_data_packet_ratio')->metric_value, 0.0001);
        $this->assertEqualsWithDelta(15 / 40, $this->metric($t, 'control_to_data_byte_ratio')->metric_value, 0.0001);

        $dataMs = 2 * 56.576;
        $this->assertEqualsWithDelta($dataMs, $this->metric($t, 'airtime_data')->metric_value, 0.01);
        $total = $this->metric($t, 'airtime_total');
        $this->assertSame('theoretical', $total->metadata['kind']);
        $this->assertSame(2, $total->metadata['by_node']['NODE01']['transmissions']);
        $this->assertSame(1, $total->metadata['by_node']['BASE']['transmissions']);
    }

    public function test_airtime_is_na_without_radio_config_and_delay_is_always_na(): void
    {
        $t = $this->makeSession();
        $this->ev($t, 'NODE01', 'PACKET_TX', 'DATA', 1, ['payload_length' => 20]);

        (new QosCalculationService)->calculate($t);

        $this->assertNull($this->metric($t, 'airtime_total')->metric_value);
        $this->assertNull($this->metric($t, 'e2e_delay')->metric_value);
        $this->assertEquals(0, $this->metric($t, 'control_packet_count')->metric_value);
    }
}