<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Keine Standardkonten; Kontoanlage erfolgt interaktiv oder ausdrücklich durch den lokalen Seeder. */
    public function run(): void {}
}
