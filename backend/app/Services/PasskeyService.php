<?php

namespace App\Services;

use App\Models\User;

/**
 * Single source of truth for everything to do with member-portal passkeys:
 * generating one, hashing it, issuing it to a user, and resolving a login
 * attempt back to a user. Never touches the plain passkey outside this class
 * except to hand it to a caller once, immediately after generation, so it can
 * be emailed — it is never logged and never persisted anywhere in the clear.
 *
 * Hashing note: a passkey is only 6 digits (1,000,000 possible values), so no
 * hash algorithm makes it resistant to brute force on its own — that's what
 * login rate-limiting/lockout is for (see MemberPortalAuthController). What a
 * hash buys here is that the plain passkey is never sitting in the database.
 * Because a passkey login has no username/email to look the user up by first,
 * the hash must be a deterministic function of the input so a login attempt
 * can be resolved with a single indexed query — a salted hash (bcrypt/argon2,
 * different output every time even for the same input) can't do that without
 * iterating and checking every user in the table. Hence a keyed HMAC-SHA256
 * (keyed with the app's own secret key, so it's not just a bare unsalted
 * SHA-256 either) rather than Hash::make().
 */
class PasskeyService
{
    private const LENGTH = 6;

    public static function hash(string $passkey): string
    {
        return hash_hmac('sha256', $passkey, config('app.key'));
    }

    public function generateUniquePasskey(): string
    {
        do {
            $passkey = str_pad((string) random_int(0, 10 ** self::LENGTH - 1), self::LENGTH, '0', STR_PAD_LEFT);
        } while (User::where('passkey_hash', self::hash($passkey))->exists());

        return $passkey;
    }

    // Generates, hashes, and stores a brand-new passkey for this user,
    // returning the plain value once so the caller can email it. Overwrites
    // any previous passkey (the old one stops working immediately).
    public function issueFor(User $user): string
    {
        $passkey = $this->generateUniquePasskey();

        $user->forceFill([
            'passkey_hash' => self::hash($passkey),
            'passkey_created_at' => now(),
            'last_passkey_sent_at' => now(),
        ])->save();

        // A manual, narrow log entry — never the passkey or its hash, just
        // that an issuance happened and for whom. Deliberately not the
        // LogsActivity trait on the whole User model, which would also
        // auto-log unrelated/sensitive field changes (e.g. password) via its
        // dirty-tracking; this is the one call site that issues/resets a
        // passkey, authenticated (president verifying a member) or not (the
        // member portal's own self-service reset, no causer).
        activity()
            ->causedBy(auth()->user())
            ->performedOn($user)
            ->log('Member portal passkey issued.');

        return $passkey;
    }

    // Resolves a login attempt to the user it belongs to, or null. This is
    // the ONLY passkey lookup path — never iterate users and compare in PHP.
    public function resolve(string $passkey): ?User
    {
        return User::where('passkey_hash', self::hash($passkey))->first();
    }
}
