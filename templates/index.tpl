{**
 * plugins/importexport/portico/index.tpl
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * List of operations this plugin can perform
 *
 *}
{include file="common/header.tpl" pageTitle="plugins.importexport.portico.displayName"}

<script>
	// Attach the JS file tab handler.
	$(function() {ldelim}
		$('#exportTabs').pkpHandler('$.pkp.controllers.TabHandler');
		$('#exportTabs').tabs('option', 'cache', true);
	{rdelim});
</script>
<div id="exportTabs">
	<ul>
		<li><a href="#settings-tab">{translate key="plugins.importexport.portico.settings"}</a></li>
		<li{if $porticoErrorMessage || $porticoSuccessMessage} class="ui-tabs-active"{/if}><a href="#exportIssues-tab">{translate key="plugins.importexport.portico.export.issues"}</a></li>
	</ul>
	<div id="settings-tab">
		{capture assign=porticoSettingsGridUrl}{url router=$smarty.const.ROUTE_COMPONENT component="grid.settings.plugins.settingsPluginGridHandler" op="manage" plugin="PorticoExportPlugin" category="importexport" verb="settings" escape=false}{/capture}
		{load_url_in_div id="porticoSettingsGridContainer" url=$porticoSettingsGridUrl}
	</div>
	<div id="exportIssues-tab">
		<script>
			$(function() {ldelim}
				// Attach the form handler.
				$('#exportIssuesXmlForm').pkpHandler('$.pkp.controllers.form.FormHandler');
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

{include file="common/footer.tpl"}
