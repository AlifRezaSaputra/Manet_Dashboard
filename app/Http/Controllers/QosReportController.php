<?php

namespace App\Http\Controllers;

use App\Models\QosResult;
use App\Models\QosTest;
use App\Services\QosCalculationService;
use App\Services\QosReportService;

class QosReportController extends Controller
{
    public function pdf($id, QosReportService $reports, QosCalculationService $calculator)
    {
        $test = QosTest::with('nodes')->find($id);

        if (!$test) {
            return response()->json(['success' => false, 'message' => 'Test not found'], 404);
        }

        if ($test->status !== 'completed') {
            return response()->json(['success' => false, 'message' => 'Laporan hanya untuk sesi completed'], 409);
        }

        if (!QosResult::where('qos_test_id', $test->id)->exists()) {
            $calculator->calculate($test);
        }

        return response($reports->render($test), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $test->test_code . '-report.pdf"',
        ]);
    }
}