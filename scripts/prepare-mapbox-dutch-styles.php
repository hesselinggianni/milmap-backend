<?php

// Prepare three Dutch raster styles without changing the Mapbox account.
// With --publish, a MAPBOX_STYLES_WRITE_TOKEN in .env is required. Never prints tokens.
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$output = storage_path('app/tiles/mapbox-overview/dutch-style-preparation');
Illuminate\Support\Facades\File::ensureDirectoryExists($output);
$publish = in_array('--publish', $argv, true);
$env = Dotenv\Dotenv::createArrayBacked($root)->safeLoad();
$writeToken = $env['MAPBOX_STYLES_WRITE_TOKEN'] ?? getenv('MAPBOX_STYLES_WRITE_TOKEN');
if ($publish && ! $writeToken) {
    fwrite(STDERR, "Set MAPBOX_STYLES_WRITE_TOKEN with styles:write in the server .env first.\n");
    exit(1);
}
function dutchNames(mixed $value): mixed
{
    if (! is_array($value)) {
        return is_string($value) ? preg_replace('/\{name(?:_[a-zA-Z-]+)?\}/', '{name_nl}', $value) : $value;
    }
    if (($value[0] ?? null) === 'get' && is_string($value[1] ?? null)
        && preg_match('/^name(?:_(?:en|latin|local|int|nl))?$/D', $value[1])) {
        return ['coalesce', ['get', 'name_nl'], $value];
    }
    return array_map('dutchNames', $value);
}
$mapping = ['satellite-v9' => 'mapbox/satellite-v9'];
foreach (['outdoors-v12', 'streets-v11', 'satellite-streets-v11'] as $logical) {
    $response = Illuminate\Support\Facades\Http::timeout(30)->get(
        "https://api.mapbox.com/styles/v1/mapbox/{$logical}", ['access_token' => config('services.mapbox.token')]
    );
    if (! $response->successful()) {
        fwrite(STDERR, "Style read failed: {$logical}, HTTP {$response->status()}\n");
        exit(1);
    }
    $style = $response->json();
    foreach (['id', 'owner', 'created', 'modified', 'draft', 'visibility'] as $field) {
        unset($style[$field]);
    }
    $style['name'] = 'MilMap NL ' . $logical;
    $style['metadata']['mapbox:language'] = 'nl';
    $changed = 0;
    foreach ($style['layers'] as &$layer) {
        if (isset($layer['layout']['text-field'])) {
            $old = $layer['layout']['text-field'];
            $layer['layout']['text-field'] = dutchNames($old);
            if ($old !== $layer['layout']['text-field']) {
                $changed++;
            }
        }
    }
    unset($layer);
    if ($changed === 0) {
        fwrite(STDERR, "No localizable label layers found: {$logical}\n");
        exit(1);
    }
    file_put_contents("{$output}/{$logical}-nl.json", json_encode($style, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "Prepared {$logical}: {$changed} Dutch label layers.\n";
    if ($publish) {
        $parts = explode('.', $writeToken);
        $payload = json_decode(base64_decode(strtr($parts[1] ?? '', '-_', '+/')), true);
        $username = $payload['u'] ?? '';
        if (! preg_match('/^[A-Za-z0-9_-]+$/D', $username)) {
            fwrite(STDERR, "Cannot determine the Mapbox account from the write token.\n");
            exit(1);
        }
        $response = Illuminate\Support\Facades\Http::timeout(60)->post(
            "https://api.mapbox.com/styles/v1/{$username}?access_token=" . urlencode($writeToken), $style
        );
        if (! $response->successful() || ! $response->json('id')) {
            fwrite(STDERR, "Style publication failed: {$logical}, HTTP {$response->status()}\n");
            exit(1);
        }
        $mapping[$logical] = $username . '/' . $response->json('id');
        // Persist partial results too; a failed later style need not be recreated.
        file_put_contents("{$output}/published-styles.json", json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "Published {$logical}.\n";
    }
}
if ($publish) {
    file_put_contents(storage_path('app/tiles/mapbox-overview/dutch-styles.json'), json_encode($mapping, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}
