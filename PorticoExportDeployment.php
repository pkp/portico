<?php

/**
 * @file plugins/generic/portico/PorticoExportDeployment.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PorticoExportDeployment
 *
 * @brief Unused stub required by PubObjectsExportPlugin::getExportDeploymentClassName();
 *  this plugin builds its export packages directly (see PorticoExportPlugin::createZip())
 *  rather than through the filter-based XML export/deployment machinery.
 */

namespace APP\plugins\generic\portico;

use PKP\context\Context;

class PorticoExportDeployment
{
    public function __construct(public Context $context, public PorticoExportPlugin $plugin)
    {
    }
}
