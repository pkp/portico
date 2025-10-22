<?php

/**
 * @file PorticoSettingsForm.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PorticoSettingsForm
 *
 * @brief Form for journal managers to modify Portico plugin settings
 */

namespace APP\plugins\importexport\portico;

use APP\template\TemplateManager;
use Exception;
use PKP\form\Form;
use PKP\form\validation\FormValidator;
use PKP\form\validation\FormValidatorArrayCustom;

class PorticoSettingsForm extends Form
{
    /**
     * Constructor
     */
    public function __construct(private PorticoExportPlugin $plugin, private int $contextId)
    {

        parent::__construct($this->plugin->getTemplateResource('settingsForm.tpl'));

        $this->addCheck(
            new FormValidatorArrayCustom(
                $this,
                'endpoints',
                FormValidator::FORM_VALIDATOR_REQUIRED_VALUE,
                'plugins.importexport.portico.manager.settings.required',
                fn () => true
            )
        );
    }

    /**
     * @copydoc Form::initData()
     */
    public function initData(): void
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
     * @throws Exception
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
     * @throws Exception
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
