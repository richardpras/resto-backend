<?php

namespace App\Modules\Imports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Imports\Services\MasterImportTemplateService;
use App\Modules\Imports\Services\Phase1MasterImportService;
use App\Modules\Imports\Services\Phase2MasterImportService;
use App\Modules\Imports\Services\Phase2MasterImportTemplateService;
use App\Modules\Imports\Services\Phase3MasterImportService;
use App\Modules\Imports\Services\Phase3MasterImportTemplateService;
use App\Modules\Imports\Services\Phase4MasterImportService;
use App\Modules\Imports\Services\Phase4MasterImportTemplateService;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MasterImportController extends Controller
{
    public function __construct(
        private readonly MasterImportTemplateService $templateService,
        private readonly Phase1MasterImportService $phase1ImportService,
        private readonly Phase2MasterImportTemplateService $phase2TemplateService,
        private readonly Phase2MasterImportService $phase2ImportService,
        private readonly Phase3MasterImportTemplateService $phase3TemplateService,
        private readonly Phase3MasterImportService $phase3ImportService,
        private readonly Phase4MasterImportTemplateService $phase4TemplateService,
        private readonly Phase4MasterImportService $phase4ImportService,
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    public function phase1Template(): BinaryFileResponse
    {
        $zipPath = $this->templateService->buildBundleZip();

        return response()->download(
            $zipPath,
            'master-import-phase1-template.zip',
            ['Content-Type' => 'application/zip'],
        )->deleteFileAfterSend(true);
    }

    public function phase1TemplateXlsx(Request $request): BinaryFileResponse
    {
        $outletId = $this->validatedOutletId($request);

        $xlsxPath = $this->templateService->buildWorkbookXlsx($outletId);

        return response()->download(
            $xlsxPath,
            'master-import-phase1-template.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend(true);
    }

    public function phase1Bundle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
            'tenantId' => ['nullable', 'integer', 'min:1'],
            'preview' => ['nullable', 'boolean'],
            'file' => ['required', 'file', 'mimes:zip,xlsx', 'max:10240'],
        ]);

        $result = $this->phase1ImportService->importBundle($request->user('api'), [
            'outletId' => (int) $validated['outletId'],
            'tenantId' => $validated['tenantId'] ?? null,
            'preview' => (bool) ($validated['preview'] ?? false),
            'file' => $request->file('file'),
        ]);

        return response()->json([
            'message' => ($validated['preview'] ?? false)
                ? 'Import preview generated.'
                : 'Master import committed successfully.',
            'data' => $result,
        ]);
    }

    public function phase1Type(Request $request, string $type): JsonResponse
    {
        $validated = $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
            'tenantId' => ['nullable', 'integer', 'min:1'],
            'preview' => ['nullable', 'boolean'],
            'csv' => ['required', 'string'],
            'filename' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->phase1ImportService->importType($request->user('api'), $type, [
            'outletId' => (int) $validated['outletId'],
            'tenantId' => $validated['tenantId'] ?? null,
            'preview' => (bool) ($validated['preview'] ?? false),
            'csv' => $validated['csv'],
            'filename' => $validated['filename'] ?? null,
        ]);

        return response()->json([
            'message' => ($validated['preview'] ?? false)
                ? 'Import preview generated.'
                : 'Import committed successfully.',
            'data' => $result,
        ]);
    }

    public function phase2Template(): BinaryFileResponse
    {
        $zipPath = $this->phase2TemplateService->buildBundleZip();

        return response()->download(
            $zipPath,
            'master-import-phase2-template.zip',
            ['Content-Type' => 'application/zip'],
        )->deleteFileAfterSend(true);
    }

    public function phase2TemplateXlsx(Request $request): BinaryFileResponse
    {
        $outletId = $this->validatedOutletId($request);

        $xlsxPath = $this->phase2TemplateService->buildWorkbookXlsx($outletId);

        return response()->download(
            $xlsxPath,
            'master-import-phase2-template.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend(true);
    }

    public function phase2Bundle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
            'tenantId' => ['nullable', 'integer', 'min:1'],
            'preview' => ['nullable', 'boolean'],
            'file' => ['required', 'file', 'mimes:zip,xlsx', 'max:10240'],
        ]);

        $result = $this->phase2ImportService->importBundle($request->user('api'), [
            'outletId' => (int) $validated['outletId'],
            'tenantId' => $validated['tenantId'] ?? null,
            'preview' => (bool) ($validated['preview'] ?? false),
            'file' => $request->file('file'),
        ]);

        return response()->json([
            'message' => ($validated['preview'] ?? false)
                ? 'Import preview generated.'
                : 'Master import phase 2 committed successfully.',
            'data' => $result,
        ]);
    }

    public function phase2Type(Request $request, string $type): JsonResponse
    {
        $validated = $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
            'tenantId' => ['nullable', 'integer', 'min:1'],
            'preview' => ['nullable', 'boolean'],
            'csv' => ['required', 'string'],
            'filename' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->phase2ImportService->importType($request->user('api'), $type, [
            'outletId' => (int) $validated['outletId'],
            'tenantId' => $validated['tenantId'] ?? null,
            'preview' => (bool) ($validated['preview'] ?? false),
            'csv' => $validated['csv'],
            'filename' => $validated['filename'] ?? null,
        ]);

        return response()->json([
            'message' => ($validated['preview'] ?? false)
                ? 'Import preview generated.'
                : 'Import committed successfully.',
            'data' => $result,
        ]);
    }

    public function phase3Template(): BinaryFileResponse
    {
        $zipPath = $this->phase3TemplateService->buildBundleZip();

        return response()->download(
            $zipPath,
            'master-import-phase3-template.zip',
            ['Content-Type' => 'application/zip'],
        )->deleteFileAfterSend(true);
    }

    public function phase3TemplateXlsx(Request $request): BinaryFileResponse
    {
        $outletId = $this->validatedOutletId($request);

        $xlsxPath = $this->phase3TemplateService->buildWorkbookXlsx($outletId);

        return response()->download(
            $xlsxPath,
            'master-import-phase3-template.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend(true);
    }

    public function phase3Bundle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
            'tenantId' => ['nullable', 'integer', 'min:1'],
            'preview' => ['nullable', 'boolean'],
            'file' => ['required', 'file', 'mimes:zip,xlsx', 'max:10240'],
        ]);

        $result = $this->phase3ImportService->importBundle($request->user('api'), [
            'outletId' => (int) $validated['outletId'],
            'tenantId' => $validated['tenantId'] ?? null,
            'preview' => (bool) ($validated['preview'] ?? false),
            'file' => $request->file('file'),
        ]);

        return response()->json([
            'message' => ($validated['preview'] ?? false)
                ? 'Import preview generated.'
                : 'Master import phase 3 committed successfully.',
            'data' => $result,
        ]);
    }

    public function phase3Type(Request $request, string $type): JsonResponse
    {
        $validated = $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
            'tenantId' => ['nullable', 'integer', 'min:1'],
            'preview' => ['nullable', 'boolean'],
            'csv' => ['required', 'string'],
            'filename' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->phase3ImportService->importType($request->user('api'), $type, [
            'outletId' => (int) $validated['outletId'],
            'tenantId' => $validated['tenantId'] ?? null,
            'preview' => (bool) ($validated['preview'] ?? false),
            'csv' => $validated['csv'],
            'filename' => $validated['filename'] ?? null,
        ]);

        return response()->json([
            'message' => ($validated['preview'] ?? false)
                ? 'Import preview generated.'
                : 'Import committed successfully.',
            'data' => $result,
        ]);
    }

    public function phase4Template(): BinaryFileResponse
    {
        $zipPath = $this->phase4TemplateService->buildBundleZip();

        return response()->download(
            $zipPath,
            'master-import-phase4-template.zip',
            ['Content-Type' => 'application/zip'],
        )->deleteFileAfterSend(true);
    }

    public function phase4TemplateXlsx(Request $request): BinaryFileResponse
    {
        $outletId = $this->validatedOutletId($request);

        $xlsxPath = $this->phase4TemplateService->buildWorkbookXlsx($outletId);

        return response()->download(
            $xlsxPath,
            'master-import-phase4-template.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend(true);
    }

    public function phase4Bundle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
            'tenantId' => ['nullable', 'integer', 'min:1'],
            'preview' => ['nullable', 'boolean'],
            'file' => ['required', 'file', 'mimes:zip,xlsx', 'max:10240'],
        ]);

        $result = $this->phase4ImportService->importBundle($request->user('api'), [
            'outletId' => (int) $validated['outletId'],
            'tenantId' => $validated['tenantId'] ?? null,
            'preview' => (bool) ($validated['preview'] ?? false),
            'file' => $request->file('file'),
        ]);

        return response()->json([
            'message' => ($validated['preview'] ?? false)
                ? 'Import preview generated.'
                : 'Master import phase 4 committed successfully.',
            'data' => $result,
        ]);
    }

    public function phase4Type(Request $request, string $type): JsonResponse
    {
        $validated = $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
            'tenantId' => ['nullable', 'integer', 'min:1'],
            'preview' => ['nullable', 'boolean'],
            'csv' => ['required', 'string'],
            'filename' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->phase4ImportService->importType($request->user('api'), $type, [
            'outletId' => (int) $validated['outletId'],
            'tenantId' => $validated['tenantId'] ?? null,
            'preview' => (bool) ($validated['preview'] ?? false),
            'csv' => $validated['csv'],
            'filename' => $validated['filename'] ?? null,
        ]);

        return response()->json([
            'message' => ($validated['preview'] ?? false)
                ? 'Import preview generated.'
                : 'Import committed successfully.',
            'data' => $result,
        ]);
    }

    private function validatedOutletId(Request $request): int
    {
        $validated = $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
        ]);

        $outletId = (int) $validated['outletId'];
        $user = $request->user('api');
        abort_if($user === null, 401);

        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outletId is invalid.'],
            ]);
        }

        return $outletId;
    }
}
