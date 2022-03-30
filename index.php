<?php

/**
 * @defgroup plugins_importexport_portico
 */

/**
 * @file index.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Wrapper for portico XML export plugin.
 *
 */

require_once('PorticoExportPlugin.inc.php');

return new PorticoExportPlugin();
