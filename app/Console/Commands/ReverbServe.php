<?php

namespace App\Console\Commands;

use Laravel\Reverb\Servers\Reverb\Console\Commands\StartServer;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * reverb:start, maar ook bruikbaar zonder pcntl-extensie.
 *
 * De PHP-CLI op de productieserver (DirectAdmin, geen root) heeft geen pcntl.
 * reverb:start vraagt bij het opstarten SIGINT/SIGTERM/SIGTSTP op en crasht dan
 * met "Undefined constant SIGINT". Zonder signaal-afhandeling stopt de server
 * gewoon bij een kill; reverb:restart werkt via de cache-vlag die StartServer
 * periodiek controleert, dus dat blijft werken.
 *
 * Wordt elke minuut via cron + flock gestart (zie deploy.sh), zodat een
 * gecrashte server binnen een minuut terug is.
 */
#[AsCommand(name: 'reverb:serve')]
class ReverbServe extends StartServer
{
    protected $signature = 'reverb:serve
                {--host= : The IP address the server should bind to}
                {--port= : The port the server should listen on}
                {--path= : The path the server should prefix to all routes}
                {--hostname= : The hostname the server is accessible from}
                {--debug : Indicates whether debug messages should be displayed in the terminal}';

    protected $description = 'Start de Reverb-server (werkt ook zonder pcntl)';

    public function getSubscribedSignals(): array
    {
        return extension_loaded('pcntl') ? parent::getSubscribedSignals() : [];
    }
}
