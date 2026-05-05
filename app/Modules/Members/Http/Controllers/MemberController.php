<?php

namespace App\Modules\Members\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Modules\Members\Http\Requests\StoreMemberRequest;
use App\Modules\Members\Http\Requests\UpdateMemberRequest;
use App\Modules\Members\Http\Resources\MemberResource;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class MemberController extends Controller
{
    public function index(): JsonResponse
    {
        $members = Member::query()->orderBy('name')->get();

        return response()->json([
            'data' => MemberResource::collection($members),
        ]);
    }

    public function store(StoreMemberRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (! isset($data['points'])) {
            $data['points'] = 0;
        }

        $member = Member::query()->create($data);

        return response()->json([
            'message' => 'Member created successfully.',
            'data' => new MemberResource($member),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateMemberRequest $request, Member $member): JsonResponse
    {
        $member->fill($request->validated());
        $member->save();

        return response()->json([
            'message' => 'Member updated successfully.',
            'data' => new MemberResource($member->fresh()),
        ]);
    }

    public function updateStatus(Member $member): JsonResponse
    {
        $member->status = $member->status === 'active' ? 'inactive' : 'active';
        $member->save();

        return response()->json([
            'message' => 'Status updated.',
            'data' => new MemberResource($member->fresh()),
        ]);
    }

    public function destroy(Member $member): JsonResponse
    {
        $member->delete();

        return response()->json([
            'message' => 'Member deleted successfully.',
        ]);
    }
}
