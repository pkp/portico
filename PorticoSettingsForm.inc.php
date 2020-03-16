<?php

/**
 * @file plugins/importexport/portico/PorticoSettingsForm.inc.php
 *
 * Copyright (c) 2014-2019 Simon Fraser University
 * Copyright (c) 2003-2019 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class PorticoSettingsForm
 * @ingroup plugins_importexport_portico
 *
 * @brief Form for journal managers to modify Portico plugin settings
 */


import('lib.pkp.classes.form.Form');

class PorticoSettingsForm extends Form {

	/** @var $journalId int */
	private $journalId;

	/** @var $plugin PorticoExportPlugin */
	private $plugin;

	/** @var $fields array */
	private $fields = ['porticoHost', 'porticoUsername', 'porticoPassword'];

	/**
	 * Constructor
	 * @param $plugin PorticoExportPlugin
	 * @param $journalId int
	 */
	public function __construct(PorticoExportPlugin $plugin, $journalId) {
		$this->journalId = $journalId;
		$this->plugin = $plugin;

		parent::__construct($this->plugin->getTemplatePath(). 'settingsForm.tpl');

		foreach($this->fields as $name) {
			$this->addCheck(new FormValidator($this, $name, 'required', 'plugins.importexport.portico.manager.settings.' . $name . 'Required'));
		}
	}

	/**
	 * @copydoc Form::initData()
	 */
	public function initData() {
		foreach($this->fields as $name) {
			$this->setData($name, $this->plugin->getSetting($this->journalId, $name));
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
	public function execute() {
		foreach($this->fields as $name) {
			$this->plugin->updateSetting($this->journalId, $name, $this->getData($name));
		}
	}
}
