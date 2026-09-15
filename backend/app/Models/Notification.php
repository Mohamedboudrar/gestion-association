<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'title',
        'message',
        'subject_type',
        'subject_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // The record this notification is about (an Expense, Donation, Project,
    // ...) — nullable, older rows and system-wide notices have none. Drives
    // NotificationResource's deep link. Never guarded behind a policy here:
    // a notification only ever exists for a user it was already addressed
    // to, so no extra authorization check is needed to read its own subject.
    public function subject()
    {
        return $this->morphTo();
    }

    /**
     * Create one notification per user holding any of the given roles.
     * Used to alert whoever is authorized to act on an event (e.g. subscription
     * verifiers) at the moment it actually happens. $subject (optional) is
     * the record the notification is about — see subject() above.
     */
    public static function notifyRoles(array $roles, string $type, string $title, ?string $message = null, ?Model $subject = null): void
    {
        $recipients = User::role($roles)->get();

        self::insertFor($recipients->pluck('id')->all(), $type, $title, $message, $subject);
    }

    /**
     * Create one notification per given user id. Used for audiences that aren't
     * a global role — e.g. a project's committee members.
     */
    public static function notifyUsers(array $userIds, string $type, string $title, ?string $message = null, ?Model $subject = null): void
    {
        self::insertFor($userIds, $type, $title, $message, $subject);
    }

    /**
     * Same as notifyRoles(), but skips any recipient who was already
     * notified with this exact (type, subject) pair — for scheduled checks
     * that run repeatedly (subscription-expiry reminders, overdue projects)
     * and must never re-notify the same user about the same record twice.
     * Requires a $subject: without one there's nothing to dedupe against.
     */
    public static function notifyRolesOnce(array $roles, string $type, string $title, ?string $message, Model $subject): void
    {
        $recipients = User::role($roles)->get();

        self::insertForOnce($recipients->pluck('id')->all(), $type, $title, $message, $subject);
    }

    public static function notifyUsersOnce(array $userIds, string $type, string $title, ?string $message, Model $subject): void
    {
        self::insertForOnce($userIds, $type, $title, $message, $subject);
    }

    private static function insertFor(array $userIds, string $type, string $title, ?string $message, ?Model $subject): void
    {
        $rows = collect($userIds)
            ->unique()
            ->filter()
            ->map(fn ($userId) => self::row($userId, $type, $title, $message, $subject));

        if ($rows->isNotEmpty()) {
            self::insert($rows->all());
        }
    }

    private static function insertForOnce(array $userIds, string $type, string $title, ?string $message, Model $subject): void
    {
        $userIds = collect($userIds)->unique()->filter()->values();

        if ($userIds->isEmpty()) {
            return;
        }

        $alreadyNotified = self::where('type', $type)
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->whereIn('user_id', $userIds)
            ->pluck('user_id');

        $remaining = $userIds->diff($alreadyNotified);

        self::insertFor($remaining->all(), $type, $title, $message, $subject);
    }

    private static function row(int $userId, string $type, string $title, ?string $message, ?Model $subject): array
    {
        return [
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
