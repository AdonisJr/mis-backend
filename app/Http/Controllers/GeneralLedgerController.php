<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShowGeneralLedgerRequest;
use App\Models\Account;
use App\Support\GeneralLedgerBuilder;
use Illuminate\Http\JsonResponse;

class GeneralLedgerController extends Controller
{
    public function showGeneralLedger(ShowGeneralLedgerRequest $request): JsonResponse
    {
        $ledgerBuilder = new GeneralLedgerBuilder(Account::findOrFail($request->validated('account_id')));

        return response()->json([
            'ledger' => $ledgerBuilder->build($request->validated('from'), $request->validated('to')),
        ]);
    }
}
