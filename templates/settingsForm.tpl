{**
 * plugins/importexport/portico/settingsForm.tpl
 *
 * Copyright (c) 2014-2020 Simon Fraser University
 * Copyright (c) 2003-2020 John Willinsky
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
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="porticoSettingsFormNotification"}
	{fbvFormArea id="porticoSettingsFormArea"}
		<p class="pkp_help">{translate key="plugins.importexport.portico.description"}</p>
		{foreach from=$endpoints key=endpointKey item=credentials}
			{capture assign="sectionTitle"}{translate key="plugins.importexport.portico.endpointNumber" number=$endpointKey+1}{/capture}
			{fbvFormSection id="formSection-$endpointKey" title=$sectionTitle translate=false}
				<script>
					$('#endpoints-{$endpointKey}-type').on('change', function(e) {ldelim}
						if ($(e.currentTarget).val() !== '') {ldelim}
							$('[id^="endpoints-{$endpointKey}-container"]').show();
						{rdelim} else {ldelim}
							$('[id^="endpoints-{$endpointKey}-container"]').hide();
						{rdelim}
					{rdelim});
					$('#endpoints-{$endpointKey}-type').change();
				</script>
				{fbvElement type="select" id="endpoints-$endpointKey-type" name="endpoints[$endpointKey][type]" from=$endpointTypeOptions selected=$credentials.type label="plugins.importexport.portico.endpoint.type" size=$fbvStyles.size.SMALL translate=false}
				<div id="endpoints-{$endpointKey}-container">
					{fbvElement type="text" id="endpoints-$endpointKey-hostname" name="endpoints[$endpointKey][hostname]" value=$credentials.hostname label="plugins.importexport.portico.endpoint.hostname" maxlength="120" size=$fbvStyles.size.MEDIUM}
					{fbvElement type="text" id="endpoints-$endpointKey-username" name="endpoints[$endpointKey][username]" value=$credentials.username label="plugins.importexport.portico.endpoint.username" maxlength="120" size=$fbvStyles.size.MEDIUM}
					{fbvElement type="text" id="endpoints-$endpointKey-password" name="endpoints[$endpointKey][password]" value=$credentials.password label="plugins.importexport.portico.endpoint.password" maxlength="120" size=$fbvStyles.size.MEDIUM}
					{fbvElement type="text" id="endpoints-$endpointKey-path" name="endpoints[$endpointKey][path]" value=$credentials.path label="plugins.importexport.portico.endpoint.path" maxlength="120" size=$fbvStyles.size.MEDIUM}
				</div>
			{/fbvFormSection}
		{/foreach}
		{fbvFormSection title="plugins.importexport.portico.newEndpoint"}
			{fbvElement type="select" id="endpoints-new-type" name="endpoints[new][type]" from=$newEndpointTypeOptions selected=$endpoints.new.type label="plugins.importexport.portico.endpoint.type" size=$fbvStyles.size.SMALL translate=false}
			<script>
				$('#endpoints-new-type').on('change', function(e) {ldelim}
					if ($(e.currentTarget).val() !== '') {ldelim}
						$('[id^="endpoints-new-container"]').show();
					{rdelim} else {ldelim}
						$('[id^="endpoints-new-container"]').hide();
					{rdelim}
				{rdelim});
				$('#endpoints-new-type').change();
			</script>
			<div id="endpoints-new-container">
				{fbvElement type="text" id="endpoints-new-hostname" name="endpoints[new][hostname]" value=$endpoints.new.hostname label="plugins.importexport.portico.endpoint.hostname" maxlength="120" size=$fbvStyles.size.MEDIUM}
				{fbvElement type="text" id="endpoints-new-username" name="endpoints[new][username]" value=$endpoints.new.username label="plugins.importexport.portico.endpoint.username" maxlength="120" size=$fbvStyles.size.MEDIUM}
				{fbvElement type="text" id="endpoints-new-password" name="endpoints[new][password]" value=$endpoints.new.password label="plugins.importexport.portico.endpoint.password" maxlength="120" size=$fbvStyles.size.MEDIUM}
				{fbvElement type="text" id="endpoints-new-path" name="endpoints[new][path]" value=$endpoints.new.path label="plugins.importexport.portico.endpoint.path" maxlength="120" size=$fbvStyles.size.MEDIUM}
			</div>
		{/fbvFormSection}
	{/fbvFormArea}
	{fbvFormButtons submitText="common.save" hideCancel="true"}
	<p><span class="formRequired">{translate key="common.requiredField"}</span></p>
</form>
