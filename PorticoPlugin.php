<?php

/**
 * @file plugins/generic/portico/PorticoPlugin.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PorticoPlugin
 *
 * @brief Plugin to export and deliver articles to the Portico digital preservation service.
 */

namespace APP\plugins\generic\portico;

use APP\plugins\generic\portico\classes\migration\upgrade\updatePorticoPluginNameToGeneric;
use APP\plugins\PubObjectsExportGenericPlugin;
use PKP\plugins\PluginRegistry;

class PorticoPlugin extends PubObjectsExportGenericPlugin
{
    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        return parent::register($category, $path, $mainContextId);
    }

    /**
     * @copydoc Plugin::getInstallMigration()
     */
    public function getInstallMigration(): updatePorticoPluginNameToGeneric
    {
        return new updatePorticoPluginNameToGeneric();
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName(): string
    {
        return __('plugins.generic.portico.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription(): string
    {
        return __('plugins.generic.portico.description');
    }

    protected function setExportPlugin(): void
    {
        PluginRegistry::register('importexport', new PorticoExportPlugin(), $this->getPluginPath());
        $this->exportPlugin = PluginRegistry::getPlugin('importexport', 'PorticoExportPlugin');
    }

    /**
     * @copydoc Plugin::getContextSpecificPluginSettingsFile()
     */
    public function getContextSpecificPluginSettingsFile(): string
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    /**
     * @copydoc Plugin::getInstallSitePluginSettingsFile()
     */
    public function getInstallSitePluginSettingsFile(): string
    {
        return $this->getPluginPath() . '/settings.xml';
    }
}
