<?php

namespace App\Http\Controllers;

use App\Models\AdminLink;
use Illuminate\Http\Request;

/**
 * CRUD voor de partnersite-snelkoppelingen in de admin-sidebar.
 * Alle routes hangen achter admin.auth (admin-token vereist).
 *
 * Wachtwoorden: opgeslagen via de encrypted-cast op AdminLink; de lijst geeft
 * alleen has_password terug en het aparte reveal-endpoint ontsleutelt pas
 * wanneer de admin er expliciet om vraagt (kopieer-knop in de UI).
 */
class AdminLinkController extends Controller
{
    public function index()
    {
        $links = AdminLink::orderBy('position')->orderBy('id')->get();

        return response()->json([
            'links' => $links->map->toApiArray()->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateLink($request);

        $data['position'] = (int) AdminLink::max('position') + 1;
        $link = AdminLink::create($data);

        return response()->json(['link' => $link->toApiArray()], 201);
    }

    public function update(Request $request, $id)
    {
        $link = AdminLink::findOrFail($id);
        $data = $this->validateLink($request, partial: true);

        // Leeg wachtwoord-veld in het formulier betekent "laat staan";
        // expliciet wissen kan met password_clear.
        if (array_key_exists('password', $data) && ($data['password'] === null || $data['password'] === '')) {
            unset($data['password']);
        }
        if ($request->boolean('password_clear')) {
            $data['password'] = null;
        }

        $link->update($data);

        return response()->json(['link' => $link->fresh()->toApiArray()]);
    }

    public function destroy($id)
    {
        AdminLink::findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }

    /** On-demand ontsleutelen — alleen wanneer de admin expliciet kopieert. */
    public function revealPassword($id)
    {
        $link = AdminLink::findOrFail($id);

        return response()->json(['password' => $link->password]);
    }

    private function validateLink(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'label'    => [$required, 'string', 'max:120'],
            'url'      => [$required, 'string', 'max:500', 'url'],
            'username' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:500'],
            'notes'    => ['nullable', 'string', 'max:500'],
        ]);
    }
}
