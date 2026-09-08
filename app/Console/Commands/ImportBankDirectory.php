<?php

namespace App\Console\Commands;

use App\Settings\BankDirectory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/** Expliziter lokaler Import öffentlicher Bankdaten mit nachvollziehbarem Gültigkeitszeitraum. */
#[Signature('stationdeck:import-banks {path : Pfad zur Bundesbank-CSV} {--from= : Gültig ab YYYY-MM-DD} {--until= : Gültig bis YYYY-MM-DD}')]
#[Description('Importiert die öffentliche Bundesbank-CSV als vollständigen Bankenstamm.')]
class ImportBankDirectory extends Command
{
    /** Validiert vor dem Schreiben; meldet Importnummer und Datensatzanzahl ohne Dateiinhalt. */
    public function handle(): int
    {
        try {
            $id = app(BankDirectory::class)->import((string) $this->argument('path'), (string) $this->option('from'), (string) $this->option('until'));
            $this->info('Bankenstamm importiert. Importnummer: '.$id);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Import abgebrochen: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
