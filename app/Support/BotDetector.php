<?php

namespace App\Support;

/**
 * Herkent bot-/crawler-/tool-verkeer aan de User-Agent, zodat de first-party
 * analytics (site_events + app_events) alleen echte bezoekers telt.
 *
 * Waarom: zoekmachine-crawlers (Googlebot/Bingbot) en Lighthouse — waaronder
 * onze eigen PageSpeed-cron die milmap.nl-pagina's doormeet — voeren gewoon
 * JavaScript uit en vuren dus de pageview/route-events af, maar klikken nooit
 * op knoppen. Dat blaast de bezoek-tellers op terwijl de actie-tellers laag
 * blijven. Server-side UA-filtering vangt vrijwel al dit verkeer; headless
 * automation die zijn UA spoofed wordt daarnaast client-side geweerd via
 * navigator.webdriver (zie analytics.js in de app en analytics.client.ts op
 * milmap.nl).
 */
class BotDetector
{
    /**
     * Eén case-insensitive patroon. 'bot' dekt de meeste crawlers (googlebot,
     * bingbot, duckduckbot, uptimerobot, discordbot, …); de rest zijn bekende
     * namen zonder 'bot' erin: headless browsers/automation, HTTP-libs,
     * linkpreview-fetchers, SEO-tools en monitoring-diensten.
     */
    private const PATTERN = '/bot|crawl|spider|slurp|headless|lighthouse|chrome-lighthouse'
        . '|phantomjs|selenium|puppeteer|playwright|scrapy'
        . '|curl\/|wget\/|python-|httpx|aiohttp|okhttp|go-http-client|libwww|java\/'
        . '|facebookexternalhit|whatsapp|telegram|discord|slackbot|twitterbot|linkedin'
        . '|bingpreview|yandex|baiduspider|sogou|applebot|amazonbot|bytespider|petalbot'
        . '|gptbot|claudebot|ccbot|ahrefs|semrush|mj12|dotbot'
        . '|pingdom|statuscake|uptime|site24x7|newrelic|datadoghq/i';

    /**
     * Is deze User-Agent (vrijwel zeker) geen echte bezoeker? Een lege UA telt
     * ook als bot: echte browsers sturen er altijd één mee.
     */
    public static function isBot(?string $userAgent): bool
    {
        $ua = trim((string) $userAgent);
        if ($ua === '') {
            return true;
        }

        return (bool) preg_match(self::PATTERN, $ua);
    }
}
