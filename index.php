<?php

/**
 * @defgroup plugins_importexport_portico
 */

/**
 * @file plugins/importexport/portico/index.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @ingroup plugins_importexport_portico
 * @brief Wrapper for portico XML export plugin.
 *
 */

require_once('PorticoExportPlugin.inc.php');

return new PorticoExportPlugin();
