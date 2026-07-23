<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Services\AccountingLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountingTransactionController extends ApiController
{
    public function __construct(
        protected AccountingLedgerService $ledger,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'administrative_area' => ['nullable', 'string', 'max:120'],
            'market' => ['nullable', Rule::in(['all', 'domestic', 'international'])],
            'direction' => ['nullable', Rule::in(['all', 'inflow', 'outflow'])],
            'status' => ['nullable', 'string', 'max:32'],
            'search' => ['nullable', 'string', 'max:160'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:200'],
        ]);

        $result = $this->ledger->build($filters);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = min(200, max(10, (int) ($filters['per_page'] ?? 50)));
        $total = $result['items']->count();

        return response()->json([
            'items' => $result['items']
                ->slice(($page - 1) * $perPage, $perPage)
                ->values(),
            'summary' => $result['summary'],
            'options' => $result['options'],
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)),
            ],
            'applied_filters' => $filters,
        ]);
    }
}
