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

	/** @var $fields array */
	private $fields = ['porticoHost', 'porticoUsername', 'porticoPassword'];

	/**
	 * Constructor
	 * @param $plugin PorticoExportPlugin
	 * @param $contextId int
	 */
	public function __construct(PorticoExportPlugin $plugin, $contextId) {
		$this->contextId = $contextId;
		$this->plugin = $plugin;

		parent::__construct($this->plugin->getTemplateResource('settingsForm.tpl'));

		foreach($this->fields as $name) {
			$this->addCheck(new FormValidator($this, $name, 'required', 'plugins.importexport.portico.manager.settings.' . $name . 'Required'));
		}
	}

	/**
	 * @copydoc Form::initData()
	 */
	public function initData() {
		foreach($this->fields as $name) {
			$this->setData($name, $this->plugin->getSetting($this->contextId, $name));
		}
	}

	/**
	 * @copydoc Form::readInputData()
	 */
	public function readInputData() {
		$this->readUserVars($this->fields);
	}

	/**
	 * @copydoc Form::execute()
	 */
	public function execute(...$functionArgs) {
		foreach($this->fields as $name) {
			$this->plugin->updateSetting($this->contextId, $name, $this->getData($name));
		}
	}
}
