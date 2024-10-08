{**
 * templates/index.tpl
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * List of operations this plugin can perform
 *
 *}
{extends file="layouts/backend.tpl"}

{block name="page"}
<script>
	// Attach the JS file tab handler.
	$(function() {ldelim}
		$('#exportTabs')
			.pkpHandler('$.pkp.controllers.TabHandler')
			.tabs('option', 'cache', true);
	{rdelim});
</script>
{capture assign="ftpWarning"}
	{if $ftpLibraryMissing}
		{translate key="plugins.importexport.portico.ftpLibraryMissing"}
	{/if}
{/capture}
<div id="exportTabs">
	<ul>
		<li><a href="#settings-tab">{translate key="plugins.importexport.portico.settings"}</a></li>
		<li{if $porticoErrorMessage || $porticoSuccessMessage} class="ui-tabs-active"{/if}><a href="#exportIssues-tab">{translate key="plugins.importexport.portico.export.issues"}</a></li>
	</ul>
	<div id="settings-tab">
		{$ftpWarning}
		{capture assign=porticoSettingsGridUrl}{url router=$smarty.const.ROUTE_COMPONENT component="grid.settings.plugins.settingsPluginGridHandler" op="manage" plugin=$pluginName category="importexport" verb="settings" escape=false}{/capture}
		{load_url_in_div id="porticoSettingsGridContainer" url=$porticoSettingsGridUrl}
	</div>
	<div id="exportIssues-tab">
		<script>
			$(function() {ldelim}
				// Attach the form handler.
				var form = $('#exportIssuesXmlForm').pkpHandler('$.pkp.controllers.form.FormHandler');
				form.find('button[type=submit]').click(function () {
					form.trigger('unregisterAllForms');
				});
			{rdelim});
			{literal}
			function toggleIssues() {
				var elements = document.querySelectorAll("#exportIssuesXmlForm input[type=checkbox]");
				for (var i = elements.length; i--; ) {
						elements[i].checked ^= true;
				}
			}
			{/literal}
		</script>
		<form id="exportIssuesXmlForm" class="pkp_form" action="{plugin_url path="export"}" method="post">
			{$ftpWarning}
			{csrf}
			{fbvFormArea id="issuesXmlForm"}
				{if $porticoErrorMessage}
					<p><span class="error">{$porticoErrorMessage|escape}</strong></p>
				{/if}
				{if $porticoSuccessMessage}
					<p><span class="pkp_form_success">{$porticoSuccessMessage|escape}</strong></p>
				{/if}
				
				{if !$issn}
					<p><strong>{translate key="plugins.importexport.portico.issnWarning" setupUrl=$contextSettingsUrl}</strong></p>
				{/if}
				{if !$abbreviation}
					<p><strong>{translate key="plugins.importexport.portico.abbreviationWarning" setupUrl=$contextSettingsUrl}</strong></p>
				{/if}
				{capture assign=issuesListGridUrl}{url router=$smarty.const.ROUTE_COMPONENT component="grid.issues.ExportableIssuesListGridHandler" op="fetchGrid" escape=false}{/capture}
				{load_url_in_div id="issuesListGridContainer" url=$issuesListGridUrl}

				{fbvFormSection}
					{fbvElement type="submit" label="plugins.importexport.native.exportIssues" id="exportIssues" name="type" value="download" inline=true}
					{fbvElement type="submit" label="plugins.importexport.portico.export.ftp" id="exportFTP" name="type" value="ftp" inline=true}
					<input type="button" value="{translate key="plugins.importexport.portico.export.toggleSelection"|escape}" class="pkp_button" onclick="toggleIssues()" />
				{/fbvFormSection}
			{/fbvFormArea}
		</form>
	</div>
</div>

{/block}
