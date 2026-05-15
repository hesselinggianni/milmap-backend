<?php 
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreWorkspaceRequest extends FormRequest
{
    public function authorize()
    {
        return true; // Zorg ervoor dat alleen geauthenticeerde gebruikers toegang hebben
    }

    public function rules()
    {
        return [
            'name' => 'required|string|max:255',
            'status' => 'nullable|string|max:255',
            'logo' => 'nullable|url', 
            'color' => 'nullable|string|max:255',
            'workspaceType' => 'nullable|string|max:255',
            'manyPeople' => 'nullable|string|max:255',
            'manage' => 'nullable|string|max:255',
            'selectedFeatures' => 'nullable|array',
            'selectedFeatures.*' => 'nullable|string|max:255',
        ];
    }
}
