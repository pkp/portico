<?php

/**
 * @file PorticoSettingsForm.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PorticoSettingsForm
 *
 * @brief Form for journal managers to modify Portico plugin settings
 */


import('lib.pkp.classes.form.Form');

class PorticoSettingsForm extends Form {

	/** @var $contextId int */
	private $contextId;

	/** @var $plugin PorticoExportPlugin */
	private $plugin;

	/**
	 * Constructor
	 * @param $plugin PorticoExportPlugin
	 * @param $contextId int
	 */
	public function __construct(PorticoExportPlugin $plugin, $contextId) {
		$this->contextId = $contextId;
		$this->plugin = $plugin;

		parent::__construct($this->plugin->getTemplateResource('settingsForm.tpl'));

		$this->addCheck(new FormValidatorArrayCustom($this, 'endpoints', 'required', 'plugins.importexport.portico.manager.settings.required', function($credentials) {
			return true;
		}));
	}

	/**
	 * @copydoc Form::initData()
	 */
	public function initData() {
		$this->setData('endpoints', $this->plugin->getEndpoints($this->contextId));
	}

	/**
	 * @copydoc Form::readInputData()
	 */
	public function readInputData() {
		$this->readUserVars(['endpoints']);

		// Remove empties and resequence the array.
		$this->_data['endpoints'] = array_filter(array_values((array) $this->_data['endpoints']), function($e) {
			return !empty($e['hostname']) && !empty($e['type']);
		});
	}

	/**
	 * @copydata Form::fetch()
	 */
	function fetch($request, $template = null, $display = false) {
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
			]
		]);
		return parent::fetch($request, $template, $display);
	}

	/**
	 * @copydoc Form::execute()
	 */
	public function execute(...$functionArgs) {
		$this->plugin->updateSetting($this->contextId, 'endpoints', $this->getData('endpoints'));
	}
}
