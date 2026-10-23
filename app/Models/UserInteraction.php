<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserInteraction extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_VIEW = 'view';
    public const ACTION_DETAILS = 'details';
    public const ACTION_ADD = 'add_to_route';
    public const ACTION_REMOVE = 'remove_from_route';
    public const ACTION_REORDER = 'reorder';
    public const ACTION_REJECT = 'reject_recommendation';
    public const ACTION_SAVE_ROUTE = 'save_route';

    public const ACTIONS = [
        self::ACTION_VIEW,
        self::ACTION_DETAILS,
        self::ACTION_ADD,
        self::ACTION_REMOVE,
        self::ACTION_REORDER,
        self::ACTION_REJECT,
        self::ACTION_SAVE_ROUTE,
    ];

    /** Действия, означающие отказ от объекта — штрафуют S_I. */
    public const NEGATIVE_ACTIONS = [
        self::ACTION_REMOVE,
        self::ACTION_REJECT,
    ];

    protected $fillable = [
        'user_id',
        'event_id',
        'itinerary_id',
        'category_group',
        'action',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
