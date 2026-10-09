<?php

/**
 * @file plugins/generic/canonicalUrl/CanonicalUrlSettingsForm.inc.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @class CanonicalUrlSettingsForm
 *
 * @brief Settings per journal (Journal Manager / Site Administrator; the plugin grid checks
 *   the role, the form requires POST and a CSRF token). Saving clears the journal's sitemap cache.
 *   At site level (no journal) it only shows status information; nothing is saved.
 */

import('lib.pkp.classes.form.Form');

class CanonicalUrlSettingsForm extends Form {

	/** Limits of the cache lifetime (hours) */
	const CACHE_HOURS_MIN = 1;
	const CACHE_HOURS_MAX = 720;

	/** @var CanonicalUrlPlugin */
	private $_plugin;

	/** @var int */
	private $_contextId;

	/**
	 * @param CanonicalUrlPlugin $plugin
	 * @param int $contextId
	 */
	function __construct($plugin, $contextId) {
		$this->_plugin = $plugin;
		$this->_contextId = (int) $contextId;
		parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
		$this->addCheck(new FormValidatorCustom(
			$this,
			'sitemapCacheHours',
			'required',
			'plugins.generic.canonicalUrl.settings.sitemapCacheHours.invalid',
			function ($v) {
				$v = trim((string) $v);
				return ctype_digit($v) && (int) $v >= CanonicalUrlSettingsForm::CACHE_HOURS_MIN && (int) $v <= CanonicalUrlSettingsForm::CACHE_HOURS_MAX;
			}
		));
		$this->addCheck(new FormValidatorPost($this));
		$this->addCheck(new FormValidatorCSRF($this));
	}

	/**
	 * @copydoc Form::initData()
	 */
	function initData() {
		foreach (array_keys(CanonicalUrlPlugin::SETTINGS) as $name) {
			$this->setData($name, $this->_plugin->opt($this->_contextId, $name));
		}
	}

	/**
	 * @copydoc Form::readInputData()
	 */
	function readInputData() {
		$this->readUserVars(array_keys(CanonicalUrlPlugin::SETTINGS));
	}

	/**
	 * @copydoc Form::fetch()
	 */
	function fetch($request, $template = null, $display = false) {
		$context = $request->getContext();
		// This is a component (modal) request: build page addresses with the page router.
		$dispatcher = $request->getDispatcher();
		$path = $context ? $context->getPath() : 'index';
		// OJS builds addresses from the host name the request came in with (or from base_url[<journal>] when set);
		// the canonical address carries that name. A different name in the configuration means two names are in use.
		$canonicalHost = strtolower((string) parse_url($dispatcher->url($request, ROUTE_PAGE, $path), PHP_URL_HOST));
		$configUrl = Config::getVar('general', 'base_url[' . $path . ']');
		if (!$configUrl) {
			$configUrl = Config::getVar('general', 'base_url');
		}
		$configHost = strtolower((string) parse_url((string) $configUrl, PHP_URL_HOST));
		$requestHost = strtolower((string) preg_replace('/:\d+$/', '', isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : ''));
		list($cacheFiles, $cacheCreated, $cacheUrls) = $this->_plugin->sitemapCacheStatus($this->_contextId);
		$foreign = $this->_plugin->foreignCanonicalSeen($this->_contextId);

		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign(array(
			'pluginName' => $this->_plugin->getName(),
			'cuRouter' => ROUTE_COMPONENT,
			'canonicalHost' => $canonicalHost,
			'requestHost' => $requestHost,
			'configHost' => $configHost,
			'hostMismatch' => $configHost !== '' && $canonicalHost !== $configHost,
			'sitemapUrl' => $dispatcher->url($request, ROUTE_PAGE, $path, 'sitemap'),
			'cacheFiles' => $cacheFiles,
			'cacheCreated' => $cacheCreated ? date('Y-m-d H:i', $cacheCreated) : '',
			'cacheUrls' => $cacheUrls,
			'cacheHoursMin' => self::CACHE_HOURS_MIN,
			'cacheHoursMax' => self::CACHE_HOURS_MAX,
			'siteLevel' => !$context,
			// Status notices, so that nobody has to look on the server
			'cacheUnwritable' => $context && $this->_plugin->opt($this->_contextId, 'sitemapCache') && !$this->_plugin->cacheWritable(),
			'cacheDirShown' => 'cache/canonicalUrl',
			'foreignSeen' => (bool) $foreign,
			'foreignDate' => $foreign ? date('Y-m-d', $foreign['t']) : '',
			'foreignPage' => $foreign ? $foreign['page'] : '',
			'foreignHref' => $foreign ? $foreign['href'] : '',
		));
		return parent::fetch($request, $template, $display);
	}

	/**
	 * @copydoc Form::execute()
	 */
	function execute(...$functionArgs) {
		foreach (CanonicalUrlPlugin::SETTINGS as $name => $def) {
			$value = $this->getData($name);
			if ($def[0] === 'bool') {
				$this->_plugin->updateSetting($this->_contextId, $name, (bool) $value, 'bool');
			} else {
				$this->_plugin->updateSetting($this->_contextId, $name, (int) $value, 'int');
			}
		}
		$this->_plugin->forgetOpts($this->_contextId);
		$this->_plugin->purgeSitemapCache($this->_contextId);
		// The other-canonical notice is cleared; if the link is still there, the next page request records it again.
		$this->_plugin->forgetForeignCanonical($this->_contextId);
		parent::execute(...$functionArgs);
	}
}
