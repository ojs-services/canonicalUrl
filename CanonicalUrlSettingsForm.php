<?php

/**
 * @file plugins/generic/canonicalUrl/CanonicalUrlSettingsForm.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class CanonicalUrlSettingsForm
 *
 * @brief Settings per journal (Journal Manager / Site Administrator; the plugin grid checks
 *   the role, the form requires POST and a CSRF token). Saving clears the journal's sitemap cache.
 *   At site level (no journal, contextId 0) it only shows status information; nothing is saved.
 */

namespace APP\plugins\generic\canonicalUrl;

use APP\core\Application;
use APP\template\TemplateManager;
use PKP\config\Config;
use PKP\core\PKPApplication;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;

class CanonicalUrlSettingsForm extends Form
{
    /** Limits of the cache lifetime (hours) */
    public const CACHE_HOURS_MIN = 1;
    public const CACHE_HOURS_MAX = 720;

    public function __construct(
        private CanonicalUrlPlugin $plugin,
        private int $contextId
    ) {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
        $this->addCheck(new FormValidatorCustom(
            $this,
            'sitemapCacheHours',
            'required',
            'plugins.generic.canonicalUrl.settings.sitemapCacheHours.invalid',
            fn ($v) => ctype_digit(trim((string) $v)) && (int) $v >= self::CACHE_HOURS_MIN && (int) $v <= self::CACHE_HOURS_MAX
        ));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    public function initData()
    {
        foreach (array_keys(CanonicalUrlPlugin::SETTINGS) as $name) {
            $this->setData($name, $this->plugin->opt($this->contextId, $name));
        }
    }

    public function readInputData()
    {
        $this->readUserVars(array_keys(CanonicalUrlPlugin::SETTINGS));
    }

    public function fetch($request, $template = null, $display = false)
    {
        $context = $request->getContext();
        // This is a component (modal) request: build page addresses with the page router.
        $path = $context ? $context->getPath() : 'index';
        $pageUrl = fn (?string $page = null) => $request->getDispatcher()->url($request, PKPApplication::ROUTE_PAGE, $path, $page);
        // OJS builds addresses from the host name the request came in with (or from base_url[<journal>] when set);
        // the canonical address carries that name. A different name in the configuration means two names are in use.
        $canonicalHost = strtolower((string) parse_url($pageUrl(), PHP_URL_HOST));
        $requestHost = strtolower((string) preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')));
        $configUrl = Config::getVar('general', 'base_url[' . $path . ']') ?: Config::getVar('general', 'base_url');
        $configHost = strtolower((string) parse_url((string) $configUrl, PHP_URL_HOST));
        [$cacheFiles, $cacheCreated, $cacheUrls] = $this->plugin->sitemapCacheStatus($this->contextId);
        $foreign = $this->plugin->foreignCanonicalSeen($this->contextId);

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'cuRouter' => PKPApplication::ROUTE_COMPONENT,
            'canonicalHost' => $canonicalHost,
            'requestHost' => $requestHost,
            'configHost' => $configHost,
            'hostMismatch' => $configHost !== '' && $canonicalHost !== $configHost,
            'sitemapUrl' => $pageUrl('sitemap'),
            'cacheFiles' => $cacheFiles,
            'cacheCreated' => $cacheCreated ? date('Y-m-d H:i', $cacheCreated) : '',
            'cacheUrls' => $cacheUrls,
            'cacheHoursMin' => self::CACHE_HOURS_MIN,
            'cacheHoursMax' => self::CACHE_HOURS_MAX,
            'siteLevel' => !$context,
            // Status notices, so that nobody has to look on the server
            'cacheUnwritable' => $context && $this->plugin->opt($this->contextId, 'sitemapCache') && !$this->plugin->cacheWritable(),
            'cacheDirShown' => 'cache/canonicalUrl',
            'foreignSeen' => (bool) $foreign,
            'foreignDate' => $foreign ? date('Y-m-d', $foreign['t']) : '',
            'foreignPage' => $foreign ? $foreign['page'] : '',
            'foreignHref' => $foreign ? $foreign['href'] : '',
        ]);
        return parent::fetch($request, $template, $display);
    }

    public function execute(...$functionArgs)
    {
        foreach (CanonicalUrlPlugin::SETTINGS as $name => [$type, $default]) {
            $value = $this->getData($name);
            if ($type === 'bool') {
                $this->plugin->updateSetting($this->contextId, $name, (bool) $value, 'bool');
            } else {
                $this->plugin->updateSetting($this->contextId, $name, (int) $value, 'int');
            }
        }
        $this->plugin->forgetOpts($this->contextId);
        $this->plugin->purgeSitemapCache($this->contextId);
        // The other-canonical notice is cleared; if the link is still there, the next page request records it again.
        $this->plugin->forgetForeignCanonical($this->contextId);
        parent::execute(...$functionArgs);
    }
}
