<?php

namespace Database\Factories;

use App\Models\Owner;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Testidentitäten benötigen bewusst eine explizit bereitgestellte Tenant-ID. @extends Factory<Owner> */
class OwnerFactory extends Factory
{
    /** Liefert bestätigte Testkonten ohne öffentliche oder feste Produktionszugänge. */
    public function definition(): array
    {
        return [
            'name' => fake()->name(), 'first_name' => fake()->firstName(), 'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(), 'email_verified_at' => now(), 'password' => 'test-only-password',
        ];
    }
}
