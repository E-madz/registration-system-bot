<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Program extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_CANCELED = 'canceled';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
        self::STATUS_CLOSED,
        self::STATUS_CANCELED,
        self::STATUS_ARCHIVED,
    ];

    /**
     * Allowed status transitions: current status => list of statuses it can move to.
     *
     * @var array<string, array<string>>
     */
    private const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_PUBLISHED],
        self::STATUS_PUBLISHED => [self::STATUS_CLOSED, self::STATUS_CANCELED],
        self::STATUS_CLOSED => [self::STATUS_PUBLISHED, self::STATUS_CANCELED, self::STATUS_ARCHIVED],
        self::STATUS_CANCELED => [self::STATUS_ARCHIVED],
        self::STATUS_ARCHIVED => [],
    ];

    protected $fillable = [
        'title',
        'slug',
        'description',
        'status',
        'registration_starts_at',
        'registration_ends_at',
        'capacity',
        'requires_approval',
        'success_message',
    ];

    protected function casts(): array
    {
        return [
            'registration_starts_at' => 'datetime',
            'registration_ends_at' => 'datetime',
            'requires_approval' => 'boolean',
        ];
    }

    public function fields()
    {
        return $this->hasMany(ProgramField::class)->orderBy('order');
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public static function generateUniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title);
        $base = $base === '' ? Str::random(8) : Str::limit($base, 55, '');
        $slug = $base;
        $suffix = 1;

        while (
            static::query()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->exists()
        ) {
            $suffix++;
            $slug = Str::limit($base, 55, '')."-{$suffix}";
        }

        return $slug;
    }
}
