<?php

namespace App\Modules\Reports\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Services\ReportService;

class ReportController extends Controller
{
    public function overview(ReportService $reports)
    {
        return $this->successResponse(
            $reports->overview(),
            'Report overview retrieved successfully.'
        );
    }
}
