<?php

namespace Joelseneque\HelpRequests\Tests\Fixtures;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory;
    use Notifiable;

    protected $table = 'users';

    protected $fillable = ['name', 'email', 'password', 'is_admin'];

    protected function casts(): array
    {
        return ['is_admin' => 'boolean'];
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }
}
