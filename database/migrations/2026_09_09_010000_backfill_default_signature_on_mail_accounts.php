<?php

use App\Models\MailAccount;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Bestaande inboxen die zijn aangemaakt vóórdat AdminMailAccountController
     * er standaard een handtekening aan gaf, krijgen die nu alsnog — anders
     * blijft hun uitgaande mail ongebrand tot iemand het handmatig invult.
     */
    public function up(): void
    {
        MailAccount::whereNull('signature_html')
            ->orWhere('signature_html', '')
            ->update(['signature_html' => MailAccount::defaultSignatureHtml()]);
    }

    public function down(): void
    {
        // Niet omkeerbaar: we kunnen niet onderscheiden welke accounts hun
        // handtekening al leeg hadden vóór deze migratie liep.
    }
};
