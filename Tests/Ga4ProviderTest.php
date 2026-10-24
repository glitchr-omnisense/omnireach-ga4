<?php

namespace Omnireach\Ga4\Tests;

use Omnireach\Exception\InvalidKeyException;
use Omnireach\Exception\NotSupportedException;
use Omnireach\Ga4\Ga4Factory;
use Omnireach\Ga4\Ga4Provider;
use Omnireach\Ga4\GoogleToken;
use Omnireach\Http\JsonApi;
use Omnireach\Model\Dimension;
use Omnireach\Model\Metric;
use Omnireach\Model\ReachQuery;
use Omnireach\Model\Row;
use Omnireach\Registry;
use Omnireach\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/**
 * Google Analytics' answers are composed for these tests in the shape the
 * Data API's runReport reference documents (dimension and metric headers,
 * rows of string values, rowCount); they were not recorded. The service
 * account's key is made by the test: no Google account was used.
 */
final class Ga4ProviderTest extends TestCase
{
    public function testLandingPagesFromGoogle(): void
    {
        $http = self::http();
        $report = self::provider($http)->audience(new ReachQuery(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-31'), [Dimension::EntryPage], [Metric::Visitors, Metric::Visits, Metric::BounceRate, Metric::VisitDuration], ['source' => 'google']));

        self::assertSame([['entry_page' => '/', 'visitors' => 1840, 'visits' => 2210, 'bounce_rate' => 0.4321, 'visit_duration' => 96.5], ['entry_page' => '/ateliers', 'visitors' => 602, 'visits' => 655, 'bounce_rate' => 0.3876, 'visit_duration' => 141.0]], array_map(static fn (Row $r) => $r->dimensions + $r->metrics, $report->rows));
        self::assertSame('https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport', $http->requests[0]['url']);
        self::assertSame([
            'dateRanges' => [['startDate' => '2026-10-01', 'endDate' => '2026-10-31']],
            'dimensions' => [['name' => 'landingPage']],
            'metrics' => [['name' => 'totalUsers'], ['name' => 'sessions'], ['name' => 'bounceRate'], ['name' => 'averageSessionDuration']],
            'dimensionFilter' => ['filter' => ['fieldName' => 'sessionSource', 'stringFilter' => ['matchType' => 'EXACT', 'value' => 'google']]],
            'limit' => 1000,
            'offset' => 0,
        ], $http->requests[0]['options']['json']);
    }

    public function testADailyHistoryAndTwoFilters(): void
    {
        $http = RecordedHttpClient::routes(['POST /properties/123456789:runReport' => (string) file_get_contents(__DIR__.'/Fixtures/daily.json')]);
        $report = self::provider($http)->audience(new ReachQuery(new \DateTimeImmutable('2026-10-01'), new \DateTimeImmutable('2026-10-02'), [Dimension::Date], [Metric::Visits], ['country' => 'FR', 'device' => 'mobile']));

        self::assertSame([['date' => '2026-10-01', 'visits' => 120], ['date' => '2026-10-02', 'visits' => 98]], array_map(static fn (Row $r) => $r->dimensions + $r->metrics, $report->rows));
        self::assertSame(['visits' => 218], $report->totals());
        $json = $http->requests[0]['options']['json'];
        self::assertIsArray($json);
        self::assertSame(['andGroup' => ['expressions' => [['filter' => ['fieldName' => 'countryId', 'stringFilter' => ['matchType' => 'EXACT', 'value' => 'FR']]], ['filter' => ['fieldName' => 'deviceCategory', 'stringFilter' => ['matchType' => 'EXACT', 'value' => 'mobile']]]]]], $json['dimensionFilter']);
        self::assertSame([['dimension' => ['dimensionName' => 'date']]], $json['orderBys']);
    }

    public function testRefusals(): void
    {
        try {
            self::provider(self::http())->audience(ReachQuery::lastDays(7, [Dimension::Query]));
            self::fail('No search queries in GA4 here.');
        } catch (NotSupportedException $e) {
            self::assertSame('Google Analytics does not report the dimension "query".', $e->getMessage());
        }
        $this->expectException(InvalidKeyException::class);
        self::provider(RecordedHttpClient::routes(['POST /properties/123456789:runReport' => [403, '{"error": {"code": 403, "message": "User does not have sufficient permissions for this property.", "status": "PERMISSION_DENIED"}}']]))->audience(ReachQuery::lastDays(7));
    }

    public function testTheFactoryWithAServiceAccount(): void
    {
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => \OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($pair);
        openssl_pkey_export($pair, $private);
        $key = ['type' => 'service_account', 'client_email' => 'omnireach-test@example-project.iam.gserviceaccount.com', 'private_key' => \is_string($private) ? $private : ''];
        $http = self::http();
        $registry = new Registry([new Ga4Factory($http)], ['ga' => ['factory' => 'ga4', 'options' => ['property' => '123456789', 'credentials' => (string) json_encode($key)]]]);

        self::assertSame(['audience'], $registry->get('ga')->capabilities()->contracts());
        self::assertCount(2, $registry->audience('ga')->audience(ReachQuery::lastDays(28, [Dimension::EntryPage], [Metric::Visitors, Metric::Visits, Metric::BounceRate, Metric::VisitDuration]))->rows);
        self::assertSame(['https://oauth2.googleapis.com/token', 'example-access-token-not-real'], [$http->requests[0]['url'], $http->requests[1]['options']['auth_bearer']]);
    }

    private static function provider(RecordedHttpClient $http): Ga4Provider
    {
        return new Ga4Provider(new JsonApi($http, 'Google Analytics'), new GoogleToken(new JsonApi($http, 'Google OAuth'), Ga4Factory::SCOPE, null, 'example-token-not-real'), '123456789');
    }

    private static function http(): RecordedHttpClient
    {
        return RecordedHttpClient::routes([
            'POST /token' => (string) file_get_contents(__DIR__.'/Fixtures/token.json'),
            'POST /properties/123456789:runReport' => (string) file_get_contents(__DIR__.'/Fixtures/landing-pages.json'),
        ]);
    }
}
