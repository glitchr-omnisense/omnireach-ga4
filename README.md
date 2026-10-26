# omnireach/ga4

[omnisense/omnireach](https://github.com/glitchr-omnisense/omnireach)'s Google Analytics 4, under
any PHP host: visitors, sessions, pageviews, bounce rate and session length of a property, by
landing page, page, source, country, device or day, combined as needed.

```php
use Omnireach\Ga4\Ga4Factory;
use Omnireach\Registry;
use Symfony\Component\HttpClient\HttpClient;

$registry = new Registry([new Ga4Factory(HttpClient::create())], [
    'ga' => ['factory' => 'ga4', 'options' => ['property' => '123456789', 'credentials' => '/secrets/service-account.json']],
]);
```

Options: `property` (the numeric property id, not the `G-` measurement id), `credentials` or
`access_token` (scope `analytics.readonly`; the service account given the Viewer role on the
property), `url`.

## The API

`POST properties/{id}:runReport` of the Data API v1beta
(<https://developers.google.com/analytics/devguides/reporting/data/v1>): `dateRanges`,
`dimensions` (`landingPage`, `pagePath`, `sessionSource`, `countryId`, `deviceCategory`,
`date`), `metrics` (`totalUsers`, `sessions`, `screenPageViews`, `bounceRate`,
`averageSessionDuration`), `dimensionFilter` (EXACT string filters, an `andGroup` for
several), `limit` (250,000 at most) and `offset`. Values come as strings and are typed here;
dates `YYYYMMDD` become Y-m-d.

**Terms and limits** (<https://developers.google.com/analytics/devguides/reporting/data/v1/quotas>):
token quotas per property (per day, per hour, concurrent requests); rows may be thresholded
(Google signals) or grouped under "(other)" on high-cardinality reports.

**Google credentials**: a service account's JSON key (`credentials`: the JSON or the path of
its file) - the token is asked with a JWT signed by its key (RS256, the OAuth 2.0 JWT bearer
grant) and kept until a minute before it expires - or an `access_token` obtained elsewhere.
`GoogleToken` is the same class in omnireach/search-console and omnireach/ga4.

**Reuse**: Google's PHP libraries were set aside: `google/apiclient` with
`google/apiclient-services` (every Google API generated, a very large package, its own Guzzle
stack), `google/analytics-data` (gRPC and protobuf through google/gax), `google/auth` (Guzzle
again). The family injects the application's HTTP client and needs one JSON call per report and
one signed JWT, done here with ext-openssl.

## Verified, and not

- Verified (the core's harness, PHP 8.4): landing pages with a filter, a daily history with two
  filters (`andGroup`) in date order, what GA4 cannot answer here, a refused property, the
  factory with a service account (a key made by the test) - against answers composed in the shape
  the runReport reference documents, not recorded; every class loading with the package's
  requirements alone (`BareTest`).
- Not verified live: no Google account was used.

## Licence

MIT. An unofficial client, affiliated with Google in no way.
