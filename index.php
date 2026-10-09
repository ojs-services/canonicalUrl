<?php

/**
 * @defgroup plugins_generic_canonicalUrl Canonical URL Plugin
 */

/**
 * @file plugins/generic/canonicalUrl/index.php
 *
 * Distributed under the GNU GPL v3.
 *
 * @brief Wrapper for the Canonical URL plugin (OJS 3.3).
 */

require_once('CanonicalUrlPlugin.inc.php');

return new CanonicalUrlPlugin();
