<?php

namespace App\Filament\Owner\Auth;

use App\Models\Owner;
use App\Onboarding\RegisterOwner;
use App\Onboarding\SendVerificationMail;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\RegistrationResponse;
use Filament\Auth\Pages\Register as BaseRegister;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use SensitiveParameter;

/** Lokale Registrierung auf der gemeinsamen Owner-Seite; Fachanlage bleibt bis zur E-Mail-Bestätigung gesperrt. */
class Register extends BaseRegister
{
    protected Width|string|null $maxWidth = Width::FourExtraLarge;

    /** Verhindert auch bei bekannter URL die Nutzung außerhalb der freigegebenen lokalen Umgebung. */
    public function mount(): void
    {
        abort_unless(RegisterOwner::available(), 403);
        parent::mount();
    }

    /** Wiederholt die Umgebungssperre auf jeder Livewire-Aktion und entfernt Passwörter vor der Antwort. */
    public function register(): ?RegistrationResponse
    {
        abort_unless(RegisterOwner::available(), 403);
        abort_if(auth('web')->check(), 403);
        try {
            return parent::register();
        } finally {
            $this->data['password'] = $this->data['passwordConfirmation'] = '';
        }
    }

    /** Gruppiert Chef, Rechnungsanschrift und erste Station; die Testbestätigung ersetzt keine Vertragsannahme. */
    public function form(Schema $schema): Schema
    {
        $field = fn (string $name, string $label) => TextInput::make($name)->label($label)->required()->maxLength(255);

        return $schema->components([
            Text::make('Lokale Testregistrierung: Du richtest deinen Chef-Zugang und die erste Tankstelle ein. Der Testzeitraum beginnt nach E-Mail-Bestätigung und erfolgreicher Einrichtung. Es entsteht hier kein kostenpflichtiger Vertrag und kein SEPA-Mandat.'),
            Section::make('Dein Zugang als Chef')->columns(2)->schema([
                $field('first_name', 'Vorname')->autocomplete('given-name'), $field('last_name', 'Nachname')->autocomplete('family-name'),
                $this->getEmailFormComponent()->columnSpanFull(),
                TextInput::make('password')->label('Passwort')->password()->revealable()->required()->minLength(12)->maxLength(72)->same('passwordConfirmation')->autocomplete('new-password')->helperText('Mindestens 12 Zeichen.'),
                TextInput::make('passwordConfirmation')->label('Passwort wiederholen')->password()->revealable()->required()->autocomplete('new-password'),
            ]),
            Section::make('Firma und Rechnungsanschrift')->columns(2)->schema([
                $field('company_name', 'Firmenname')->columnSpanFull(), $field('billing_street', 'Straße und Hausnummer')->columnSpanFull(),
                $field('billing_postal_code', 'Postleitzahl')->maxLength(20), $field('billing_city', 'Ort'),
                $field('billing_country_code', 'Ländercode')->default('DE')->length(2),
                TextInput::make('phone')->label('Telefon (optional)')->tel()->maxLength(50),
                TextInput::make('vat_id')->label('USt-IdNr. (optional)')->maxLength(32),
            ]),
            Section::make('Deine erste Tankstelle')->columns(2)->schema([
                $field('station_name', 'Name der Tankstelle')->columnSpanFull(), $field('station_street', 'Straße und Hausnummer')->columnSpanFull(),
                $field('station_postal_code', 'Postleitzahl')->maxLength(20), $field('station_city', 'Ort'),
                $field('station_country_code', 'Ländercode')->default('DE')->length(2),
            ]),
            Checkbox::make('test_registration')->label('Ich möchte ein lokales Testkonto anlegen. Die Bestätigungsmail wird an meine angegebene E-Mail-Adresse gesendet.')->accepted()->required(),
        ]);
    }

    /** Der Dienst erzeugt ausschließlich interne IDs und eine unbestätigte Owner-Identität. */
    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        return app(RegisterOwner::class)->create($data);
    }

    /** Kennzeichnet den Abschluss passend zur freigegebenen lokalen Testphase. */
    public function getRegisterFormAction(): Action
    {
        return parent::getRegisterFormAction()->label('Testkonto anlegen');
    }

    /** Ein SMTP-Ausfall darf die erfolgte Kontoanlage nicht als fehlgeschlagene Registrierung erscheinen lassen. */
    protected function sendEmailVerificationNotification(Model $user): void
    {
        abort_unless($user instanceof Owner, 403);
        try {
            app(SendVerificationMail::class)->send($user);
        } catch (RuntimeException $exception) {
            session()->flash('verification_mail_error', $exception->getMessage());
        }
    }
}
