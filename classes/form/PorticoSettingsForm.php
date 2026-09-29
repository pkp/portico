<?php

/**
 * @file plugins/generic/portico/classes/form/PorticoSettingsForm.php
 *
 * Copyright (c) 2014-2026 Simon Fraser University
 * Copyright (c) 2003-2026 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PorticoSettingsForm
 *
 * @brief Form for journal managers to configure Portico delivery endpoints
 */

namespace APP\plugins\generic\portico\classes\form;

use APP\plugins\generic\portico\PorticoExportPlugin;
use APP\plugins\PubObjectsExportSettingsForm;
use APP\template\TemplateManager;
use Exception;
use PKP\form\validation\FormValidator;
use PKP\form\validation\FormValidatorArrayCustom;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;

class PorticoSettingsForm extends PubObjectsExportSettingsForm
{
    /**
     * Constructor
     */
    public function __construct(private readonly PorticoExportPlugin $plugin, private readonly int $contextId)
    {
        parent::__construct($this->plugin->getTemplateResource('settingsForm.tpl'));

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
        $this->addCheck(
            new FormValidatorArrayCustom(
                $this,
                'endpoints',
                FormValidator::FORM_VALIDATOR_OPTIONAL_VALUE,
                'plugins.importexport.portico.manager.settings.required',
                fn (array $endpoint) => $this->plugin->isEndpointComplete($endpoint)
            )
        );
        $this->addCheck(
            new FormValidatorCustom(
                $this,
                'automaticRegistration',
                FormValidator::FORM_VALIDATOR_OPTIONAL_VALUE,
                'plugins.importexport.portico.manager.settings.automaticRegistrationRequiresEndpoint',
                fn () => $this->hasCompleteEndpointInFormData()
            )
        );
    }

    /**
     * Whether the submitted (not yet saved) endpoints include at least one complete one.
     */
    protected function hasCompleteEndpointInFormData(): bool
    {
        foreach ((array) $this->getData('endpoints') as $endpoint) {
            if ($this->plugin->isEndpointComplete($endpoint)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @copydoc Form::initData()
     */
    public function initData(): void
    {
        $this->setData('endpoints', $this->plugin->getEndpoints($this->contextId));
        $this->setData('automaticRegistration', $this->plugin->getSetting($this->contextId, 'automaticRegistration'));
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData(): void
    {
        $this->readUserVars(['endpoints', 'automaticRegistration']);

        // Remove empties and resequence the array.
        $this->_data['endpoints'] = array_filter(array_values((array) $this->_data['endpoints']), function ($e) {
            return !empty($e['hostname']) && !empty($e['type']);
        });
    }

    /**
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false): ?string
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
        ]);
        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs): void
    {
        parent::execute(...$functionArgs);
        foreach ($this->getData('endpoints') ?? [] as $endpoint) {
            if (!empty($endpoint['private_key']) && !is_file($endpoint['private_key'])) {
                throw new Exception('Private key file not found');
            }
        }
        $this->plugin->updateSetting($this->contextId, 'endpoints', $this->getData('endpoints'), 'object');
        $this->plugin->updateSetting($this->contextId, 'automaticRegistration', $this->getData('automaticRegistration'), 'bool');
    }

    /**
     * @copydoc PubObjectsExportSettingsForm::getFormFields()
     */
    public function getFormFields(): array
    {
        return [
            'endpoints' => 'object',
            'automaticRegistration' => 'bool',
        ];
    }

    /**
     * @copydoc PubObjectsExportSettingsForm::isOptional()
     */
    public function isOptional(string $settingName): bool
    {
        return in_array($settingName, ['endpoints', 'automaticRegistration']);
    }
}
