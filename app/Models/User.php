<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
        'tenant_id',
        'google_id',
        'avatar_url',
        'provider',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_admin' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function unlocks(): HasMany
    {
        return $this->hasMany(UserUnlock::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(Report::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)
            ->where('status', 'active')
            ->where('current_period_end', '>', now());
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function hasActiveSubscription(): bool
    {
        return $this->activeSubscription()->exists();
    }

    public function hasUnlocked(string $desoCode): bool
    {
        // Check direct DeSO unlock
        if ($this->unlocks()->where('unlock_type', 'deso')->where('unlock_code', $desoCode)->exists()) {
            return true;
        }

        // Check kommun unlock (DeSO code starts with 4-digit kommun code)
        $kommunCode = substr($desoCode, 0, 4);
        if ($this->unlocks()->where('unlock_type', 'kommun')->where('unlock_code', $kommunCode)->exists()) {
            return true;
        }

        // Check län unlock (first 2 digits)
        $lanCode = substr($desoCode, 0, 2);
        if ($this->unlocks()->where('unlock_type', 'lan')->where('unlock_code', $lanCode)->exists()) {
            return true;
        }

        return false;
    }

    public function hasApiAccess(): bool
    {
        return (bool) $this->is_admin;
    }
}
