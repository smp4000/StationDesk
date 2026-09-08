<?php

namespace Database\Factories;

use App\Models\SuperAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;

/** Isolierte Plattformkonten für Guard- und MFA-Negativtests. @extends Factory<SuperAdmin> */
class SuperAdminFactory extends Factory
{
    /** TOTP ist zunächst nicht eingerichtet, damit die verpflichtende Einrichtung geprüft wird. */
    public function definition(): array
    {
        return ['name' => fake()->name(), 'email' => fake()->unique()->safeEmail(), 'password' => 'test-only-password'];
    }
}
