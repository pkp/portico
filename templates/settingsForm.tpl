{**
 * templates/settingsForm.tpl
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Portico plugin settings
 *
 *}
<script>
	$(function() {ldelim}
		// Attach the form handler.
		var form = $('#porticoSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');

		form.find('.endpointContainer select').on('change', function(e) {ldelim}
			var $endpointType = $(e.currentTarget),
				endpointType = $endpointType.val(),
				$container = $endpointType.closest('.endpointContainer').find('.endpointDetails');
			if (endpointType) {ldelim}
				$container.find('.presetField:not(.' + endpointType + ')').hide();
				$container.find('.presetField.' + endpointType).show();
			{rdelim}
			$container.show();
			if (endpointType !== 'sftp') {
				$container.find('[name$="[authentication]"][value=password]').click();
			}
			switch (endpointType) {ldelim}
				case 'loc':
					$container.find('input[name$="[hostname]"]').val('esubmit.loc.gov');
					$container.find('input[name$="[path]"]').val('/working');
					break;
				case 'portico':
					$container.find('input[name$="[hostname]"]').val('ftp.portico.org');
					$container.find('input[name$="[path]"]').val('/');
					break;
				case '':
					$container.hide();
					break;
			{rdelim}
		{rdelim}).change();

		$('[name$="[authentication]"]').on('change', function(e) {ldelim}
			if (!this.checked) {ldelim}
				return;
			{rdelim}
			var $parent = $(e.currentTarget).parents('.endpointDetails');
			var isPassword = this.value === 'password';
			$parent.find('.authentication-password').toggle(isPassword);
			$parent.find('.authentication-certificate').toggle(!isPassword);
			if (isPassword) {ldelim}
				$parent.find('textarea[name$="[private_key]"]').val('');
				$parent.find('input[name$="[keyphrase]"]').val('');
			{rdelim} else {ldelim}
				$parent.find('input[name$="[password]"]').val('');
			{rdelim}
		{rdelim}).change();

		// Prevent complaints about unsaved data
		$.pkp.classes.Handler.getHandler(form).formChangesTracked = false;
	{rdelim});
</script>
<form class="pkp_form" method="post" id="porticoSettingsForm" action="{url router=$smarty.const.ROUTE_COMPONENT op="manage" plugin="PorticoExportPlugin" category="importexport" verb="settings" save="true"}">
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="porticoSettingsFormNotification"}
	{fbvFormArea id="porticoSettingsFormArea"}
		<p class="pkp_help">{translate key="plugins.importexport.portico.description"}</p>
		{foreach from=$endpoints key=endpointKey item=credentials}
			{if $credentials.authentication === 'certificate'}
				{assign var="isCertificate" value=true}
			{else}
				{assign var="isCertificate" value=false}
			{/if}

			{capture assign="sectionTitle"}{translate key="plugins.importexport.portico.endpointNumber" number=$endpointKey+1}{/capture}
			{fbvFormSection id="formSection-$endpointKey" title=$sectionTitle translate=false class="endpointContainer"}
				{fbvElement type="select" id="endpoints-$endpointKey-type" name="endpoints[$endpointKey][type]" from=$endpointTypeOptions selected=$credentials.type label="plugins.importexport.portico.endpoint.type" size=$fbvStyles.size.SMALL translate=false}
				<div class="endpointDetails">
					<div class="presetField ftp sftp">
						{fbvElement type="text" id="endpoints-$endpointKey-hostname" name="endpoints[$endpointKey][hostname]" value=$credentials.hostname label="plugins.importexport.portico.endpoint.hostname" maxlength="120" size=$fbvStyles.size.MEDIUM}
						{fbvElement type="text" id="endpoints-$endpointKey-port" name="endpoints[$endpointKey][port]" value=$credentials.port label="plugins.importexport.portico.endpoint.port" maxlength="5" size=$fbvStyles.size.MEDIUM}
						{fbvElement type="text" id="endpoints-$endpointKey-path" name="endpoints[$endpointKey][path]" value=$credentials.path label="plugins.importexport.portico.endpoint.path" maxlength="120" size=$fbvStyles.size.MEDIUM}
					</div>
					{fbvElement type="text" id="endpoints-$endpointKey-username" name="endpoints[$endpointKey][username]" value=$credentials.username label="plugins.importexport.portico.endpoint.username" maxlength="120" size=$fbvStyles.size.MEDIUM}
					{fbvFormSection class="presetField sftp" id="endpoints-$endpointKey-authentication" description="plugins.importexport.portico.endpoint.authenticationType" list=true}
						{fbvElement type="radio" id="endpoints-$endpointKey-authentication-password" name="endpoints[$endpointKey][authentication]" value="password" checked=!$isCertificate label="plugins.importexport.portico.endpoint.password"}
						{fbvElement type="radio" id="endpoints-$endpointKey-authentication-certificate" name="endpoints[$endpointKey][authentication]" value="certificate" checked=$isCertificate label="plugins.importexport.portico.endpoint.private_key"}
					{/fbvFormSection}
					<div class="authentication-password">
						{fbvElement type="text" password="true" id="endpoints-$endpointKey-password" name="endpoints[$endpointKey][password]" value=$credentials.password label="plugins.importexport.portico.endpoint.password" maxlength="120" size=$fbvStyles.size.MEDIUM}
					</div>
					<div class="presetField sftp">
						<div class="authentication-certificate">
							{fbvElement type="textarea" id="endpoints-$endpointKey-private_key" name="endpoints[$endpointKey][private_key]" value=$credentials.private_key label="plugins.importexport.portico.endpoint.private_key" size=$fbvStyles.size.MEDIUM}
							{fbvElement type="text" password="true" id="endpoints-$endpointKey-keyphrase" name="endpoints[$endpointKey][keyphrase]" value=$credentials.keyphrase label="plugins.importexport.portico.endpoint.keyphrase" maxlength="1024" size=$fbvStyles.size.MEDIUM}
						</div>
					</div>
				</div>
			{/fbvFormSection}
		{/foreach}
		{fbvFormSection title="plugins.importexport.portico.newEndpoint" class="endpointContainer"}
			{fbvElement type="select" id="endpoints-new-type" name="endpoints[new][type]" from=$newEndpointTypeOptions selected=$endpoints.new.type label="plugins.importexport.portico.endpoint.type" size=$fbvStyles.size.SMALL translate=false}
			<div class="endpointDetails">
				<div class="presetField ftp sftp">
					{fbvElement type="text" id="endpoints-new-hostname" name="endpoints[new][hostname]" value=$endpoints.new.hostname label="plugins.importexport.portico.endpoint.hostname" maxlength="120" size=$fbvStyles.size.MEDIUM}
					{fbvElement type="text" id="endpoints-new-port" name="endpoints[new][port]" value=$endpoints.new.port label="plugins.importexport.portico.endpoint.port" maxlength="5" size=$fbvStyles.size.MEDIUM}
					{fbvElement type="text" id="endpoints-new-path" name="endpoints[new][path]" value=$endpoints.new.path label="plugins.importexport.portico.endpoint.path" maxlength="120" size=$fbvStyles.size.MEDIUM}
				</div>
				{fbvElement type="text" id="endpoints-new-username" name="endpoints[new][username]" value=$endpoints.new.username label="plugins.importexport.portico.endpoint.username" maxlength="120" size=$fbvStyles.size.MEDIUM}
				{fbvFormSection class="presetField sftp" id="endpoints-new-authentication" description="plugins.importexport.portico.endpoint.authenticationType" list=true}
					{fbvElement type="radio" id="endpoints-new-authentication-password" name="endpoints[new][authentication]" value="password" checked="true" label="plugins.importexport.portico.endpoint.password"}
					{fbvElement type="radio" id="endpoints-new-authentication-certificate" name="endpoints[new][authentication]" value="certificate" label="plugins.importexport.portico.endpoint.private_key"}
				{/fbvFormSection}
				<div class="authentication-password">
					{fbvElement type="text" password="true" id="endpoints-new-password" name="endpoints[new][password]" value=$endpoints.new.password label="plugins.importexport.portico.endpoint.password" maxlength="120" size=$fbvStyles.size.MEDIUM}
				</div>
				<div class="presetField sftp">
					<div class="authentication-certificate">
						{fbvElement type="textarea" id="endpoints-new-private_key" name="endpoints[new][private_key]" value=$endpoints.new.private_key label="plugins.importexport.portico.endpoint.private_key" size=$fbvStyles.size.MEDIUM}
						{fbvElement type="text" password="true" id="endpoints-new-keyphrase" name="endpoints[new][keyphrase]" value=$endpoints.new.keyphrase label="plugins.importexport.portico.endpoint.keyphrase" maxlength="1024" size=$fbvStyles.size.MEDIUM}
					</div>
				</div>
			</div>
		{/fbvFormSection}
	{/fbvFormArea}
	{fbvFormButtons submitText="common.save" hideCancel="true"}
	<p><span class="formRequired">{translate key="common.requiredField"}</span></p>
</form>
