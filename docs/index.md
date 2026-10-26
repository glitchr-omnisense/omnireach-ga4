# omnireach/ga4

Google Analytics 4 for [omnisense/omnireach](https://github.com/glitchr-omnisense/omnireach):
visitors, sessions, pageviews, bounce rate and session length of a property, by landing page,
page, source, country, device or day, combined as needed. The provider calls:

| Operation | API | Request |
|---|---|---|
| token (a service account) | Google OAuth 2.0, JWT bearer grant | `POST https://oauth2.googleapis.com/token` (or the key's `token_uri`) |
| audience | Google Analytics Data API v1beta | `POST {url}/properties/{property}:runReport` |

`{url}` is `https://analyticsdata.googleapis.com/v1beta` by default; Google has no sandbox.
Google's PHP libraries are not used (`google/analytics-data` runs on gRPC and protobuf,
`google/apiclient` and `google/auth` on Guzzle): one JSON call per page of rows and one signed
JWT, through the application's HTTP client. `GoogleToken` is the same class as in
[omnireach/search-console](https://github.com/glitchr-omnisense/omnireach-search-console), under
this package's namespace.

## Installation

```sh
composer require omnireach/ga4 symfony/http-client
```

Requires PHP 8.2, `ext-openssl` (to sign the JWT), `omnisense/omnireach` (1.x) and
`symfony/http-client-contracts` (3).

## Credentials

Either of:

- **A service account's JSON key** (`credentials`): a service account of a Google Cloud project
  with the Google Analytics Data API enabled, its key downloaded as JSON, and the account's e-mail
  given the Viewer role on the property (Admin, Property access management).
- **An access token** (`access_token`) with the
  `https://www.googleapis.com/auth/analytics.readonly` scope, obtained elsewhere; used as it is,
  never refreshed.

The property is its numeric id (Admin, Property details), not the `G-` measurement id of the tag.

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `property` | string | - | yes | the property's numeric id: `123456789` |
| `credentials` | string | `''` | one of the two | the service account's JSON key, or the path of its file |
| `access_token` | string | `''` | one of the two | a bearer token with the scope above; when both are given, the token is used |
| `url` | string | `https://analyticsdata.googleapis.com/v1beta` | no | the Data API's base URL |

Per query: `ReachQuery::$site` replaces `property` for one report.

## Capabilities

| | |
|---|---|
| contract | `AudienceInterface` |
| dimensions | page, entry page, source, country, device, date - combined freely |
| metrics | visitors, visits, pageviews, bounce rate, visit duration (visitors, visits, pageviews by default) |
| filters | on any of its dimensions, exact |
| `SearchPerformanceInterface` | no |

The family's names map to GA4's:

| Omnireach | GA4 |
|---|---|
| `page` | `pagePath` |
| `entry_page` | `landingPage` |
| `source` | `sessionSource` |
| `country` | `countryId` |
| `device` | `deviceCategory` |
| `date` | `date` |
| `visitors` | `totalUsers` |
| `visits` | `sessions` |
| `pageviews` | `screenPageViews` |
| `bounce_rate` | `bounceRate` |
| `visit_duration` | `averageSessionDuration` |

## Behaviour

**Token.** As for Search Console: with a service account, a JWT signed with its key (`RS256`,
`exp` an hour on) is exchanged on the first report, and the token kept until a minute before it
expires.

**The report.** One `POST` per page of rows, with a bearer token:

```
POST https://analyticsdata.googleapis.com/v1beta/properties/123456789:runReport
  json: {"dateRanges":[{"startDate":"2026-10-01","endDate":"2026-10-31"}],"dimensions":[{"name":"landingPage"}],"metrics":[{"name":"sessions"},{"name":"averageSessionDuration"}],"dimensionFilter":{"filter":{"fieldName":"sessionSource","stringFilter":{"matchType":"EXACT","value":"google"}}},"limit":1000,"offset":0}
```

(the body sent for entry pages, visits and visit duration, filtered on `source => google`, seen on
a recorded client in the harness, 2026-10-26)

- one filter is a `dimensionFilter.filter`; several an `andGroup` of `EXACT` string filters;
- with the date, `orderBys` sorts by date, so a history comes in order of its days;
- `limit` is the rows still wanted, at most 250,000, `offset` the rows already read: the provider
  pages until `limit` rows are read, a page comes back short, or GA4's `rowCount` is reached.

**Rows.** Values come as strings and are typed: visitors, visits and pageviews as integers, the
bounce rate as a ratio rounded to 4 decimals (GA4 gives it from 0 to 1), the visit duration in
seconds rounded to 1. GA4's dates `YYYYMMDD` become `Y-m-d`; the other dimensions are passed as GA4
gives them (`countryId` ISO 3166-1 alpha-2, `deviceCategory` `desktop`/`mobile`/`tablet`). A value
that is not numeric is left out of its row. The query's filters and limit are then applied.

**Limits** (`capabilities()->limits`): token quotas per property (per day, per hour, concurrent
requests), a report costing more the more rows and dimensions it has; rows can be thresholded
(Google signals) or grouped under `(other)` on high-cardinality reports. The provider does not
read GA4's quota or sampling metadata: `Report::$note` is always `null`.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnireach\Ga4\Ga4Factory;
use Omnireach\Model\Dimension;
use Omnireach\Model\Metric;
use Omnireach\Model\ReachQuery;
use Omnireach\Registry;
use Symfony\Component\HttpClient\HttpClient;

$registry = new Registry([new Ga4Factory(HttpClient::create())], [
    'ga' => ['factory' => 'ga4', 'options' => ['property' => '123456789', 'credentials' => '/secrets/ga4.json']],
]);
$ga = $registry->audience('ga');

// Landing pages of the last 28 days, from Google, in France.
$report = $ga->audience(new ReachQuery(
    (new DateTimeImmutable('yesterday'))->modify('-27 days'), new DateTimeImmutable('yesterday'),
    [Dimension::EntryPage], [Metric::Visits, Metric::BounceRate],
    ['source' => 'google', 'country' => 'FR'],
));
foreach ($report->rows as $row) {
    printf("%-40s %6d sessions %5.1f%% bounce\n", $row->dimension(Dimension::EntryPage), $row->metric(Metric::Visits), 100 * ($row->metric(Metric::BounceRate) ?? 0));
}

// Sessions by device, day by day.
$history = $ga->audience(ReachQuery::lastDays(90, [Dimension::Date, Dimension::Device], [Metric::Visits]));
```

In Symfony:

```yaml
services:
    Omnireach\Ga4\Ga4Factory:
        arguments: ['@http_client']
    Omnireach\Registry:
        arguments:
            $factories: ['@Omnireach\Ga4\Ga4Factory']
            $config:
                ga: { factory: ga4, options: { property: '%env(GA4_PROPERTY)%', credentials: '%env(GA4_SERVICE_ACCOUNT)%' } }
```

The example needs a property and credentials: it was not run live.

## Errors

The provider's HTTP calls are named `Google Analytics` (the reports) and `Google OAuth` (the token):

- 401, 403: `InvalidKeyException` - the token expired, the service account not given access to the
  property (`Google Analytics refused the credentials (403) for a report of property 123456789:
  ...`);
- 404: `NotFoundException`; another status of 400 or more (a 400 for an incompatible mix of
  dimensions and metrics, a 429 for quotas), or a body that is not JSON: `ProviderException`, with
  GA4's message (`error.message`);
- a network error: `UnreachableException`;
- no `property`, neither credentials nor token: `InvalidConfigException`.

A dimension or metric it does not report (`query`, `clicks`...) is a `NotSupportedException`
before any call.

## Verified, and not

Tested offline (`Tests/Ga4ProviderTest.php`, in the core's harness, PHP 8.4): landing pages with a
filter, the request's URL and body; a daily history with two filters (`andGroup`) sorted by date,
its totals; the refusals; the factory with a service account (a key made by the test), the token
asked first then sent as bearer. The answers are composed in the shape the `runReport` reference
documents, not recorded. `Tests/BareTest.php` loads every class with the package's requirements
alone.

Not verified: no Google account was used; no call was made to the Data API or to Google's token
endpoint.

## Responsibility

An independent, unofficial client, affiliated with Google in no way. Whoever runs it complies with
Google's terms (<https://developers.google.com/terms>) and the Data API's quotas
(<https://developers.google.com/analytics/devguides/reporting/data/v1/quotas>): see the README.
