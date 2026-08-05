<?php

declare(strict_types=1);

namespace Nvl\Forms\Tests\Stubs;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class TestFormsUser extends Authenticatable
{
    use HasFactory;

    protected $table = 'test_forms_users';

    protected $guarded = [];

    // Simulate account_type for testing
    public $account_type = 'admin';

    // Mock factory
    protected static function newFactory()
    {
        return new class extends Factory
        {
            protected $model = TestFormsUser::class;

            public function definition()
            {
                return [
                    'name' => 'Test User',
                    'email' => 'test@example.com',
                    'password' => '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', // password
                ];
            }
        };
    }
}
