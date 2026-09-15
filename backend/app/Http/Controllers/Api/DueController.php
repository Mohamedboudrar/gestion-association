<?php

namespace App\Http\Controllers\Api;

use App\Helpers\AuthorizationHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\DueResource;
use App\Models\Due;
use App\Services\DuesService;
use Illuminate\Http\Request;

class DueController extends Controller
{
    // Read-only + waive. Dues are system-generated (DuesService/
    // app:generate-annual-dues) and auto-updated via linked subscription
    // payments — there is no manual create/update/delete HTTP surface,
    // matching the task's "no dead code" constraint.
    public function index(Request $request)
    {
        $this->authorize('viewAny', Due::class);

        $query = Due::with('member.user')->latest('year');

        // Same scoping rule as SubscriptionController::index — a plain
        // abonne only ever sees their own dues.
        if (! AuthorizationHelper::isBureauMember(auth()->user())) {
            $member = auth()->user()->member;
            $query->where('member_id', $member?->id ?? 0);
        } elseif ($request->filled('member_id')) {
            $query->where('member_id', $request->integer('member_id'));
        }

        if ($request->filled('year')) {
            $query->where('year', $request->integer('year'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return DueResource::collection($query->get());
    }

    public function show(Due $due)
    {
        $this->authorize('view', $due);

        return new DueResource($due->load('member.user'));
    }

    public function waive(Request $request, Due $due, DuesService $duesService)
    {
        $this->authorize('waive', $due);

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
        ]);

        $duesService->waive($due, $validated['reason']);

        return new DueResource($due->fresh()->load('member.user'));
    }
}
