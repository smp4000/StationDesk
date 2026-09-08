<?php

namespace App\Settings;

use Fhp\Action\GetSEPAAccounts;
use Fhp\BaseAction;
use Fhp\FinTs;
use Fhp\Options\Credentials;
use Fhp\Options\FinTsOptions;
use RuntimeException;

/** Ausschließlich TAN-Verfahrensauswahl, Anmeldung und SEPA-Kontenliste; keine Zahlungsaktionen. */
class FintsReadOnlyAdapter
{
    /** Bearbeitet genau einen Dialogschritt; interne Zustände dürfen nur verschlüsselt gespeichert werden. */
    public function advance(array $state, string $choice = ''): array
    {
        $client = $this->client($state);
        switch ($state['phase']) {
            case 'start':
                $options = [];
                foreach ($client->getTanModes() as $mode) {
                    if ($mode->isDecoupled() && $mode->isProzessvariante2()) {
                        $options[(string) $mode->getId()] = $mode->getName();
                    }
                }
                if (! $options) {
                    throw new RuntimeException('Kein freigegebenes Handy-Verfahren.');
                }
                $state['phase'] = 'mode';
                $state['options'] = $options;
                break;
            case 'mode':
                if (! array_key_exists($choice, $state['options'])) {
                    throw new RuntimeException('Unbekanntes TAN-Verfahren.');
                }
                $mode = $client->getTanModes()[(int) $choice];
                if (! $mode->isDecoupled()) {
                    throw new RuntimeException('Nur Handy-Freigabe erlaubt.');
                }
                $state['mode'] = (int) $choice;
                if ($mode->needsTanMedium()) {
                    $state['options'] = [];
                    foreach ($client->getTanMedia($mode) as $medium) {
                        $state['options'][$medium->getName()] = $medium->getName();
                    }
                    if (! $state['options']) {
                        throw new RuntimeException('Kein Freigabegerät vorhanden.');
                    }
                    $state['phase'] = 'medium';
                    break;
                }
                $client->selectTanMode($mode);
                $state = $this->login($client, $state);
                break;
            case 'medium':
                if (! array_key_exists($choice, $state['options'])) {
                    throw new RuntimeException('Unbekanntes Gerät.');
                }
                $client->selectTanMode($state['mode'], $choice);
                $state = $this->login($client, $state);
                break;
            case 'waiting':
                // Ausschließlich zuvor serverseitig erzeugte, authentifiziert verschlüsselte Paketdaten einlesen.
                $action = unserialize($state['action']);
                if (! $action instanceof BaseAction) {
                    throw new RuntimeException('Ungültiger Dialogzustand.');
                }
                if ($client->checkDecoupledSubmission($action)) {
                    $state = $state['step'] === 'login' ? $this->accounts($client, $state) : $this->complete($client, $state, $action);
                } else {
                    $state['checks']++;
                    $state['next_check'] = time() + max(1, $client->getSelectedTanMode()->getPeriodicDecoupledCheckDelaySeconds());
                    $state['action'] = serialize($action);
                }
                break;
            default:
                throw new RuntimeException('Unbekannter Dialogschritt.');
        }
        if ($state['phase'] !== 'done') {
            $state['client'] = $client->persist();
        }

        return $state;
    }

    /** Baut den Client aus dem eingefrorenen Bankprofil; Zertifikatsprüfung bleibt im Paket aktiv. */
    protected function client(array $state): FinTs
    {
        $options = new FinTsOptions;
        $options->url = app(TestFintsConnection::class)->endpoint($state['settings']['endpoint']);
        $options->bankCode = $state['settings']['bank_code'];
        $options->productName = $state['settings']['product_id'];
        $options->productVersion = '0.1';
        $options->timeoutConnect = 5;
        $options->timeoutResponse = 15;

        return FinTs::new($options, Credentials::create($state['login'], $state['pin']), $state['client'] ?? null);
    }

    /** Die Bank entscheidet, ob bereits die Anmeldung eine separate Handy-Freigabe benötigt. */
    private function login(FinTs $client, array $state): array
    {
        $action = $client->login();

        return $action->needsTan() ? $this->waiting($client, $state, $action, 'login') : $this->accounts($client, $state);
    }

    /** Die einzige Fachabfrage liest Konten; auch sie kann eine eigene Freigabe erfordern. */
    private function accounts(FinTs $client, array $state): array
    {
        $action = $this->accountsAction();
        $client->execute($action);

        return $action->needsTan() ? $this->waiting($client, $state, $action, 'accounts') : $this->complete($client, $state, $action);
    }

    /** Erzeugt nur die lesende HKSPA-Aktion; zugleich Austauschpunkt für Tests ohne Bankverkehr. */
    protected function accountsAction(): GetSEPAAccounts
    {
        return GetSEPAAccounts::create();
    }

    /** Bewahrt Bankintervalle und Versuchslimits für die nächste manuelle Statusabfrage. */
    private function waiting(FinTs $client, array $state, BaseAction $action, string $step): array
    {
        $mode = $client->getSelectedTanMode();
        if (! $mode->isDecoupled()) {
            throw new RuntimeException('Bank fordert ein anderes Verfahren.');
        }
        $state['phase'] = 'waiting';
        $state['step'] = $step;
        $state['action'] = serialize($action);
        $state['checks'] = 0;
        $state['max_checks'] = $mode->getMaxDecoupledChecks();
        $state['next_check'] = time() + max(1, $mode->getFirstDecoupledCheckDelaySeconds());
        $state['challenge'] = mb_substr(strip_tags($action->getTanRequest()->getChallenge() ?? 'Bitte in SecureGo plus bestätigen.'), 0, 2000);

        return $state;
    }

    /** Zeigt nur maskierte Kontoreferenzen; PIN und Dialogdaten werden im Aufrufer anschließend gelöscht. */
    private function complete(FinTs $client, array $state, GetSEPAAccounts $action): array
    {
        $accounts = [];
        foreach ($action->getAccounts() as $account) {
            $reference = $account->getIban() ?: $account->getAccountNumber();
            $accounts[] = $reference ? '•••• '.substr($reference, -4) : 'Konto ohne Referenz';
        }
        $client->close();

        return ['phase' => 'done', 'accounts' => $accounts];
    }
}
