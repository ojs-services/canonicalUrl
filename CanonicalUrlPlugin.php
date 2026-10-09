<?php

/**
 * @file plugins/generic/canonicalUrl/CanonicalUrlPlugin.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class CanonicalUrlPlugin
 *
 * @brief Adds one fixed <link rel="canonical"> to every reader-facing page, so that
 *   alternate addresses for the same content (galley URLs, with or without index.php,
 *   tracking parameters, numeric id or custom URL path) are not treated as duplicates
 *   by search engines. Thin pages get noindex instead. The journal sitemap is filtered,
 *   dated and cached. Everything can be switched off per journal on the settings page.
 *
 *   The host name is not changed: OJS builds its addresses from the name the request
 *   came in with; www / non-www must be redirected at the web server (the settings
 *   page warns when the two names differ).
 *
 *   Uses hooks only; no core or theme file is modified.
 *
 *   The same code runs on OJS 3.4 and 3.5 (capabilities are detected, not version numbers).
 *   On OJS 3.5, where the language code is part of the address, the hreflang alternates
 *   are rewritten to the canonical addresses.
 */

namespace APP\plugins\generic\canonicalUrl;

use APP\core\Application;
use APP\template\TemplateManager;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;
use PKP\core\JSONMessage;
use PKP\core\PKPPageRouter;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\submission\PKPSubmission;

class CanonicalUrlPlugin extends GenericPlugin
{
    /**
     * Settings and their defaults (everything on).
     * When no value is stored, the default applies.
     */
    public const SETTINGS = [
        // Canonical address
        'canonicalTag' => ['bool', true],
        'galleyToArticle' => ['bool', true],
        'linkHeader' => ['bool', true],
        // Sitemap
        'sitemapFilterGalleys' => ['bool', true],
        'sitemapFilterThin' => ['bool', true],
        'sitemapLastmod' => ['bool', true],
        'sitemapAnnouncements' => ['bool', true],
        'sitemapCustomPages' => ['bool', true],
        'sitemapCache' => ['bool', true],
        'sitemapCacheHours' => ['int', 24],
        // Excluded from indexing
        'noindexThin' => ['bool', true],
        'noindexCitations' => ['bool', true],
    ];

    /**
     * Thin pages: they get noindex instead of a canonical address.
     * page => null (every operation) | [operations]
     */
    public const NOINDEX_PAGES = [
        'search' => null,
        'login' => null,
        'user' => null,
        'notification' => null,
        'about' => ['aboutThisPublishingSystem'],
    ];

    /** Citation format output (dozens of duplicate addresses per article): X-Robots-Tag only. */
    public const NOINDEX_CITATION_PAGES = ['citationstylelanguage' => null];

    /** While the cache is being rebuilt, other requests get the stale copy for this long (seconds). */
    public const CACHE_LOCK_SECONDS = 300;

    /** At most this many cache files are kept per journal (twice the number of languages, if that is more). */
    public const CACHE_MAX_FILES = 8;

    /** Cache-Control header of a sitemap answered from the cache (the sitemap is public). */
    public const SITEMAP_CACHE_CONTROL = 'public, max-age=3600';

    /** When another source's canonical link is seen, the note is refreshed at most this often and shown for this long (seconds). */
    public const FOREIGN_NOTE_EVERY = 86400;
    public const FOREIGN_NOTE_SHOWN = 2592000;

    /** The HTTP Link header line sent in this request (null if none); once per request. */
    private ?string $_linkSent = null;

    /** The canonical link written in this request: ['tag' => tag|null, 'url' => …, 'contextId' => …, 'page' => …] */
    private ?array $_own = null;

    /** The sitemap to be written to the cache in this request: ['file' => …, 'fingerprint' => …] */
    private ?array $_sitemapPending = null;

    /** Settings cache for this request: contextId => [name => value] */
    private array $_opts = [];

    public function register($category, $path, $mainContextId = null)
    {
        if (parent::register($category, $path, $mainContextId)) {
            if ($this->getEnabled($mainContextId)) {
                // Earliest in the sequence: another plugin's LoadHandler callback may return true and stop
                // the chain (citationStyleLanguage does on OJS 3.5); this callback always returns false.
                Hook::add('LoadHandler', [$this, 'handleLoad'], Hook::SEQUENCE_CORE);
                Hook::add('TemplateManager::display', [$this, 'addCanonicalUrl']);
                Hook::add('SitemapHandler::createJournalSitemap', [$this, 'filterSitemap']);
                Hook::add('SitemapHandler::createJournalSitemap', [$this, 'storeSitemap'], Hook::SEQUENCE_LAST);
                // Invalidate the sitemap cache (a fingerprint and a lifetime apply as well)
                foreach ([
                    'Publication::publish', 'Publication::unpublish', 'Publication::edit', 'Publication::delete',
                    'Submission::delete', 'IssueGridHandler::publishIssue', 'IssueGridHandler::unpublishIssue', 'Issue::edit',
                ] as $hook) {
                    try {
                        Hook::add($hook, [$this, 'purgeOnChange']);
                    } catch (\Throwable $e) {
                        error_log('canonicalUrl: ' . $hook . ': ' . $e->getMessage());
                    }
                }
            }
            return true;
        }
        return false;
    }

    //
    // Settings
    //

    /** Effective value of a setting (the default when nothing is stored, or when there is no journal). */
    public function opt(?int $contextId, string $name)
    {
        [$type, $default] = self::SETTINGS[$name];
        if (!$contextId) {
            return $default;
        }
        if (!array_key_exists($contextId, $this->_opts)) {
            $this->_opts[$contextId] = [];
        }
        if (!array_key_exists($name, $this->_opts[$contextId])) {
            $value = $this->getSetting($contextId, $name);
            if ($value === null || $value === '') {
                $value = $default;
            } elseif ($type === 'bool') {
                $value = (bool) $value;
            } elseif ($type === 'int') {
                $value = (int) $value;
            }
            $this->_opts[$contextId][$name] = $value;
        }
        return $this->_opts[$contextId][$name];
    }

    public function forgetOpts(int $contextId): void
    {
        unset($this->_opts[$contextId]);
    }

    private function _contextId($request): ?int
    {
        $context = $request->getContext();
        return $context ? (int) $context->getId() : null;
    }

    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }
        $router = $request->getRouter();
        array_unshift($actions, new LinkAction(
            'settings',
            new AjaxModal(
                $router->url($request, null, null, 'manage', null, ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        ));
        return $actions;
    }

    public function manage($args, $request)
    {
        $context = $request->getContext();
        if ($request->getUserVar('verb') === 'settings') {
            // Without a journal (site level) the form only shows information; settings are per journal.
            $form = new CanonicalUrlSettingsForm($this, $context ? (int) $context->getId() : 0);
            if ($context && $request->getUserVar('save')) {
                $form->readInputData();
                if ($form->validate()) {
                    $form->execute();
                    return new JSONMessage(true);
                }
            } else {
                $form->initData();
            }
            return new JSONMessage(true, $form->fetch($request));
        }
        return parent::manage($args, $request);
    }

    //
    // Start of a page request: noindex header and sitemap from the cache
    //

    /**
     * LoadHandler runs on every page request, before any template or redirect (e.g. user/setLocale):
     * X-Robots-Tag for thin pages; answer the sitemap at once when it is cached.
     */
    public function handleLoad($hookName, $args)
    {
        try {
            $request = Application::get()->getRequest();
            $router = $request->getRouter();
            if (!$router instanceof PKPPageRouter) {
                return false;
            }
            $page = (string) $router->getRequestedPage($request);
            $op = (string) $router->getRequestedOp($request);
            $contextId = $this->_contextId($request);

            if ($this->isNoindexPage($contextId, $page, $op, true) && !headers_sent()) {
                header('X-Robots-Tag: noindex, follow');
            }
            if ($page === 'sitemap' && $contextId) {
                $this->_serveCachedSitemap($request, $contextId);
            }
        } catch (\Throwable $e) {
            error_log('canonicalUrl load: ' . $e->getMessage());
        }
        return false;
    }

    /**
     * @param bool $headerOnly true: also count pages that get the header only (citation format output)
     */
    public function isNoindexPage(?int $contextId, string $page, string $op, bool $headerOnly = false): bool
    {
        $lists = [];
        if ($this->opt($contextId, 'noindexThin')) {
            $lists[] = self::NOINDEX_PAGES;
        }
        if ($headerOnly && $this->opt($contextId, 'noindexCitations')) {
            $lists[] = self::NOINDEX_CITATION_PAGES;
        }
        foreach ($lists as $list) {
            if (array_key_exists($page, $list)) {
                $ops = $list[$page];
                if ($ops === null || in_array($op ?: 'index', $ops, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    //
    // <head>: canonical / noindex / hreflang
    //

    /**
     * Adds the canonical address (or, on a thin page, noindex) to <head>.
     * addHeader() defaults to contexts=['frontend'], so the management pages are not touched.
     */
    public function addCanonicalUrl($hookName, $args)
    {
        try {
            $templateMgr = $args[0];
            $request = Application::get()->getRequest();
            $router = $request->getRouter();

            if (!$router instanceof PKPPageRouter) {
                return false;
            }

            $page = (string) $router->getRequestedPage($request);
            $op = (string) $router->getRequestedOp($request);
            $contextId = $this->_contextId($request);

            if ($this->isNoindexPage($contextId, $page, $op)) {
                $templateMgr->addHeader('canonicalUrlRobots', '<meta name="robots" content="noindex,follow"/>');
                if (!headers_sent()) {
                    header('X-Robots-Tag: noindex, follow');
                }
                return false;
            }

            $tag = $this->opt($contextId, 'canonicalTag');
            $link = $this->opt($contextId, 'linkHeader');
            if (!$tag && !$link) {
                return false;
            }

            // No canonical address on error pages (404, 403 …).
            if ($this->_responseStatus() !== 200) {
                return false;
            }

            $target = $this->_canonicalTarget($request, $router, $templateMgr, $page, $op, (bool) $this->opt($contextId, 'galleyToArticle'));
            if (!$target) {
                return false;
            }
            [$tPage, $tOp, $tArgs] = $target;
            // Home page (no page/operation): url() fills an empty page with the requested one
            // (/index/index); for the root address the context is passed explicitly.
            $tContext = null;
            if ($tPage === null && $tOp === null && $tArgs === null) {
                $context = $request->getContext();
                $tContext = $context ? $context->getPath() : 'index';
            }

            $url = $router->url($request, $tContext, $tPage, $tOp, $tArgs);
            if (!is_string($url) || $url === '') {
                return false;
            }

            $tagHtml = null;
            if ($tag) {
                $tagHtml = '<link rel="canonical" href="' . htmlspecialchars($url, ENT_QUOTES) . '"/>';
                $templateMgr->addHeader('canonicalUrl', $tagHtml);
            }

            // Article and galley viewer: the same target as an HTTP header as well
            // (seen at crawl time, without rendering). Download endpoints
            // (article/download) display no template, so they never get it.
            if ($link && $page === 'article' && $op === 'view' && $this->_linkSent === null && !headers_sent()) {
                $this->_linkSent = 'Link: <' . $this->_headerUrl($url) . '>; rel="canonical"';
                header($this->_linkSent, false);
            }

            if ($tag) {
                $this->_addHreflang($request, $router, $templateMgr, $tContext, $tPage, $tOp, $tArgs);
            }

            // Once the page is rendered: if another source wrote a canonical link too, ours is removed.
            if ($tagHtml !== null || $this->_linkSent !== null) {
                $this->_own = ['tag' => $tagHtml, 'url' => $url, 'contextId' => $contextId, 'page' => trim($page . '/' . $op, '/')];
                $templateMgr->registerFilter('output', [$this, 'dropDuplicateCanonical']);
            }
        } catch (\Throwable $e) {
            // The page must still load when the canonical link cannot be built.
            error_log('canonicalUrl plugin: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Canonical target: [page, op, args], or null (no canonical link).
     *   /article/view/135/120 (galley) -> /article/view/<best id of the article>
     *   /issue/view/12/...            -> /issue/view/<best id of the issue>
     * null when the article or issue was not resolved (no id, or it does not exist).
     */
    private function _canonicalTarget($request, $router, $templateMgr, string $page, string $op, bool $galleyToArticle): ?array
    {
        $reqArgs = $router->getRequestedArgs($request);

        if ($page === 'article' && $op === 'view') {
            $article = $templateMgr->getTemplateVars('article');
            if (!isset($reqArgs[0]) || $reqArgs[0] === '' || !is_object($article) || !method_exists($article, 'getBestId')) {
                return null;
            }
            $path = [(string) $article->getBestId()];
            $rest = array_map('strval', array_slice($reqArgs, 1));
            $versionPart = [];
            // Version address: /article/view/N/version/P[/galley].
            // On the landing page of an OUTDATED version OJS itself writes noindex and a canonical link to the
            // current version (ArticleHandler::view, addHeader('canonical')), so the plugin adds no second link.
            // When the current version is opened with /version/, the canonical address is the article itself.
            if (count($rest) >= 2 && $rest[0] === 'version' && ctype_digit($rest[1])) {
                $current = method_exists($article, 'getData') ? (int) $article->getData('currentPublicationId') : 0;
                $outdated = ((int) $rest[1] !== $current);
                if ($outdated) {
                    $versionPart = ['version', $rest[1]];
                }
                $rest = array_slice($rest, 2);
                if ($outdated && !$rest) {
                    return null;
                }
            }
            if (!$galleyToArticle && $rest) {
                // Setting off: a galley page gets its own address (with the best id, and the version if any) as canonical.
                $path = array_merge($path, $versionPart, $rest);
            }
            return ['article', 'view', $path];
        }

        if ($page === 'issue' && $op === 'view') {
            $issue = $templateMgr->getTemplateVars('issue');
            if (!isset($reqArgs[0]) || $reqArgs[0] === '' || !is_object($issue) || !method_exists($issue, 'getBestIssueId')) {
                return null;
            }
            return ['issue', 'view', [(string) $issue->getBestIssueId()]];
        }

        // 'index' is the default operation; in the address it would create a needless variant
        // such as /about/index. The canonical address stays plain.
        if ($op === 'index') {
            $op = '';
        }
        // Home page variants /index and /index/index: the canonical address is the root address.
        if ($page === 'index' && $op === '' && !$reqArgs) {
            $page = '';
        }
        return [$page ?: null, $op ?: null, $reqArgs ?: null];
    }

    //
    // One canonical link: the plugin withdraws when another source wrote one
    //

    /**
     * Smarty output filter. When the <head> of the rendered page contains a rel="canonical" other than
     * the plugin's own (theme template, another plugin, the journal's custom tags), the plugin's link is
     * removed; when the addresses differ, the Link header is withdrawn too. What was seen is stored, to be
     * shown on the settings page. On any error the output is returned unchanged.
     */
    public function dropDuplicateCanonical($output, $template = null)
    {
        try {
            $own = $this->_own;
            if (!$own || !is_string($output)) {
                return $output;
            }
            $end = stripos($output, '</head>');
            if ($end === false) {
                return $output;
            }
            $head = substr($output, 0, $end);
            if (!preg_match_all('#<link\b[^>]*\brel\s*=\s*["\']?canonical\b[^>]*>#i', $head, $m)) {
                return $output;
            }
            $others = $m[0];
            if ($own['tag'] !== null) {
                $i = array_search($own['tag'], $others, true);
                if ($i === false) {
                    return $output; // this output is not the page itself
                }
                unset($others[$i]);
            }
            $this->_own = null;
            if (!$others) {
                return $output;
            }

            $otherHref = null;
            foreach ($others as $other) {
                $href = preg_match('#\bhref\s*=\s*(["\'])(.*?)\1#i', $other, $h) ? html_entity_decode($h[2], ENT_QUOTES) : '';
                if ($href !== $own['url'] && $otherHref === null) {
                    $otherHref = $href;
                }
            }
            if ($own['tag'] !== null) {
                $pos = strpos($head, $own['tag']);
                $output = substr($output, 0, $pos) . substr($output, $pos + strlen($own['tag']));
            }
            if ($otherHref !== null) {
                // The addresses differ: do not leave two different canonical signals.
                $this->_dropLinkHeader();
            }
            if ($own['tag'] !== null) {
                $this->_noteForeignCanonical($own['contextId'], $own['page'], $otherHref ?? $own['url']);
            }
        } catch (\Throwable $e) {
            error_log('canonicalUrl dedupe: ' . $e->getMessage());
        }
        return $output;
    }

    /** Withdraws the Link header sent in this request; other Link headers are kept. */
    private function _dropLinkHeader(): void
    {
        if ($this->_linkSent === null || headers_sent()) {
            return;
        }
        $keep = [];
        foreach (headers_list() as $line) {
            if (stripos($line, 'Link:') === 0 && $line !== $this->_linkSent) {
                $keep[] = $line;
            }
        }
        header_remove('Link');
        foreach ($keep as $line) {
            header($line, false);
        }
        $this->_linkSent = null;
    }

    /** Notes the other source's canonical link in a journal setting (written at most once a day). */
    private function _noteForeignCanonical(?int $contextId, string $page, string $href): void
    {
        if (!$contextId) {
            return;
        }
        $prev = json_decode((string) $this->getSetting($contextId, 'foreignCanonical'), true);
        if (is_array($prev) && (time() - (int) ($prev['t'] ?? 0)) < self::FOREIGN_NOTE_EVERY) {
            return;
        }
        $this->updateSetting($contextId, 'foreignCanonical', json_encode([
            't' => time(),
            'page' => substr($page, 0, 60),
            'href' => substr($href, 0, 300),
        ], JSON_UNESCAPED_SLASHES), 'string');
    }

    /** For the settings page: another canonical link seen recently ['t', 'page', 'href'], or null. */
    public function foreignCanonicalSeen(int $contextId): ?array
    {
        if (!$contextId) {
            return null;
        }
        $note = json_decode((string) $this->getSetting($contextId, 'foreignCanonical'), true);
        if (!is_array($note) || (time() - (int) ($note['t'] ?? 0)) > self::FOREIGN_NOTE_SHOWN) {
            return null;
        }
        return ['t' => (int) $note['t'], 'page' => (string) ($note['page'] ?? ''), 'href' => (string) ($note['href'] ?? '')];
    }

    public function forgetForeignCanonical(int $contextId): void
    {
        if ($contextId && (string) $this->getSetting($contextId, 'foreignCanonical') !== '') {
            $this->updateSetting($contextId, 'foreignCanonical', '', 'string');
        }
    }

    /**
     * HTTP status of the response. OJS 3.5 also keeps the status in the Laravel Response object
     * (display() sends its cookies with that status); both are checked.
     */
    private function _responseStatus(): int
    {
        $code = http_response_code();
        $code = is_int($code) ? $code : 200;
        if ($code === 200 && function_exists('app')) {
            try {
                if (app()->bound(\Illuminate\Http\Response::class)) {
                    $status = (int) app()->get(\Illuminate\Http\Response::class)->getStatusCode();
                    if ($status) {
                        $code = $status;
                    }
                }
            } catch (\Throwable $e) {
                // without that binding, the PHP status applies
            }
        }
        return $code;
    }

    /** Address for a header line: no line breaks and no angle brackets. */
    private function _headerUrl(string $url): string
    {
        return str_replace(["\r", "\n", '<', '>', ' '], ['', '', '%3C', '%3E', '%20'], $url);
    }

    /**
     * OJS 3.5: the address carries the language code, and OJS writes hreflang for the language
     * versions of the requested address (e.g. a galley). This rewrites the same header names
     * (language-<locale>, language-xdefault) to the canonical target; x-default is the journal's
     * primary language. Does nothing where the address has no language code (OJS 3.4).
     */
    private function _addHreflang($request, $router, $templateMgr, $newContext, $page, $op, $args): void
    {
        if (!$this->_urlHasLocale($router)) {
            return;
        }
        $context = $request->getContext();
        $locales = $context ? $context->getSupportedLocales() : $request->getSite()->getSupportedLocales();
        if (!is_array($locales) || count($locales) < 2) {
            return;
        }
        $primary = $context ? $context->getPrimaryLocale() : $request->getSite()->getPrimaryLocale();
        if (!in_array($primary, $locales, true)) {
            $primary = reset($locales);
        }
        $url = fn (string $locale): string => $router->url($request, $newContext, $page, $op, $args, null, null, false, $locale);
        foreach ($locales as $locale) {
            $templateMgr->addHeader(
                'language-' . $locale,
                '<link rel="alternate" hreflang="' . htmlspecialchars($this->_bcp47($locale), ENT_QUOTES) . '" href="' . htmlspecialchars($url($locale), ENT_QUOTES) . '"/>'
            );
        }
        $templateMgr->addHeader(
            'language-xdefault',
            '<link rel="alternate" hreflang="x-default" href="' . htmlspecialchars($url($primary), ENT_QUOTES) . '"/>'
        );
    }

    /** Does PKPPageRouter::url() take a locale parameter (OJS 3.5 and later)? */
    private function _urlHasLocale($router): bool
    {
        try {
            $method = new \ReflectionMethod($router, 'url');
            foreach ($method->getParameters() as $param) {
                if ($param->getName() === 'urlLocaleForPage') {
                    return true;
                }
            }
        } catch (\Throwable $e) {
        }
        return false;
    }

    private function _bcp47(string $locale): string
    {
        if (class_exists(\PKP\i18n\LocaleConversion::class) && method_exists(\PKP\i18n\LocaleConversion::class, 'toBcp47')) {
            return \PKP\i18n\LocaleConversion::toBcp47($locale);
        }
        return str_replace('_', '-', $locale);
    }

    //
    // Sitemap: filter
    //

    /**
     * Filters the journal sitemap and adds lastmod to articles and issues.
     * On any error the sitemap is left untouched (the one OJS built is sent as it is).
     */
    public function filterSitemap($hookName, $args)
    {
        try {
            $doc = $args[0];
            $request = Application::get()->getRequest();
            $context = $request->getContext();
            if (!$doc instanceof \DOMDocument || !$context) {
                return false;
            }
            $contextId = (int) $context->getId();
            $opts = [];
            foreach (['sitemapFilterGalleys', 'sitemapFilterThin', 'sitemapLastmod', 'sitemapAnnouncements', 'sitemapCustomPages'] as $name) {
                $opts[$name] = (bool) $this->opt($contextId, $name);
            }
            $this->decorateSitemap($doc, $this->_sitemapBase($request, $context), $contextId, $opts);
        } catch (\Throwable $e) {
            error_log('canonicalUrl sitemap: ' . $e->getMessage());
        }

        return false;
    }

    private function _sitemapBase($request, $context): string
    {
        return rtrim($request->url($context->getPath()), '/');
    }

    /**
     * The actual sitemap work (separate, so it can be measured): filtering, lastmod, and rewriting
     * issue addresses to the best id. Works on a clone; the document changes only when all went well.
     * $opts: sitemapFilterGalleys, sitemapFilterThin, sitemapLastmod,
     *        sitemapAnnouncements, sitemapCustomPages (not given = on)
     */
    public function decorateSitemap(\DOMDocument $doc, string $base, int $contextId, array $opts = []): void
    {
        $on = fn (string $name): bool => $opts[$name] ?? true;
        $dates = $this->_loadDates($contextId);
        $customPaths = $on('sitemapCustomPages') ? [] : $this->_customPagePaths($contextId);

        $work = $doc->cloneNode(true);
        $urls = [];
        foreach ($work->getElementsByTagName('url') as $u) {
            $urls[] = $u;
        }

        foreach ($urls as $u) {
            $locEl = $u->getElementsByTagName('loc')->item(0);
            if (!$locEl) {
                continue;
            }
            $loc = trim($locEl->textContent);
            if (strpos($loc, $base) !== 0) {
                continue;
            }
            $path = substr($loc, strlen($base));

            // Duplicate or non-content addresses; announcements and custom pages depending on the settings
            if (($on('sitemapFilterGalleys') && preg_match('#^/article/view/[^/]+/[^/]+/?$#', $path))
                || ($on('sitemapFilterThin') && preg_match('#^/(login|user/register|search|issue/current)/?$#', $path))
                || (!$on('sitemapAnnouncements') && preg_match('#^/announcement(/.*)?$#', $path))
                || ($customPaths && isset($customPaths[rawurldecode(trim($path, '/'))]))) {
                $u->parentNode->removeChild($u);
                continue;
            }

            $date = null;
            if (preg_match('#^/article/view/([^/]+)/?$#', $path, $m)) {
                $date = $dates['article'][rawurldecode($m[1])] ?? null;
            } elseif (preg_match('#^/issue/view/([^/]+)/?$#', $path, $m)) {
                $issue = $dates['issue'][rawurldecode($m[1])] ?? null;
                if ($issue) {
                    $date = $issue['date'];
                    // OJS always lists an issue by its numeric id, while the canonical address uses
                    // the URL path: the sitemap gets the canonical address as well.
                    if ($issue['path'] !== '' && $issue['path'] !== rawurldecode($m[1])) {
                        $locEl->textContent = $base . '/issue/view/' . rawurlencode($issue['path']);
                    }
                }
            }
            if ($date && $on('sitemapLastmod') && !$u->getElementsByTagName('lastmod')->length) {
                $u->appendChild($work->createElement('lastmod', $date));
            }
        }

        $root = $doc->documentElement;
        $newRoot = $doc->importNode($work->documentElement, true);
        $doc->replaceChild($newRoot, $root);
    }

    /**
     * Dates of published articles and issues, in two queries.
     * Keyed by numeric id and by URL path. Empty on error (filtering still happens).
     */
    private function _loadDates(int $contextId): array
    {
        $out = ['article' => [], 'issue' => []];
        try {
            $rows = DB::select(
                'SELECT s.submission_id AS id, p.url_path AS path, p.last_modified AS lm, p.date_published AS dp
                   FROM submissions s
                   JOIN publications p ON p.publication_id = s.current_publication_id
                  WHERE s.context_id = ? AND s.status = ?',
                [$contextId, PKPSubmission::STATUS_PUBLISHED]
            );
            foreach ($rows as $r) {
                $date = $this->_latestDate([$r->lm, $r->dp]);
                if (!$date) {
                    continue;
                }
                $out['article'][(string) $r->id] = $date;
                if ((string) $r->path !== '') {
                    $out['article'][(string) $r->path] = $date;
                }
            }

            $rows = DB::select(
                'SELECT issue_id AS id, url_path AS path, last_modified AS lm, date_published AS dp
                   FROM issues
                  WHERE journal_id = ? AND published = 1',
                [$contextId]
            );
            foreach ($rows as $r) {
                $info = ['date' => $this->_latestDate([$r->lm, $r->dp]), 'path' => (string) $r->path];
                $out['issue'][(string) $r->id] = $info;
                if ($info['path'] !== '') {
                    $out['issue'][$info['path']] = $info;
                }
            }
        } catch (\Throwable $e) {
            error_log('canonicalUrl sitemap dates: ' . $e->getMessage());
        }
        return $out;
    }

    /** Paths of the journal's custom pages (NMI_TYPE_CUSTOM menu items): [path => true] */
    private function _customPagePaths(int $contextId): array
    {
        $out = [];
        try {
            $rows = DB::select(
                "SELECT path FROM navigation_menu_items WHERE context_id = ? AND type = 'NMI_TYPE_CUSTOM'",
                [$contextId]
            );
            foreach ($rows as $r) {
                $p = trim((string) $r->path, '/');
                if ($p !== '') {
                    $out[$p] = true;
                }
            }
        } catch (\Throwable $e) {
            error_log('canonicalUrl sitemap pages: ' . $e->getMessage());
        }
        return $out;
    }

    /** The latest of the dates (never later than today), as YYYY-MM-DD. */
    private function _latestDate(array $values): ?string
    {
        $ts = 0;
        foreach ($values as $v) {
            $t = $v ? strtotime((string) $v) : false;
            if ($t && $t > $ts) {
                $ts = $t;
            }
        }
        if (!$ts) {
            return null;
        }
        return date('Y-m-d', min($ts, time()));
    }

    //
    // Sitemap: cache
    //
    // File: cache/canonicalUrl/sitemap-<journal>-<md5(base address)>.xml (plus .json metadata
    // and .lock). The base address is part of the key: on OJS 3.5 the language code is in the
    // address, so each language has its own file. Valid while: lifetime, and a fingerprint (number of articles and issues,
    // latest modification and publication dates, current publication ids, settings, version);
    // the files are also deleted by the hooks that fire on changes.
    //

    public function cacheDir(): string
    {
        return Core::getBaseDir() . '/cache/canonicalUrl';
    }

    /** Does the cache folder exist (or can it be created), and is it writable? */
    public function cacheWritable(): bool
    {
        $dir = $this->cacheDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return is_dir($dir) && is_writable($dir);
    }

    private function _cacheFile(int $contextId, string $base): string
    {
        return $this->cacheDir() . '/sitemap-' . $contextId . '-' . md5($base) . '.xml';
    }

    /**
     * Limits the number of cache files per journal. The file name depends on the base address of the
     * request (host name; on OJS 3.5 also language). So that made-up host names cannot create files without
     * limit on a server that does not check the Host header, the least recently used files are removed
     * before a new one is written.
     */
    private function _trimCache(int $contextId, string $keepFile, int $limit): void
    {
        $files = [];
        foreach (glob($this->cacheDir() . '/sitemap-' . $contextId . '-*.xml') ?: [] as $f) {
            if ($f !== $keepFile) {
                $files[$f] = (int) @filemtime($f);
            }
        }
        asort($files);
        while (count($files) >= $limit) {
            $f = key($files);
            array_shift($files);
            @unlink($f);
            @unlink($f . '.json');
            @unlink($f . '.lock');
        }
        // Locks whose sitemap was never written (unfinished or rejected requests): expired ones are removed,
        // the rest count towards the limit, so requests that build no sitemap cannot pile up files either.
        $locks = [];
        foreach (glob($this->cacheDir() . '/sitemap-' . $contextId . '-*.xml.lock') ?: [] as $l) {
            if ($l === $keepFile . '.lock' || is_file(substr($l, 0, -5))) {
                continue;
            }
            $age = time() - (int) @filemtime($l);
            if ($age >= self::CACHE_LOCK_SECONDS) {
                @unlink($l);
            } else {
                $locks[$l] = -$age;
            }
        }
        asort($locks);
        while ($locks && count($files) + count($locks) >= $limit) {
            $l = key($locks);
            array_shift($locks);
            @unlink($l);
        }
    }

    /** Sends the sitemap from the cache when it is valid and ends the request; otherwise plans the build. */
    private function _serveCachedSitemap($request, int $contextId): void
    {
        if (!$this->opt($contextId, 'sitemapCache')) {
            return;
        }
        $context = $request->getContext();
        $file = $this->_cacheFile($contextId, $this->_sitemapBase($request, $context));
        $fingerprint = $this->sitemapFingerprint($contextId);
        $ttl = max(1, (int) $this->opt($contextId, 'sitemapCacheHours')) * 3600;

        $meta = is_file($file . '.json') ? json_decode((string) @file_get_contents($file . '.json'), true) : null;
        $valid = is_array($meta) && is_file($file)
            && ($meta['fingerprint'] ?? null) === $fingerprint
            && (time() - (int) ($meta['created'] ?? 0)) < $ttl;

        if ($valid && $this->_sendSitemapFile($file, 'hit')) {
            @touch($file); // most recently used: beyond the limit, the least recently used file is removed
            exit;
        }

        // Another request is building it right now: send the stale copy (one build at a time).
        $lock = $file . '.lock';
        if (is_file($file) && is_file($lock) && (time() - (int) @filemtime($lock)) < self::CACHE_LOCK_SECONDS) {
            if ($this->_sendSitemapFile($file, 'stale')) {
                exit;
            }
        }

        // This request builds it: OJS creates the sitemap, storeSitemap() writes it.
        // When the folder is not writable, OJS builds it on every request; the settings page shows a warning.
        if ($this->cacheWritable()) {
            $locales = $context->getSupportedLocales();
            $this->_trimCache($contextId, $file, max(self::CACHE_MAX_FILES, 2 * (is_array($locales) ? count($locales) : 1)));
            @touch($lock);
            $this->_sitemapPending = ['file' => $file, 'fingerprint' => $fingerprint];
            if (!headers_sent()) {
                header('X-CanonicalUrl-Sitemap: miss');
            }
        }
    }

    /**
     * Sends the file when it looks intact. The sitemap is public, so intermediate caches may store it
     * (OJS itself sends "private"); no session cookie is set with this answer.
     */
    private function _sendSitemapFile(string $file, string $state): bool
    {
        $xml = @file_get_contents($file);
        if (!is_string($xml) || strncmp($xml, '<?xml', 5) !== 0 || !preg_match('#</urlset>\s*$#', $xml) || headers_sent()) {
            return false;
        }
        header('Content-Type: application/xml');
        header('Cache-Control: ' . self::SITEMAP_CACHE_CONTROL);
        header_remove('Set-Cookie');
        header('Content-Disposition: inline; filename=sitemap.xml');
        header('X-CanonicalUrl-Sitemap: ' . $state);
        echo $xml;
        return true;
    }

    /** Last in the sequence: after OJS and the other plugins are done, writes the sitemap to the cache. */
    public function storeSitemap($hookName, $args)
    {
        $pending = $this->_sitemapPending;
        $this->_sitemapPending = null;
        if (!$pending) {
            return false;
        }
        try {
            $doc = $args[0];
            if ($doc instanceof \DOMDocument) {
                $xml = $doc->saveXML();
                if (is_string($xml) && $xml !== '') {
                    $tmp = $pending['file'] . '.' . getmypid() . '.tmp';
                    if (@file_put_contents($tmp, $xml) !== false && @rename($tmp, $pending['file'])) {
                        @file_put_contents($pending['file'] . '.json', json_encode([
                            'fingerprint' => $pending['fingerprint'],
                            'created' => time(),
                            'urls' => $doc->getElementsByTagName('url')->length,
                        ]));
                    } else {
                        @unlink($tmp);
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('canonicalUrl sitemap cache: ' . $e->getMessage());
        }
        @unlink($pending['file'] . '.lock');
        return false;
    }

    /** Digest of the data the sitemap depends on; when it changes, the cache is invalid. */
    public function sitemapFingerprint(int $contextId): string
    {
        $parts = [$this->_versionString()];
        foreach (array_keys(self::SETTINGS) as $name) {
            $parts[] = $name . '=' . json_encode($this->opt($contextId, $name));
        }
        try {
            $a = DB::selectOne(
                'SELECT COUNT(*) AS c, MAX(p.last_modified) AS lm, MAX(p.date_published) AS dp, SUM(s.current_publication_id) AS ps
                   FROM submissions s
                   JOIN publications p ON p.publication_id = s.current_publication_id
                  WHERE s.context_id = ? AND s.status = ?',
                [$contextId, PKPSubmission::STATUS_PUBLISHED]
            );
            $i = DB::selectOne(
                'SELECT COUNT(*) AS c, MAX(last_modified) AS lm, MAX(date_published) AS dp, SUM(issue_id) AS ids
                   FROM issues WHERE journal_id = ? AND published = 1',
                [$contextId]
            );
            $parts[] = json_encode([(array) $a, (array) $i]);
        } catch (\Throwable $e) {
            // Without a fingerprint the cache is never valid (every request builds).
            $parts[] = 'err-' . microtime(true);
        }
        return md5(implode('|', $parts));
    }

    private function _versionString(): string
    {
        $version = $this->getCurrentVersion();
        return $version ? $version->getVersionString() : '';
    }

    /** Deletes the journal's cache when a publication or issue changes. */
    public function purgeOnChange($hookName, $args)
    {
        try {
            $contextId = $this->_contextId(Application::get()->getRequest());
            if ($contextId) {
                $this->purgeSitemapCache($contextId);
            }
        } catch (\Throwable $e) {
            error_log('canonicalUrl purge: ' . $e->getMessage());
        }
        return false;
    }

    public function purgeSitemapCache(int $contextId): int
    {
        $n = 0;
        foreach (glob($this->cacheDir() . '/sitemap-' . $contextId . '-*') ?: [] as $f) {
            if (@unlink($f)) {
                $n++;
            }
        }
        return $n;
    }

    /** For the settings page: [number of files, time of the newest build, number of URLs] */
    public function sitemapCacheStatus(int $contextId): array
    {
        $files = 0;
        $newest = 0;
        $urls = null;
        foreach (glob($this->cacheDir() . '/sitemap-' . $contextId . '-*.json') ?: [] as $f) {
            $meta = json_decode((string) @file_get_contents($f), true);
            if (is_array($meta) && isset($meta['created'])) {
                $files++;
                if ((int) $meta['created'] > $newest) {
                    $newest = (int) $meta['created'];
                    $urls = $meta['urls'] ?? null;
                }
            }
        }
        return [$files, $newest, $urls];
    }

    public function getDisplayName()
    {
        return __('plugins.generic.canonicalUrl.name');
    }

    public function getDescription()
    {
        return __('plugins.generic.canonicalUrl.description');
    }
}
