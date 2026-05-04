<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Accounting\Domain\Journal;
use App\Modules\Accounting\Http\Requests\StoreJournalRequest;
use App\Modules\Accounting\Http\Requests\UpdateJournalRequest;
use App\Modules\Accounting\Http\Resources\JournalResource;
use App\Modules\Accounting\Services\AccountingService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class JournalController extends Controller
{
    public function __construct(
        private readonly AccountingService $accountingService,
    ) {}

    public function index(): JsonResponse
    {
        $tenantId = (int) request()->query('tenantId', 0);
        $journals = $this->accountingService->listJournals($tenantId > 0 ? $tenantId : null);

        return response()->json([
            'data' => JournalResource::collection($journals),
        ]);
    }

    public function store(StoreJournalRequest $request): JsonResponse
    {
        $v = $request->validated();

        $journal = $this->accountingService->createJournal([
            'tenant_id' => $v['tenantId'] ?? null,
            'journal_no' => $v['journalNo'] ?? null,
            'journal_date' => $v['journalDate'],
            'description' => $v['description'] ?? null,
            'outlet' => $v['outlet'] ?? null,
            'status' => $v['status'] ?? 'draft',
            'lines' => $this->mapLines($v['lines']),
        ]);

        return response()->json([
            'message' => 'Journal created successfully.',
            'data' => new JournalResource($journal),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateJournalRequest $request, Journal $journal): JsonResponse
    {
        $v = $request->validated();

        $payload = [];
        if (array_key_exists('journalDate', $v)) {
            $payload['journal_date'] = $v['journalDate'];
        }
        if (array_key_exists('description', $v)) {
            $payload['description'] = $v['description'];
        }
        if (array_key_exists('outlet', $v)) {
            $payload['outlet'] = $v['outlet'];
        }
        if (array_key_exists('lines', $v)) {
            $payload['lines'] = $this->mapLines($v['lines']);
        }

        $updated = $this->accountingService->updateJournal($journal, $payload);

        return response()->json([
            'message' => 'Journal updated successfully.',
            'data' => new JournalResource($updated),
        ]);
    }

    public function destroy(Journal $journal): JsonResponse
    {
        $this->accountingService->deleteJournal($journal);

        return response()->json([
            'message' => 'Journal deleted successfully.',
        ]);
    }

    public function post(Journal $journal): JsonResponse
    {
        $posted = $this->accountingService->postJournal($journal);

        return response()->json([
            'message' => 'Journal posted successfully.',
            'data' => new JournalResource($posted),
        ]);
    }

    /**
     * @param  list<array{accountId: int|string, debit: float|int, credit: float|int, memo?: string|null}>  $lines
     * @return list<array{account_id: int, debit: float, credit: float, memo?: string|null}>
     */
    private function mapLines(array $lines): array
    {
        $out = [];

        foreach ($lines as $line) {
            $out[] = [
                'account_id' => (int) $line['accountId'],
                'debit' => (float) $line['debit'],
                'credit' => (float) $line['credit'],
                'memo' => $line['memo'] ?? null,
            ];
        }

        return $out;
    }
}
