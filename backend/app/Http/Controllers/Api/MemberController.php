<?php

namespace App\Http\Controllers\Api;

use App\Helpers\AuthorizationHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMemberRequest;
use App\Http\Requests\UpdateMemberRequest;
use App\Http\Resources\MemberResource;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class MemberController extends Controller
{
    public function index()
    {
        $this->authorize('viewAny', Member::class);

        $query = Member::with('user')->latest();

        // A plain abonne only sees their own profile ("Members: Own profile only").
        if (! AuthorizationHelper::isBureauMember(auth()->user())) {
            $query->where('user_id', auth()->id());
        }

        return MemberResource::collection($query->get());
    }

    public function store(StoreMemberRequest $request)
    {
        $this->authorize('create', Member::class);
        $member = DB::transaction(function () use ($request) {

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                // Subscribers never sign in with email/password — only a
                // Member Portal passkey, issued once a bureau member verifies
                // their subscription (see SubscriptionController::verify()).
                // The `password` column isn't nullable, so this fills it with
                // a value nobody knows and that is never communicated to
                // anyone, rather than making the bureau invent a password the
                // subscriber will never use.
                'password' => Hash::make(Str::random(40)),
            ]);

            $user->assignRole('abonne');

            return $user->member()->create([
                'phone' => $request->phone,
                'address' => $request->address,
            ]);
        });

        return new MemberResource(
            $member->load('user')
        );
    }

    public function show(Member $member)
    {
        $this->authorize('view', $member);

        return new MemberResource(
            $member->load('user')
        );
    }

    public function update(UpdateMemberRequest $request, Member $member)
    {
        $this->authorize('update', $member);
        $member->update($request->validated());

        return new MemberResource(
            $member->load('user')
        );
    }

    public function destroy(Member $member)
    {
        $this->authorize('delete', $member);

        DB::transaction(function () use ($member) {
            $user = $member->user;
            $member->delete();

            // store() always creates a Member together with its own
            // dedicated User (see above) — mirror that symmetry here.
            // Without this, deleting a subscriber only ever removed their
            // profile half, leaving an orphaned User row (email, password
            // hash, role, any Sanctum tokens) behind forever — the email
            // could never be reused and the account technically still
            // existed. Bureau accounts are excluded: those are shared login
            // identities with real system access (roles, approvals, audit
            // trail as `causer`), not single-purpose subscriber records —
            // deleting one's Member row must not destroy their login.
            if ($user && ! AuthorizationHelper::isBureauMember($user)) {
                $user->tokens()->delete();
                $user->roles()->detach();
                $user->delete();
            }
        });

        return response()->json([
            'message' => __('messages.member.deleted'),
        ]);
    }
}
