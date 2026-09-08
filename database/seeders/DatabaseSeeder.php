<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Keine Standardkonten anlegen; Plattformkonten werden ausschließlich interaktiv erstellt. */
    public function run(): void {}
}
