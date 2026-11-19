<?php

namespace App\Services;

class QosAirtimeCalculator
{
    /** Normalisasi radio_config. Null jika parameter wajib belum lengkap. */
    public static function config(?array $rc): ?array
    {
        if (!$rc) return null;
        foreach (['sf', 'bw', 'cr', 'preamble'] as $k) {
            if (!isset($rc[$k]) || !is_numeric($rc[$k])) return null;
        }

        $sf = (int) $rc['sf'];
        $bwHz = (float) $rc['bw'] * 1000;
        $cr = (int) $rc['cr'];
        if ($sf < 6 || $sf > 12 || $bwHz <= 0 || $cr < 5 || $cr > 8) return null;

        $tsym = (2 ** $sf) / $bwHz;

        return [
            'sf'              => $sf,
            'bw_khz'          => (float) $rc['bw'],
            'cr'              => $cr,
            'preamble'        => (int) $rc['preamble'],
            'explicit_header' => (bool) ($rc['explicit_header'] ?? true),
            'crc'             => (bool) ($rc['crc'] ?? true),
            'ldro'            => isset($rc['ldro']) ? (bool) $rc['ldro'] : $tsym > 0.016,
        ];
    }

    /** Time-on-air satu paket, dalam detik (rumus Semtech SX127x). */
    public static function packetSeconds(int $payloadBytes, array $c): float
    {
        $tsym = (2 ** $c['sf']) / ($c['bw_khz'] * 1000);
        $tPreamble = ($c['preamble'] + 4.25) * $tsym;

        $ih  = $c['explicit_header'] ? 0 : 1;
        $crc = $c['crc'] ? 1 : 0;
        $de  = $c['ldro'] ? 1 : 0;

        $num = 8 * $payloadBytes - 4 * $c['sf'] + 28 + 16 * $crc - 20 * $ih;
        $den = 4 * ($c['sf'] - 2 * $de);
        $symbols = 8 + max((int) ceil($num / $den) * ($c['cr']), 0);

        return $tPreamble + $symbols * $tsym;
    }
}