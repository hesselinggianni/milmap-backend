<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailCustomTemplate extends Model
{
    protected $table = 'mail_custom_templates';

    protected $fillable = [
        'key', 'label', 'description', 'locales', 'subjects', 'blocks', 'created_by',
    ];

    protected $casts = [
        'locales'  => 'array',
        'subjects' => 'array',
        'blocks'   => 'array',
    ];

    /**
     * Map deze DB-template naar een registry-entry (zelfde vorm als de entries in
     * config/mail_templates.php), zodat MailTemplateRegistry ze naadloos kan mergen.
     * `custom` + `id` onderscheiden 'm van de code-templates; `blocks` draagt de
     * per-taal inhoud voor de gedeelde emails.custom-view.
     */
    public function toRegistryEntry(): array
    {
        return [
            'label'       => $this->label,
            'description' => $this->description,
            'category'    => null,
            'locales'     => $this->locales ?: ['nl'],
            'subjects'    => $this->subjects ?: [],
            'view'        => 'emails.custom',
            'custom'      => true,
            'id'          => $this->id,
            'blocks'      => $this->blocks ?: [],
        ];
    }
}
