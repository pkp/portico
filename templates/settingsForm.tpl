{**
 * templates/settingsForm.tpl
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Portico plugin settings
 *
 *}
<script>
	$(function() {ldelim}
		// Attach the form handler.
		$('#porticoSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>
<form class="pkp_form" method="post" id="porticoSettingsForm" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" plugin="PorticoExportPlugin" category="importexport" verb="settings" save="true"}">
	{fbvFormArea id="porticoSettingsFormArea"}
		<p class="pkp_help">{translate key="plugins.importexport.portico.description"}</p>
		{fbvFormSection}
			{fbvElement type="text" required="true" id="porticoHost" value=$porticoHost label="plugins.importexport.portico.porticoHost" maxlength="50" size=$fbvStyles.size.MEDIUM}
			<span class="instruct">{translate key="plugins.importexport.portico.porticoHostInstructions"}</span>
			{fbvElement type="text" required="true" id="porticoUsername" value=$porticoUsername label="plugins.importexport.portico.porticoUsername" maxlength="50" size=$fbvStyles.size.MEDIUM}
			{fbvElement type="text" required="true" password="true" id="porticoPassword" value=$porticoPassword label="plugins.importexport.portico.porticoPassword" maxlength="50" size=$fbvStyles.size.MEDIUM}
			<span class="instruct">{translate key="plugins.importexport.portico.password.description"}</span>
		{/fbvFormSection}
	{/fbvFormArea}
	{fbvFormButtons submitText="common.save" hideCancel="true"}
	<p><span class="formRequired">{translate key="common.requiredField"}</span></p>
</form>
