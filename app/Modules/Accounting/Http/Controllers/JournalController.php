<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Accounting\Domain\Journal;
use App\Modules\Accounting\Http\Requests\ReverseJournalRequest;
use App\Modules\Accounting\Http\Requests\StoreJournalRequest;
use App\Modules\Accounting\Http\Requests\UpdateJournalRequest;
use App\Modules\Accounting\Http\Resources\JournalResource;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Accounting\Services\JournalPostingService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class JournalController extends Controller
{
    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly JournalPostingService $journalPostingService,
    ) {}

    public function index(): JsonResponse
    {
        $tenantId = (int) request()->query('tenantId', 0);
        $outletId = request()->query('outletId');
        $user = request()->user('api');
        $journals = $this->accountingService->listJournals(
            $tenantId > 0 ? $tenantId : null,
            $user instanceof \App\Models\User ? $user : null,
            is_numeric($outletId) ? (int) $outletId : null
        );

        return response()->json([
            'data' => JournalResource::collection($journals),
        ]);
    }

    public function store(StoreJournalRequest $request): JsonResponse
    {
        $v = $request->validated();
        $user = $request->user('api');
        if ($user instanceof \App\Models\User && isset($v['outletId'])) {
            $this->accountingService->assertOutletAllowedForActor($user, (int) $v['outletId']);
        }

        $status = $v['status'] ?? 'draft';
        if ($status === 'posted') {
            $journal = $this->journalPostingService->post([
                'tenant_id' => $v['tenantId'] ?? null,
                'journal_no' => $v['journalNo'] ?? null,
                'journal_date' => $v['journalDate'],
                'description' => $v['description'] ?? null,
                'outlet' => $v['outlet'] ?? null,
                'outlet_id' => $v['outletId'] ?? null,
                'posting_key' => $v['postingKey'] ?? null,
                'source_type' => 'manual',
                'source_id' => null,
                'lines' => $this->mapLines($v['lines']),
            ]);
        } else {
            $journal = $this->accountingService->createJournal([
                'tenant_id' => $v['tenantId'] ?? null,
                'journal_no' => $v['journalNo'] ?? null,
                'journal_date' => $v['journalDate'],
                'description' => $v['description'] ?? null,
                'outlet' => $v['outlet'] ?? null,
                'outlet_id' => $v['outletId'] ?? null,
                'status' => 'draft',
                'lines' => $this->mapLines($v['lines']),
            ]);
        }

        return response()->json([
            'message' => 'Journal created successfully.',
            'data' => new JournalResource($journal),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateJournalRequest $request, Journal $journal): JsonResponse
    {
        $user = $request->user('api');
        if ($user instanceof \App\Models\User) {
            $journal = $this->accountingService->findJournalOrFailForActor((int) $journal->id, $user);
        }
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
        $user = request()->user('api');
        if ($user instanceof \App\Models\User) {
            $journal = $this->accountingService->findJournalOrFailForActor((int) $journal->id, $user);
        }
        $this->accountingService->deleteJournal($journal);

        return response()->json([
            'message' => 'Journal deleted successfully.',
        ]);
    }

    public function post(Journal $journal): JsonResponse
    {
        $user = request()->user('api');
        if ($user instanceof \App\Models\User) {
            $journal = $this->accountingService->findJournalOrFailForActor((int) $journal->id, $user);
        }
        $posted = $this->accountingService->postJournal($journal);

        return response()->json([
            'message' => 'Journal posted successfully.',
            'data' => new JournalResource($posted),
        ]);
    }

    public function reverse(ReverseJournalRequest $request, Journal $journal): JsonResponse
    {
        $user = $request->user('api');
        $actor = $user instanceof \App\Models\User ? $user : null;
        if ($actor !== null) {
            $journal = $this->accountingService->findJournalOrFailForActor((int) $journal->id, $actor);
        }
        $reversal = $this->journalPostingService->reverse(
            $journal,
            $actor,
            $request->validated('postingKey') ?? $request->header('Idempotency-Key'),
            $request->validated('reason')
        );

        return response()->json([
            'success' => true,
            'message' => 'Journal reversed successfully.',
            'data' => new JournalResource($reversal),
            'meta' => null,
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
