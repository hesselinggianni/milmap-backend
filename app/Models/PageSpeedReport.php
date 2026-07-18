<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Eén Google PageSpeed Insights (Lighthouse) meting van een URL op mobiel of
 * desktop. Historisch bewaard zodat de admin-UI trends kan tonen.
 */
class PageSpeedReport extends Model
{
    protected $table = 'pagespeed_reports';

    protected $fillable = [
        'url', 'strategy',
        'performance', 'accessibility', 'best_practices', 'seo',
        'lcp', 'fcp', 'cls', 'tbt', 'si', 'ttfb',
        'error',
    ];

    protected $casts = [
        'performance'    => 'integer',
        'accessibility'  => 'integer',
        'best_practices' => 'integer',
        'seo'            => 'integer',
        'lcp'            => 'float',
        'fcp'            => 'float',
        'cls'            => 'float',
        'tbt'            => 'float',
        'si'             => 'float',
        'ttfb'           => 'float',
    ];

    public function toApiArray(): array
    {
        return [
            'id'            => $this->id,
            'url'           => $this->url,
            'strategy'      => $this->strategy,
            'performance'   => $this->performance,
            'accessibility' => $this->accessibility,
            'bestPractices' => $this->best_practices,
            'seo'           => $this->seo,
            'lcp'           => $this->lcp,
            'fcp'           => $this->fcp,
            'cls'           => $this->cls,
            'tbt'           => $this->tbt,
            'si'            => $this->si,
            'ttfb'          => $this->ttfb,
            'error'         => $this->error,
            'measuredAt'    => optional($this->created_at)->toIso8601String(),
        ];
    }
}
