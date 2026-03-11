<?php

namespace App\Models;

use App\Notifications\Auth\CustomResetPasswordNotification;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'price_per_page',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'price_per_page' => 'float',
        ];
    }

    /**
     * Price per page for this user (billing). When null, app default 5 is used.
     */
    public function getEffectivePricePerPage(float $appDefault = 5.0): float
    {
        return $this->price_per_page !== null && $this->price_per_page > 0
            ? (float) $this->price_per_page
            : $appDefault;
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function pdfJobs(): HasMany
    {
        return $this->hasMany(PdfJob::class);
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new CustomResetPasswordNotification($token));
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'admin' && $this->hasRole('super_admin');
    }
}
