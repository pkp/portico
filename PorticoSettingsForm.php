<?php

/**
 * @file PorticoSettingsForm.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PorticoSettingsForm
 *
 * @brief Form for journal managers to modify Portico plugin settings
 */

namespace APP\plugins\importexport\portico;

use PKP\form\Form;
use APP\template\TemplateManager;
use PKP\form\validation\FormValidatorArrayCustom;

class PorticoSettingsForm extends Form
{
    /** @var int $contextId */
    private $contextId;

    /** @var PorticoExportPlugin $plugin */
    private $plugin;

    /**
     * Constructor
     *
     * @param $plugin PorticoExportPlugin
     * @param $contextId int
     */
    public function __construct(PorticoExportPlugin $plugin, $contextId)
    {
        $this->contextId = $contextId;
        $this->plugin = $plugin;

        parent::__construct($this->plugin->getTemplateResource('settingsForm.tpl'));

        $this->addCheck(new FormValidatorArrayCustom($this, 'endpoints', 'required', 'plugins.importexport.portico.manager.settings.required', function ($credentials) {
            return true;
        }));
    }

    /**
     * @copydoc Form::initData()
     */
    public function initData()
    {
        $this->setData('endpoints', $this->plugin->getEndpoints($this->contextId));
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData()
    {
        $this->readUserVars(['endpoints']);

        // Remove empties and resequence the array.
        $this->_data['endpoints'] = array_filter(array_values((array) $this->_data['endpoints']), function ($e) {
            return !empty($e['hostname']) && !empty($e['type']);
        });
    }

    /**
     * @copydata Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'endpointTypeOptions' => [
                '' => __('plugins.importexport.portico.endpoint.delete'),
                'portico' => 'Portico',
                'loc' => 'Library of Congress',
                'sftp' => 'SFTP',
                'ftp' => 'FTP',
            ],
            'newEndpointTypeOptions' => [
                '' => '',
                'portico' => 'Portico',
                'loc' => 'Library of Congress',
                'sftp' => 'SFTP',
                'ftp' => 'FTP',
            ],
            'pluginName' => $this->plugin::class,
        ]);
        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        foreach ($this->getData('endpoints') ?? [] as $endpoint) {
            if ($endpoint['private_key'] && is_file($endpoint['private_key'])) {
                throw new Exception('Invalid private key');
            }
        }
        $this->plugin->updateSetting($this->contextId, 'endpoints', $this->getData('endpoints'));
    }
}
