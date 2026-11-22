<?php

namespace App\Services;

use App\Http\Controllers\QosResultController;
use App\Models\QosEvent;
use App\Models\QosResult;
use App\Models\QosTest;
use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;

class QosReportService
{
    public const GROUP_TITLES = [
        'delivery'    => 'Delivery Performance',
        'performance' => 'Performance',
        'radio'       => 'Radio Quality',
        'routing'     => 'Routing Performance',
        'airtime'     => 'LoRa Airtime (teoretis)',
        'events'      => 'Pengumpulan Event',
    ];

    public const LABELS = [
        'pdr' => 'Packet Delivery Ratio (PDR)', 'packet_loss' => 'Packet Loss',
        'generated_packets' => 'Paket dibuat sumber', 'delivered_packets' => 'Paket terkirim (unik)',
        'lost_packets' => 'Paket hilang', 'throughput' => 'Throughput aplikasi',
        'e2e_delay' => 'End-to-End Delay',
        'avg_rssi' => 'RSSI rata-rata', 'min_rssi' => 'RSSI minimum', 'max_rssi' => 'RSSI maksimum',
        'avg_snr' => 'SNR rata-rata', 'min_snr' => 'SNR minimum', 'max_snr' => 'SNR maksimum',
        'avg_hop_count' => 'Hop count rata-rata', 'forwarding_count' => 'Transmisi forwarding',
        'retransmission_count' => 'Retransmisi', 'control_packet_count' => 'Paket kontrol',
        'control_packet_bytes' => 'Byte paket kontrol',
        'control_to_data_packet_ratio' => 'Rasio kontrol:DATA (paket)',
        'control_to_data_byte_ratio' => 'Rasio kontrol:DATA (byte)',
        'airtime_data' => 'Airtime DATA', 'airtime_control' => 'Airtime kontrol',
        'airtime_total' => 'Airtime total', 'airtime_avg_per_tx' => 'Airtime rata-rata per transmisi',
        'data_tx_count' => 'Event TX DATA', 'data_rx_count' => 'Event RX DATA',
        'duplicate_count' => 'Duplikat RX', 'dropped_count' => 'Event drop/gagal',
    ];

    public function render(QosTest $test): string
    {
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('qos.report', $this->data($test))->render(), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    public function data(QosTest $test): array
    {
        $test->loadMissing('nodes');
        $rows = QosResult::where('qos_test_id', $test->id)->get()->keyBy('metric_key');

        $groups = [];
        foreach (QosResultController::GROUPS as $group => $keys) {
            foreach ($keys as $key) {
                if (!isset($rows[$key])) {
                    continue;
                }
                $r = $rows[$key];
                $groups[$group][] = [
                    'key'       => $key,
                    'label'     => self::LABELS[$key] ?? $key,
                    'available' => $r->metric_value !== null,
                    'display'   => $this->display($r),
                    'samples'   => $r->sample_count,
                    'reason'    => $r->metadata['reason'] ?? null,
                    'note'      => $r->metadata['note'] ?? null,
                    'estimate'  => ($r->metadata['denominator_method'] ?? null) === 'sequence_gap_estimate',
                ];
            }
        }

        // Bar delivery
        $delivery = null;
        $d = $rows->get('delivered_packets');
        $l = $rows->get('lost_packets');
        if ($d && $l && $d->metric_value !== null && $l->metric_value !== null) {
            $total = $d->metric_value + $l->metric_value;
            if ($total > 0) {
                $delivery = [
                    'delivered' => (int) $d->metric_value,
                    'lost'      => (int) $l->metric_value,
                    'pct'       => round($d->metric_value / $total * 100, 1),
                ];
            }
        }

        // Airtime per node (dari metadata airtime_total)
        $byNode = $rows->get('airtime_total')?->metadata['by_node'] ?? [];
        $maxAir = max(array_merge([1], array_column($byNode, 'airtime_ms')));
        $airtimeNodes = [];
        foreach ($byNode as $node => $r) {
            $airtimeNodes[] = [
                'node' => $node,
                'transmissions' => $r['transmissions'],
                'airtime_ms' => $r['airtime_ms'],
                'pct' => round($r['airtime_ms'] / $maxAir * 100, 1),
            ];
        }

        // Konfigurasi radio
        $cfg = QosAirtimeCalculator::config($test->radio_config);
        $radioRows = $cfg ? [
            'Spreading factor'          => 'SF' . $cfg['sf'],
            'Bandwidth'                 => $cfg['bw_khz'] . ' kHz',
            'Coding rate'               => '4/' . $cfg['cr'],
            'Preamble'                  => $cfg['preamble'] . ' simbol',
            'Mode header'               => $cfg['explicit_header'] ? 'Eksplisit' : 'Implisit',
            'CRC'                       => $cfg['crc'] ? 'Aktif' : 'Nonaktif',
            'Low data rate optimization' => $cfg['ldro'] ? 'Aktif' : 'Nonaktif',
        ] : null;

        // Statistik event
        $byType = QosEvent::where('qos_test_id', $test->id)
            ->selectRaw('event_type, packet_type, COUNT(*) as total')
            ->groupBy('event_type', 'packet_type')
            ->orderBy('event_type')->orderBy('packet_type')
            ->get();
        $span = QosEvent::where('qos_test_id', $test->id)
            ->selectRaw('COUNT(*) as total, MIN(collected_at) as first_at, MAX(collected_at) as last_at')
            ->first();

        // Jalur aktual dari "path=" pada raw_event (RX DATA di tujuan)
        $intended = implode('>', $test->topology_config['path'] ?? []);
        $counts = [];
        $q = QosEvent::where('qos_test_id', $test->id)
            ->where('event_type', 'PACKET_RX')->where('packet_type', 'DATA')
            ->where('raw_event', 'like', '%path=%')
            ->select('id', 'raw_event');
        foreach ($q->cursor() as $e) {
            if (preg_match('/path=([^,\s|]+)/', $e->raw_event, $m)) {
                $counts[$m[1]] = ($counts[$m[1]] ?? 0) + 1;
            }
        }
        arsort($counts);
        $observed = [];
        foreach ($counts as $path => $count) {
            $observed[] = ['path' => $path, 'count' => $count, 'matches_plan' => $path === $intended];
        }

        return [
            'test'          => $test,
            'intended_path' => implode(' → ', $test->topology_config['path'] ?? []),
            'started'       => $this->wib($test->started_at),
            'ended'         => $this->wib($test->ended_at),
            'created'       => $this->wib($test->created_at),
            'generated_at'  => $this->wib(now()),
            'radio_rows'    => $radioRows,
            'groups'        => $groups,
            'group_titles'  => self::GROUP_TITLES,
            'delivery'      => $delivery,
            'airtime_nodes' => $airtimeNodes,
            'event_total'   => (int) ($span->total ?? 0),
            'event_first'   => $this->wib($span->first_at ?? null),
            'event_last'    => $this->wib($span->last_at ?? null),
            'event_types'   => $byType,
            'observed_paths' => $observed,
            'calculated_at' => $this->wib($rows->max('calculated_at')),
        ];
    }

    private function display(QosResult $r): string
    {
        if ($r->metric_value === null) {
            return 'N/A';
        }

        $v = (float) $r->metric_value;
        $text = floor($v) == $v ? number_format($v, 0, ',', '.') : number_format($v, 2, ',', '.');
        $unit = in_array($r->unit, [null, '', 'ratio'], true) ? '' : ' ' . $r->unit;

        return $text . $unit;
    }

    private function wib($value): string
    {
        if (!$value) {
            return '-';
        }

        return Carbon::parse($value, 'UTC')->timezone('Asia/Jakarta')->format('d M Y H:i:s') . ' WIB';
    }
}