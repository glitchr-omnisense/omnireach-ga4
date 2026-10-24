<?php

namespace Omnireach\Ga4;

use Omnireach\AudienceInterface;
use Omnireach\Capabilities;
use Omnireach\Http\JsonApi;
use Omnireach\Model\Dimension;
use Omnireach\Model\Metric;
use Omnireach\Model\ReachQuery;
use Omnireach\Model\Report;
use Omnireach\Model\Row;

/**
 * The Google Analytics Data API (https://developers.google.com/analytics/devguides/reporting/data/v1):
 * POST properties/{id}:runReport - date range, dimensions, metrics, EXACT
 * string filters, pages of rows (limit, offset) - with a bearer token.
 */
final class Ga4Provider implements AudienceInterface
{
    private const DIMENSIONS = ['page' => 'pagePath', 'entry_page' => 'landingPage', 'source' => 'sessionSource', 'country' => 'countryId', 'device' => 'deviceCategory', 'date' => 'date'];

    private const METRICS = ['visitors' => 'totalUsers', 'visits' => 'sessions', 'pageviews' => 'screenPageViews', 'bounce_rate' => 'bounceRate', 'visit_duration' => 'averageSessionDuration'];

    /** The most rows runReport returns in one call. */
    private const PAGE = 250000;

    public function __construct(
        private readonly JsonApi $api,
        private readonly GoogleToken $token,
        private readonly string $property,
        private readonly string $url = 'https://analyticsdata.googleapis.com/v1beta',
    ) {
    }

    public function getName(): string
    {
        return 'ga4';
    }

    public function getTitle(): string
    {
        return 'Google Analytics 4';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            audience: true,
            dimensions: [Dimension::Page, Dimension::EntryPage, Dimension::Source, Dimension::Country, Dimension::Device, Dimension::Date],
            metrics: [Metric::Visitors, Metric::Visits, Metric::Pageviews, Metric::BounceRate, Metric::VisitDuration],
            terms: ['https://developers.google.com/analytics/devguides/reporting/data/v1/quotas', 'https://developers.google.com/terms'],
            limits: ['token quotas per property (per day, per hour, concurrent requests) - a report costs more the more rows and dimensions it has', 'rows can be thresholded (Google signals) or grouped under "(other)" on high-cardinality reports'],
        );
    }

    public function audience(ReachQuery $query): Report
    {
        $metrics = $query->metricsOr([Metric::Visitors, Metric::Visits, Metric::Pageviews]);
        $this->capabilities()->check($query, $metrics, 'Google Analytics');
        $property = $query->site ?? $this->property;
        $body = [
            'dateRanges' => [['startDate' => $query->start->format('Y-m-d'), 'endDate' => $query->end->format('Y-m-d')]],
            'dimensions' => array_map(static fn (Dimension $d) => ['name' => self::DIMENSIONS[$d->value] ?? $d->value], $query->dimensions),
            'metrics' => array_map(static fn (Metric $m) => ['name' => self::METRICS[$m->value] ?? $m->value], $metrics),
        ];
        $filters = array_map(static fn (string $dimension, string $value) => ['filter' => ['fieldName' => self::DIMENSIONS[$dimension] ?? $dimension, 'stringFilter' => ['matchType' => 'EXACT', 'value' => $value]]], array_keys($query->filters), $query->filters);
        if (1 === \count($filters)) {
            $body['dimensionFilter'] = $filters[0];
        } elseif ([] !== $filters) {
            $body['dimensionFilter'] = ['andGroup' => ['expressions' => $filters]];
        }
        if ($query->has(Dimension::Date)) {
            $body['orderBys'] = [['dimension' => ['dimensionName' => 'date']]];
        }

        $rows = [];
        for ($offset = 0; \count($rows) < $query->limit; $offset += self::PAGE) {
            $size = min(self::PAGE, $query->limit - \count($rows));
            $answer = $this->api->request('POST', $this->url.'/properties/'.rawurlencode($property).':runReport', ['json' => $body + ['limit' => $size, 'offset' => $offset], 'auth_bearer' => $this->token->get()], 'a report of property '.$property);
            $lines = $answer->list('rows');
            foreach ($lines as $line) {
                $dimensions = [];
                foreach ($query->dimensions as $i => $dimension) {
                    $value = (string) $line->string('dimensionValues.'.$i.'.value');
                    $dimensions[$dimension->value] = Dimension::Date === $dimension && 8 === \strlen($value) ? substr($value, 0, 4).'-'.substr($value, 4, 2).'-'.substr($value, 6, 2) : $value;
                }
                $values = [];
                foreach ($metrics as $i => $metric) {
                    $value = $line->string('metricValues.'.$i.'.value');
                    if (null !== $value && is_numeric($value)) {
                        $values[$metric->value] = \in_array($metric, [Metric::BounceRate, Metric::VisitDuration], true) ? round((float) $value, \in_array($metric, [Metric::BounceRate], true) ? 4 : 1) : (int) $value;
                    }
                }
                $rows[] = new Row($dimensions, $values);
            }
            if (\count($lines) < $size || $offset + \count($lines) >= ($answer->int('rowCount') ?? 0)) {
                break;
            }
        }

        return new Report('ga4', $property, $query, $query->apply($rows));
    }
}
