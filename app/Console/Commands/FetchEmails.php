<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\ImapSetting; // Je model voor de IMAP-instellingen
use App\Http\Controllers\EmailController; // Je controller waar de fetchEmails functie zich bevindt

class FetchEmails extends Command
{
    /**
     * De naam en het signaal van het commando.
     *
     * @var string
     */
    protected $signature = 'emails:fetch';

    /**
     * De beschrijving van het commando.
     *
     * @var string
     */
    protected $description = 'Fetch new emails from the configured IMAP accounts';

    /**
     * Maak een nieuw commando aan.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Voer de opdracht uit.
     *
     * @return void
     */
    public function handle()
    {
        // Roep de fetchEmails functie aan uit je controller
        $emailController = new EmailController();
        $response = $emailController->fetchEmails();

        // Optioneel: Log de respons als je dat wilt
        \Log::info('Fetch emails command ran: ' . json_encode($response));
        
        $this->info('E-mails succesvol opgehaald');
    }
}
