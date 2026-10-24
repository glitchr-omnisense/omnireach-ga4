<?php

namespace Omnireach\Ga4;

use Omnireach\Config;
use Omnireach\Exception\InvalidConfigException;
use Omnireach\Http\JsonApi;
use Omnireach\ProviderFactory;
use Omnireach\ProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google Analytics 4, from its options:
 *
 *   property      the GA4 property's numeric id (Admin, Property details) - not the "G-" measurement id
 *   credentials   a service account's JSON key, or the path of its file - the account given the Viewer
 *                 role on the property (Admin, Property access management); or:
 *   access_token  a token with the analytics.readonly scope, obtained elsewhere
 *   url           https://analyticsdata.googleapis.com/v1beta
 */
final class Ga4Factory extends ProviderFactory
{
    public const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnireach.factory_name' => 'ga4',
            'omnireach.factory_title' => 'Google Analytics 4',
            'omnireach.required_options' => ['property'],
            'credentials' => '',
            'access_token' => '',
            'url' => 'https://analyticsdata.googleapis.com/v1beta',
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        $http = $this->http ?? throw new InvalidConfigException('The "ga4" provider needs an HTTP client: give one to Ga4Factory.');
        $credentials = $c->string('credentials');

        return new Ga4Provider(
            new JsonApi($http, 'Google Analytics'),
            new GoogleToken(new JsonApi($http, 'Google OAuth'), self::SCOPE, null !== $credentials ? GoogleToken::credentials($credentials) : null, $c->string('access_token')),
            (string) $c->string('property'),
            rtrim($c->string('url') ?? 'https://analyticsdata.googleapis.com/v1beta', '/'),
        );
    }
}
