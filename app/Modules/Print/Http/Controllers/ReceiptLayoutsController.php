<?php

namespace App\Modules\Print\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Print\Domain\ReceiptTemplate;
use App\Models\User;
use App\Modules\Print\Http\Requests\StoreReceiptTemplateRequest;
use App\Modules\Print\Http\Requests\UpdateReceiptTemplateRequest;
use App\Modules\Print\Http\Resources\ReceiptTemplateResource;
use App\Modules\Print\Services\ReceiptTemplateAdminService;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ReceiptLayoutsController extends Controller
{
    public function __construct(
        private readonly ReceiptTemplateAdminService $service,
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->authenticate($request);
        $outletId = (int) $request->query('outletId', 0);
        abort_if($outletId < 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'outletId is required.');
        $this->assertOutletAllowed($user, $outletId);

        return response()->json([
            'success' => true,
            'message' => 'Receipt layout templates retrieved.',
            'data' => ReceiptTemplateResource::collection($this->service->listMerged($outletId)),
            'meta' => null,
        ]);
    }

    public function store(StoreReceiptTemplateRequest $request): JsonResponse
    {
        $user = $this->authenticate($request);

        $v = $request->validated();
        $this->assertOutletAllowed($user, (int) $v['outletId']);
        /** @phpstan-ignore argument.type */
        $tpl = $this->service->create((int) $v['outletId'], $v);

        return response()->json([
            'success' => true,
            'message' => 'Receipt template created.',
            'data' => new ReceiptTemplateResource($tpl),
            'meta' => null,
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateReceiptTemplateRequest $request, int $template): JsonResponse
    {
        $user = $this->authenticate($request);
        /** @var ReceiptTemplate $model */
        $model = ReceiptTemplate::query()->findOrFail($template);
        abort_if((int) $model->outlet_id === 0, Response::HTTP_FORBIDDEN, 'Builtin templates are immutable.');
        $this->assertOutletAllowed($user, (int) $model->outlet_id);

        $tpl = $this->service->update($model, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Receipt template updated.',
            'data' => new ReceiptTemplateResource($tpl),
            'meta' => null,
        ]);
    }

    private function authenticate(Request $request): User
    {
        $user = $request->user('api');
        abort_if(! $user instanceof User, Response::HTTP_UNAUTHORIZED, 'Unauthenticated.');

        return $user;
    }

    private function assertOutletAllowed(User $user, int $outletId): void
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages(['outletId' => ['The selected outlet is invalid.']]);
        }
    }
}
