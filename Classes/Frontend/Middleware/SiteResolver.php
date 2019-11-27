<?php
declare(strict_types=1);
namespace In2code\In2altroute\Frontend\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Routing\Exception\NoConfigurationException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\FrontendRestrictionContainer;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Routing\Route;
use TYPO3\CMS\Core\Routing\RouteCollection;
use TYPO3\CMS\Core\Routing\RouteResultInterface;
use TYPO3\CMS\Core\Routing\SiteRouteResult;
use TYPO3\CMS\Core\Site\Entity\PseudoSite;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\PseudoSiteFinder;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\HttpUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Frontend\Page\PageRepository;
use function array_filter;
use function array_shift;
use function count;
use function explode;
use function implode;
use function krsort;
use function reset;

class SiteResolver implements MiddlewareInterface
{
    /**
     * @var SiteFinder
     */
    protected $siteFinder;

    /**
     * @var PseudoSiteFinder
     */
    protected $pseudoSiteFinder;

    public function __construct(SiteFinder $siteFinder = null)
    {
        $this->siteFinder = $siteFinder ?? GeneralUtility::makeInstance(SiteFinder::class);
        $this->pseudoSiteFinder = GeneralUtility::makeInstance(PseudoSiteFinder::class);
    }

    /**
     * Resolve the site/language information by checking the page ID or the URL.
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     *
     * @return ResponseInterface
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var SiteRouteResult $routeResult */
        $routeResult = $this->matchRequest($request);
        $request = $request->withAttribute('site', $routeResult->getSite());
        $request = $request->withAttribute('language', $routeResult->getLanguage());
        $request = $request->withAttribute('routing', $routeResult);

        // At this point, we later get further route modifiers
        // for bw-compat we update $GLOBALS[TYPO3_REQUEST] to be used later in TSFE.
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $handler->handle($request);
    }

    /**
     * First, it is checked, if a "id" GET/POST parameter is found.
     * If it is, we check for a valid site mounted there.
     *
     * If it isn't the quest continues by validating the whole request URL and validating against
     * all available site records (and their language prefixes).
     *
     * If none is found, the "legacy" handling is checked for - checking for all pseudo-sites with
     * a sys_domain record, and match against them.
     *
     * @param ServerRequestInterface $request
     *
     * @return RouteResultInterface
     */
    public function matchRequest(ServerRequestInterface $request): RouteResultInterface
    {
        $site = null;
        $language = null;
        $defaultLanguage = null;

        $pageId = $request->getQueryParams()['id'] ?? $request->getParsedBody()['id'] ?? 0;

        if (!empty($pageId) && !MathUtility::canBeInterpretedAsInteger($pageId)) {
            $pageId = (int)GeneralUtility::makeInstance(PageRepository::class)->getPageIdFromAlias($pageId);
        }
        // First, check if we have a _GET/_POST parameter for "id", then a site information can be resolved based.
        if ($pageId > 0) {
            // Loop over the whole rootline without permissions to get the actual site information
            try {
                $site = $this->siteFinder->getSiteByPageId((int)$pageId);
                // If a "L" parameter is given, we take that one into account.
                $languageId = $request->getQueryParams()['L'] ?? $request->getParsedBody()['L'] ?? null;
                if ($languageId !== null) {
                    $language = $site->getLanguageById((int)$languageId);
                } else {
                    // Use this later below
                    $defaultLanguage = $site->getDefaultLanguage();
                }
            } catch (SiteNotFoundException $e) {
                // No site found by the given page
            } catch (\InvalidArgumentException $e) {
                // The language fetched by getLanguageById() was not available, now the PSR-15 middleware
                // redirects to the default page.
            }
        }

        // No language found at this point means that the URL was not used with a valid "?id=1&L=2" parameter
        // which resulted in a site / language combination that was found. Now, the matching is done
        // on the incoming URL.
        if (!($language instanceof SiteLanguage)) {
            // FIXME: START
            $filename = '';
            $path = $request->getUri()->getPath();
            $pathinfo = pathinfo($path);
            if(isset($pathinfo['extension'])) {
                $filename = '/' . $pathinfo['basename'];
            }
            $path = preg_replace('#(((?<=.)/)?)([^/]+?\.[^/]+?$)|((?<=[^/])/$)#', '', $path); // allow / or filename after slug
            if (!empty($path)) {
                $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
                $queryBuilder->select('*')
                             ->from('pages')
                             ->where($queryBuilder->expr()->eq('slug', $queryBuilder->createNamedParameter($path)));
                $pagesStatement = $queryBuilder->execute();
                $sites = [];
                while ($row = $pagesStatement->fetch()) {
                    $site = $this->siteFinder->getSiteByPageId($row['uid']);
                    $sites[$site->getIdentifier()] = $site;
                }
                $collection = $this->getRouteCollectionForSites($sites);
                if (!empty($collection)) {
                    $context = new RequestContext(
                        '',
                        $request->getMethod(),
                        HttpUtility::idn_to_ascii($request->getUri()->getHost()),
                        $request->getUri()->getScheme(),
                        // Ports are only necessary for URL generation in Symfony which is not used by TYPO3
                        80,
                        443,
                        $request->getUri()->getPath()
                    );
                    $matcher = new UrlMatcher($collection, $context);
                    try {
                        $result = $matcher->match($path);
                        return new SiteRouteResult(
                            $request->getUri(),
                            $result['site'],
                            // if no language is found, this usually results due to "/" called instead of "/fr/"
                            // but it could also be the reason that "/index.php?id=23" was called, so the default
                            // language is used as a fallback here then.
                            $result['language'] ?? $defaultLanguage,
                            $result['tail'] . $filename
                        );
                    } catch (NoConfigurationException | ResourceNotFoundException $e) {
                        // No site+language combination found so far
                    }
                }
            }
            // FIXME: END

            $collection = $this->getRouteCollectionForAllSites();
            $context = new RequestContext(
                '',
                $request->getMethod(),
                HttpUtility::idn_to_ascii($request->getUri()->getHost()),
                $request->getUri()->getScheme(),
                // Ports are only necessary for URL generation in Symfony which is not used by TYPO3
                80,
                443,
                $request->getUri()->getPath()
            );
            $matcher = new UrlMatcher($collection, $context);
            try {
                $result = $matcher->match($request->getUri()->getPath());
                return new SiteRouteResult(
                    $request->getUri(),
                    $result['site'],
                    // if no language is found, this usually results due to "/" called instead of "/fr/"
                    // but it could also be the reason that "/index.php?id=23" was called, so the default
                    // language is used as a fallback here then.
                    $result['language'] ?? $defaultLanguage,
                    $result['tail']
                );
            } catch (NoConfigurationException | ResourceNotFoundException $e) {
                // No site+language combination found so far
            }
            // At this point we discard a possible found site via ?id=123
            // Because ?id=123 _can_ only work if the actual domain/site base works
            // so www.domain-without-site-configuration/index.php?id=123 (where 123 is a page referring
            // to a page within a site configuration will never be resolved here) properly
            $site = null;
        }

        // Check against any sys_domain records
        $collection = $this->getRouteCollectionForVisibleSysDomains();
        $context = new RequestContext('/', $request->getMethod(), $request->getUri()->getHost());
        $matcher = new UrlMatcher($collection, $context);
        if ((bool)$GLOBALS['TYPO3_CONF_VARS']['SYS']['recursiveDomainSearch']) {
            $host = explode('.', $request->getUri()->getHost());
            while (count($host)) {
                $context->setHost(implode('.', $host));
                try {
                    $result = $matcher->match($request->getUri()->getPath());
                    return new SiteRouteResult(
                        $request->getUri(),
                        $result['site'],
                        $result['language'],
                        $result['tail']
                    );
                } catch (NoConfigurationException | ResourceNotFoundException $e) {
                    array_shift($host);
                }
            }
        } else {
            try {
                $result = $matcher->match($request->getUri()->getPath());
                return new SiteRouteResult($request->getUri(), $result['site'], $result['language'], $result['tail']);
            } catch (NoConfigurationException | ResourceNotFoundException $e) {
                // No domain record found
            }
        }
        // No domain record found, try resolving "pseudo-site" again
        if ($site == null) {
            try {
                // use the matching "pseudo-site" for $pageId
                $site = $this->pseudoSiteFinder->getSiteByPageId((int)$pageId);
            } catch (SiteNotFoundException $exception) {
                // use the first "pseudo-site" found
                $allPseudoSites = $this->pseudoSiteFinder->findAll();
                $site = reset($allPseudoSites);
            }
        }
        return new SiteRouteResult($request->getUri(), $site, $language);
    }

    /**
     * If a given page ID is handed in, a Site/PseudoSite/NullSite is returned.
     *
     * @param int $pageId uid of a page in default language
     * @param array|null $rootLine an alternative root line, if already at and.
     *
     * @return SiteInterface
     * @throws SiteNotFoundException
     */
    public function matchByPageId(int $pageId, array $rootLine = null): SiteInterface
    {
        try {
            return $this->siteFinder->getSiteByPageId($pageId, $rootLine);
        } catch (SiteNotFoundException $e) {
            // Check for a pseudo / null site
            return $this->pseudoSiteFinder->getSiteByPageId($pageId, $rootLine);
        }
    }

    /**
     * Returns a Symfony RouteCollection containing all routes to all sites.
     *
     * @return RouteCollection
     */
    protected function getRouteCollectionForAllSites(): RouteCollection
    {
        $groupedRoutes = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            // Add the site as entrypoint
            $uri = $site->getBase();
            $route = new Route(
                ($uri->getPath() ?: '/') . '{tail}',
                ['site' => $site, 'language' => null, 'tail' => ''],
                array_filter(['tail' => '.*', 'port' => (string)$uri->getPort()]),
                ['utf8' => true],
                $uri->getHost() ?: '',
                $uri->getScheme()
            );
            $identifier = 'site_' . $site->getIdentifier();
            $groupedRoutes[($uri->getScheme() ?: '-') . ($uri->getHost() ?: '-')][$uri->getPath() ?:
                '/'][$identifier] = $route;
            // Add all languages
            foreach ($site->getAllLanguages() as $siteLanguage) {
                $uri = $siteLanguage->getBase();
                $route = new Route(
                    ($uri->getPath() ?: '/') . '{tail}',
                    ['site' => $site, 'language' => $siteLanguage, 'tail' => ''],
                    array_filter(['tail' => '.*', 'port' => (string)$uri->getPort()]),
                    ['utf8' => true],
                    HttpUtility::idn_to_ascii($uri->getHost()) ?: '',
                    $uri->getScheme()
                );
                $identifier = 'site_' . $site->getIdentifier() . '_' . $siteLanguage->getLanguageId();
                $groupedRoutes[($uri->getScheme() ?: '-') . ($uri->getHost() ?: '-')][$uri->getPath() ?:
                    '/'][$identifier] = $route;
            }
        }
        return $this->createRouteCollectionFromGroupedRoutes($groupedRoutes);
    }

    /**
     * Returns a Symfony RouteCollection containing all routes to all sites.
     *
     * @param Site[] $sites
     *
     * @return RouteCollection
     */
    protected function getRouteCollectionForSites(array $sites): RouteCollection
    {
        $groupedRoutes = [];
        foreach ($sites as $site) {
            // Add the site as entrypoint
            $uri = $site->getBase();
            $route = new Route(
                ($uri->getPath() ?: '/') . '{tail}',
                ['site' => $site, 'language' => null, 'tail' => ''],
                array_filter(['tail' => '.*', 'port' => (string)$uri->getPort()]),
                ['utf8' => true],
                $uri->getHost() ?: '',
                $uri->getScheme()
            );
            $identifier = 'site_' . $site->getIdentifier();
            $groupedRoutes[($uri->getScheme() ?: '-') . ($uri->getHost() ?: '-')][$uri->getPath() ?:
                '/'][$identifier] = $route;
            // Add all languages
            foreach ($site->getAllLanguages() as $siteLanguage) {
                $uri = $siteLanguage->getBase();
                $route = new Route(
                    ($uri->getPath() ?: '/') . '{tail}',
                    ['site' => $site, 'language' => $siteLanguage, 'tail' => ''],
                    array_filter(['tail' => '.*', 'port' => (string)$uri->getPort()]),
                    ['utf8' => true],
                    HttpUtility::idn_to_ascii($uri->getHost()) ?: '',
                    $uri->getScheme()
                );
                $identifier = 'site_' . $site->getIdentifier() . '_' . $siteLanguage->getLanguageId();
                $groupedRoutes[($uri->getScheme() ?: '-') . ($uri->getHost() ?: '-')][$uri->getPath() ?:
                    '/'][$identifier] = $route;
            }
        }
        return $this->createRouteCollectionFromGroupedRoutes($groupedRoutes);
    }

    /**
     * Return the page ID (pid) of a sys_domain record, based on a request object, does the infamous
     * "recursive domain search", to also detect if the domain is like "abc.def.example.com" even if the
     * sys_domain entry is "example.com".
     *
     * @return RouteCollection
     */
    protected function getRouteCollectionForVisibleSysDomains(): RouteCollection
    {
        $sites = $this->pseudoSiteFinder->findAll();
        $groupedRoutes = [];
        foreach ($sites as $site) {
            if (!$site instanceof PseudoSite) {
                continue;
            }
            foreach ($site->getEntryPoints() as $uri) {
                // Site has no sys_domain record, it is not valid for a routing entrypoint, but only available
                // via "id" GET parameter which is handled separately
                if (!$uri->getHost()) {
                    continue;
                }
                $route = new Route(
                    ($uri->getPath() ?: '/') . '{tail}',
                    ['site' => $site, 'language' => null, 'tail' => ''],
                    array_filter(['tail' => '.*', 'port' => (string)$uri->getPort()]),
                    ['utf8' => true],
                    $uri->getHost(),
                    $uri->getScheme()
                );
                $identifier = 'site_' . $site->getIdentifier() . '_' . (string)$uri;
                $groupedRoutes[($uri->getScheme() ?: '-') . ($uri->getHost() ?: '-')][$uri->getPath() ?:
                    '/'][$identifier] = $route;
            }
        }
        return $this->createRouteCollectionFromGroupedRoutes($groupedRoutes);
    }

    /**
     * As the {tail} parameter is greedy, it needs to be ensured that the one with the
     * most specific part matches first.
     *
     * @param array $groupedRoutes
     *
     * @return RouteCollection
     */
    protected function createRouteCollectionFromGroupedRoutes(array $groupedRoutes): RouteCollection
    {
        $collection = new RouteCollection();
        foreach ($groupedRoutes as $groupedRoutesPerHost) {
            krsort($groupedRoutesPerHost);
            foreach ($groupedRoutesPerHost as $groupedRoutesPerPath) {
                krsort($groupedRoutesPerPath);
                foreach ($groupedRoutesPerPath as $identifier => $route) {
                    $collection->add($identifier, $route);
                }
            }
        }
        return $collection;
    }
}
