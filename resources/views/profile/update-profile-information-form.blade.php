@php
    $passwordLoginDisabled = $this->user->isPasswordLoginDisabled();
@endphp

{{-- `emailDirty` steht an der Form-Section und nicht am Feld darunter: Der
     Speichern-Knopf im Actions-Slot liest denselben Zustand, und Alpine reicht
     ihn nur nach unten weiter. --}}
{{-- Enter in einem Textfeld klickt den ersten Submit-Knopf des Formulars, auch
     einen ausgeblendeten: Das `display:none` von `x-show` ändert nichts an der
     Dokumentreihenfolge. Der Griff lenkt dieses Absenden auf den Knopf, der den
     Passkey-Dialog öffnet — sonst endete die E-Mail-Änderung an einer Meldung,
     die eine Bestätigung verlangt, die dann niemand anbietet. `capture`, damit
     er vor Livewires eigenem `submit`-Listener am Formular zupackt;
     `preventDefault()`, weil mit diesem Listener auch dessen `.prevent`
     ausfällt und der Browser sonst wirklich navigierte. Fehlt der Knopf, ist
     der Passwort-Login offen und sein Feld sichtbar: Dort bleibt Enter das
     gewohnte Absenden. --}}
<x-form-section
    submit="updateProfileInformation"
    x-data="{ originalEmail: {{ Js::from($this->user->email) }}, emailDirty: false }"
    x-init="const emailInput = document.getElementById('email');
        const sync = () => { emailDirty = emailInput.value.trim() !== originalEmail; };
        emailInput.addEventListener('input', sync);
        sync();"
    x-on:submit.capture="if (!emailDirty || !$refs.emailChangeSave) return;
        $event.preventDefault();
        $event.stopPropagation();
        $refs.emailChangeSave.querySelector('button').click();"
>
    <x-slot name="title">
        {{ __('app.profile_information') }}
    </x-slot>

    <x-slot name="description">
        {{ __('app.profile_information_description') }}
    </x-slot>

    <x-slot name="form">
        <!-- Profile Photo -->
        @if (Laravel\Jetstream\Jetstream::managesProfilePhotos())
            <div x-data="{photoName: null, photoPreview: null}" class="col-span-6 sm:col-span-4">
                <!-- Profile Photo File Input -->
                <input type="file" id="photo" class="hidden"
                            wire:model.live="photo"
                            x-ref="photo"
                            accept="{{ $this->photoAcceptAttribute }}"
                            x-on:change="
                                    photoName = $refs.photo.files[0].name;
                                    const reader = new FileReader();
                                    reader.onload = (e) => {
                                        photoPreview = e.target.result;
                                    };
                                    reader.readAsDataURL($refs.photo.files[0]);
                            " />

                <x-label for="photo" value="{{ __('app.photo') }}" />

                <!-- Current Profile Photo -->
                <div class="mt-2" x-show="! photoPreview">
                    <img src="{{ $this->user->profile_photo_url }}" alt="{{ $this->user->name }}" class="rounded-full size-20 object-cover">
                </div>

                <!-- New Profile Photo Preview -->
                <div class="mt-2" x-show="photoPreview" style="display: none;">
                    <span class="block rounded-full size-20 bg-cover bg-no-repeat bg-center"
                          x-bind:style="'background-image: url(\'' + photoPreview + '\');'">
                    </span>
                </div>

                <x-secondary-button class="mt-2 me-2" type="button" x-on:click.prevent="$refs.photo.click()">
                    {{ __('app.select_new_photo') }}
                </x-secondary-button>

                @if ($this->user->profile_photo_path)
                    <x-secondary-button type="button" class="mt-2" wire:click="deleteProfilePhoto">
                        {{ __('app.remove_photo') }}
                    </x-secondary-button>
                @endif

                <p class="text-sm mt-2 text-gray-600 dark:text-gray-400">
                    {{ __('app.profile_photo_max_size', ['size' => \Illuminate\Support\Number::fileSize($this->photoUploadLimit->bytes)]) }}
                    @if ($this->photoUploadLimit->constrainedByServer)
                        <span class="text-gray-500 dark:text-gray-400">{{ __('app.profile_photo_limited_by_server') }}</span>
                    @endif
                </p>

                <x-input-error for="photo" class="mt-2" />
            </div>
        @endif

        <!-- Name -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="name" value="{{ __('app.name') }}" />
            <x-input id="name" type="text" class="mt-1 block w-full" wire:model="state.name" required autocomplete="name" />
            <x-input-error for="name" class="mt-2" />
        </div>

        <!-- Email -->
        <div class="col-span-6 sm:col-span-4">
            <x-label for="email" value="{{ __('app.email') }}" />
            <x-input id="email" type="email" class="mt-1 block w-full" wire:model="state.email" required autocomplete="username" />
            <x-input-error for="email" class="mt-2" />

            @if ($this->user->roles->isNotEmpty())
                <p class="text-sm mt-2 text-gray-600 dark:text-gray-400">
                    {{ __('app.role') }}: {{ $this->user->roles->pluck('name')->first() }}
                </p>
            @endif

            @if (Laravel\Fortify\Features::enabled(Laravel\Fortify\Features::emailVerification()) && ! $this->user->hasVerifiedEmail())
                <p class="text-sm mt-2">
                    {{ __('app.email_unverified') }}

                    <button type="button" class="underline text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white rounded-md focus-ring" wire:click.prevent="sendEmailVerification">
                        {{ __('app.resend_verification') }}
                    </button>
                </p>

                @if ($this->verificationLinkSent)
                    <p class="mt-2 font-medium text-sm text-green-600">
                        {{ __('app.verification_link_sent_email') }}
                    </p>
                @endif
            @endif
        </div>

        <!-- Re-Auth für den E-Mail-Wechsel -->
        {{-- Nur eingeblendet, wenn die eingegebene E-Mail vom Original abweicht
             (Vergleich gegen die server-seitig gerenderte Adresse, damit das Feld
             nach einem Validierungsfehler-Rerender sichtbar bleibt). Die Pflicht
             erzwingt unabhängig davon die Server-Validierung in der Action. --}}
        <div class="col-span-6 sm:col-span-4" x-show="emailDirty" x-cloak>
            @if ($passwordLoginDisabled)
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('app.email_change_passkey_hint') }}
                </p>
            @else
                <x-label for="email-change-current-password" value="{{ __('app.current_password') }}" />
                <x-input id="email-change-current-password" type="password" class="mt-1 block w-full"
                         wire:model="state.current_password" autocomplete="current-password" />
                <p class="text-sm mt-2 text-gray-600 dark:text-gray-400">
                    {{ __('app.email_change_current_password_hint') }}
                </p>
            @endif

            <x-input-error for="current_password" class="mt-2" />
        </div>
    </x-slot>

    <x-slot name="actions">
        <x-action-message class="me-3" on="saved">
            {{ __('app.saved') }}
        </x-action-message>

        @if ($passwordLoginDisabled)
            <span x-show="!emailDirty">
                <x-button wire:loading.attr="disabled" wire:target="photo">
                    {{ __('app.save') }}
                </x-button>
            </span>

            {{-- Nur der E-Mail-Wechsel verlangt den Nachweis; eine Namensänderung
                 soll nicht am Passkey hängen. --}}
            <span x-show="emailDirty" x-cloak x-ref="emailChangeSave">
                <x-confirms-password wire:then="updateProfileInformation">
                    <x-button type="button" wire:loading.attr="disabled" wire:target="photo">
                        {{ __('app.save') }}
                    </x-button>
                </x-confirms-password>
            </span>

            <x-password-confirmation-modal scope="update-profile-information" />
        @else
            <x-button wire:loading.attr="disabled" wire:target="photo">
                {{ __('app.save') }}
            </x-button>
        @endif
    </x-slot>
</x-form-section>
