<?php

/*
|--------------------------------------------------------------------------
| Validation Language Lines (Dutch)
|--------------------------------------------------------------------------
|
| Covers the validation rules actually reachable from the web surface (see
| src/Http/Requests/*.php and the `validate()` calls in
| src/Http/Controllers/Web/*.php) — web parity T7 (GAP-6 M4's known
| leftover: Laravel's default English validation messages were never
| translated). Written in the same je-register Dutch as lang/nl.json, not
| a formal "u" register. Kept close to Laravel's own upstream Dutch
| translation set (laravel-lang/lang) for terms a Dutch user already knows
| from other apps, but every line here was written for this file.
|
*/

return [

    'accepted' => ':attribute moet geaccepteerd worden.',
    'active_url' => ':attribute is geen geldige URL.',
    'after' => ':attribute moet een datum na :date zijn.',
    'after_or_equal' => ':attribute moet een datum na of gelijk aan :date zijn.',
    'alpha' => ':attribute mag alleen letters bevatten.',
    'alpha_dash' => ':attribute mag alleen letters, cijfers, streepjes en underscores bevatten.',
    'alpha_num' => ':attribute mag alleen letters en cijfers bevatten.',
    'array' => ':attribute moet een lijst zijn.',
    'before' => ':attribute moet een datum voor :date zijn.',
    'before_or_equal' => ':attribute moet een datum voor of gelijk aan :date zijn.',
    'boolean' => ':attribute moet aan of uit staan.',
    'confirmed' => ':attribute komt niet overeen met de bevestiging.',
    'date' => ':attribute is geen geldige datum.',
    'distinct' => ':attribute komt dubbel voor.',
    'email' => ':attribute is geen geldig e-mailadres.',
    'enum' => 'De gekozen :attribute is ongeldig.',
    'exists' => 'De gekozen :attribute bestaat niet.',
    'file' => ':attribute moet een bestand zijn.',
    'image' => ':attribute moet een afbeelding zijn.',
    'in' => 'De gekozen :attribute is ongeldig.',
    'integer' => ':attribute moet een geheel getal zijn.',
    'max' => [
        'array' => ':attribute mag niet meer dan :max items bevatten.',
        'file' => ':attribute mag niet groter zijn dan :max kilobytes.',
        'numeric' => ':attribute mag niet hoger zijn dan :max.',
        'string' => ':attribute mag niet meer dan :max tekens bevatten.',
    ],
    'mimes' => ':attribute moet een bestand van het type :values zijn.',
    'mimetypes' => ':attribute moet een bestand van het type :values zijn.',
    'min' => [
        'array' => ':attribute moet minstens :min items bevatten.',
        'file' => ':attribute moet minstens :min kilobytes zijn.',
        'numeric' => ':attribute moet minstens :min zijn.',
        'string' => ':attribute moet minstens :min tekens bevatten.',
    ],
    'nullable' => ':attribute mag leeg zijn.',
    'numeric' => ':attribute moet een getal zijn.',
    'required' => ':attribute is verplicht.',
    'required_if' => ':attribute is verplicht wanneer :other :value is.',
    'string' => ':attribute moet tekst zijn.',
    'unique' => ':attribute is al in gebruik.',
    'uuid' => ':attribute is geen geldige UUID.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The field names users actually see on the web forms, so a message
    | reads "E-mail is verplicht" instead of "email is verplicht".
    |
    */

    'attributes' => [
        'name' => 'naam',
        'email' => 'e-mailadres',
        'password' => 'wachtwoord',
        'password_confirmation' => 'wachtwoordbevestiging',
        'code' => 'code',
        'confirm_name' => 'bevestigingsnaam',
        'strategy' => 'strategie',
        'target_shelf_id' => 'doelplank',
        'target_location_id' => 'doellocatie',
        'role' => 'rol',
        'user_id' => 'gebruiker',
        'type' => 'type',
        'quantity' => 'aantal',
        'low_stock_threshold' => 'ondergrens voor lage voorraad',
        'description' => 'omschrijving',
        'image' => 'foto',
        'position' => 'positie',
        'location_id' => 'locatie',
        'ids' => 'volgorde',
        'mode' => 'weergavemodus',
        'locale' => 'taal',
        'color' => 'kleur',
        'icon' => 'icoon',
    ],

];
