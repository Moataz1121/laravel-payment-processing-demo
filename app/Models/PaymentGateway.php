<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentGateway extends Model
{
    use HasFactory, HasUids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'slug',
        'provider',
        'is_enabled',
        'creds',
        'settings',
        'description',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'creds' => 'array',
            'settings' => 'array',
            'sort_order' => 'integer',
        ];
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
