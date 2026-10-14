<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DocumentCategory;
use App\Models\LeaveType;
use App\Models\MoneyRequestType;
use Illuminate\Http\JsonResponse;

/**
 * Small reference lists the portals need to render their forms:
 * leave and money-request types plus document categories.
 *
 * Read-only and safe for every authenticated caller: employees need
 * these to file their own requests.
 */
class ReferenceController extends Controller
{
    public function leaveTypes(): JsonResponse
    {
        return response()->json([
            'data' => LeaveType::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'annual_allowance_days', 'requires_document']),
        ]);
    }

    public function moneyTypes(): JsonResponse
    {
        return response()->json([
            'data' => MoneyRequestType::where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'requires_receipt']),
        ]);
    }

    public function documentCategories(): JsonResponse
    {
        return response()->json([
            'data' => DocumentCategory::orderBy('name')->get(['id', 'name', 'slug']),
        ]);
    }
}
