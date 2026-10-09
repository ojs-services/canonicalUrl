{**
 * plugins/generic/canonicalUrl/templates/settingsForm.tpl
 *
 * Distributed under the GNU GPL v3.
 *
 * Canonical URL settings (per journal). Tabs: canonical address, sitemap, excluded from indexing.
 * At site level (no journal) only status information is shown.
 *}
{if !$siteLevel}
<script>
	$(function() {ldelim}
		$('#canonicalUrlSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
		var $f = $('#canonicalUrlSettingsForm');
		$f.find('.cu_tabs button').on('click', function(e) {ldelim}
			e.preventDefault();
			var t = $(this).attr('aria-controls');
			$f.find('.cu_tabs button').attr('aria-selected', 'false');
			$(this).attr('aria-selected', 'true');
			$f.find('.cu_panel').attr('hidden', 'hidden');
			$f.find('#' + t).removeAttr('hidden');
		{rdelim});
	{rdelim});
</script>
{/if}
<style>
	#canonicalUrlSettingsForm .cu_tabs {ldelim} display: flex; flex-wrap: wrap; gap: .25rem; border-bottom: 1px solid #ddd; margin: 0 0 1rem; padding: 0; {rdelim}
	#canonicalUrlSettingsForm .cu_tabs button {ldelim} background: none; border: 1px solid transparent; border-bottom: none; padding: .5rem 1rem; cursor: pointer; font: inherit; color: #006798; margin-bottom: -1px; {rdelim}
	#canonicalUrlSettingsForm .cu_tabs button[aria-selected="true"] {ldelim} border-color: #ddd; background: #fff; color: #222; font-weight: 700; border-radius: 3px 3px 0 0; {rdelim}
	#canonicalUrlSettingsForm .cu_note {ldelim} font-size: .875rem; color: #555; margin: .25rem 0 1rem; {rdelim}
	#canonicalUrlSettingsForm .cu_warn {ldelim} border-left: 4px solid #d00a0a; background: #fdf1f1; padding: .5rem .75rem; margin: 0 0 1rem; font-size: .875rem; {rdelim}
	#canonicalUrlSettingsForm .cu_info {ldelim} border-left: 4px solid #006798; background: #f1f7fa; padding: .5rem .75rem; margin: 0 0 1rem; font-size: .875rem; {rdelim}
	#canonicalUrlSettingsForm code {ldelim} word-break: break-all; {rdelim}
</style>

{if $siteLevel}
<div id="canonicalUrlSettingsForm">
	<p class="cu_note">{translate key="plugins.generic.canonicalUrl.settings.siteIntro"}</p>
	{if $hostMismatch}
		<div class="cu_warn">{translate key="plugins.generic.canonicalUrl.settings.hostMismatch" canonicalHost=$canonicalHost|escape requestHost=$requestHost|escape configHost=$configHost|escape}</div>
	{else}
		<div class="cu_info">{translate key="plugins.generic.canonicalUrl.settings.host" canonicalHost=$canonicalHost|escape}</div>
	{/if}
</div>
{else}
{* On an error, open the tab that holds the field in error *}
{assign var=cuOpen value="cuPanelCanonical"}
{if $isError && isset($errors.sitemapCacheHours)}{assign var=cuOpen value="cuPanelSitemap"}{/if}
<form class="pkp_form" id="canonicalUrlSettingsForm" method="post" action="{url router=$cuRouter op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="canonicalUrlSettingsFormNotification"}

	<p class="cu_note">{translate key="plugins.generic.canonicalUrl.settings.intro"}</p>

	{if $isError}
		<div class="cu_warn" role="alert">
			{translate key="form.errorsOccurred"}:
			<ul>{foreach from=$errors item=message}<li>{$message|escape}</li>{/foreach}</ul>
		</div>
	{/if}

	{* Status notices: visible whichever tab is open *}
	{if $cacheUnwritable}
		<div class="cu_warn" id="cuCacheUnwritable">{translate key="plugins.generic.canonicalUrl.settings.cacheUnwritable" dir=$cacheDirShown|escape}</div>
	{/if}
	{if $foreignSeen}
		<div class="cu_warn" id="cuForeignCanonical">{translate key="plugins.generic.canonicalUrl.settings.foreignCanonical" date=$foreignDate|escape page=$foreignPage|escape href=$foreignHref|escape}</div>
	{/if}

	<div class="cu_tabs" role="tablist">
		<button type="button" role="tab" aria-selected="{if $cuOpen == "cuPanelCanonical"}true{else}false{/if}" aria-controls="cuPanelCanonical">{translate key="plugins.generic.canonicalUrl.settings.tab.canonical"}</button>
		<button type="button" role="tab" aria-selected="{if $cuOpen == "cuPanelSitemap"}true{else}false{/if}" aria-controls="cuPanelSitemap">{translate key="plugins.generic.canonicalUrl.settings.tab.sitemap"}</button>
		<button type="button" role="tab" aria-selected="false" aria-controls="cuPanelNoindex">{translate key="plugins.generic.canonicalUrl.settings.tab.noindex"}</button>
	</div>

	<div class="cu_panel" id="cuPanelCanonical" role="tabpanel"{if $cuOpen != "cuPanelCanonical"} hidden="hidden"{/if}>
		{if $hostMismatch}
			<div class="cu_warn">{translate key="plugins.generic.canonicalUrl.settings.hostMismatch" canonicalHost=$canonicalHost|escape requestHost=$requestHost|escape configHost=$configHost|escape}</div>
		{else}
			<div class="cu_info">{translate key="plugins.generic.canonicalUrl.settings.host" canonicalHost=$canonicalHost|escape}</div>
		{/if}
		{fbvFormArea id="cuCanonicalArea"}
			{fbvFormSection list=true}
				{fbvElement type="checkbox" id="canonicalTag" value="1" checked=$canonicalTag label="plugins.generic.canonicalUrl.settings.canonicalTag"}
				{fbvElement type="checkbox" id="galleyToArticle" value="1" checked=$galleyToArticle label="plugins.generic.canonicalUrl.settings.galleyToArticle"}
				{fbvElement type="checkbox" id="linkHeader" value="1" checked=$linkHeader label="plugins.generic.canonicalUrl.settings.linkHeader"}
			{/fbvFormSection}
		{/fbvFormArea}
		<p class="cu_note">{translate key="plugins.generic.canonicalUrl.settings.canonicalNote"}</p>
	</div>

	<div class="cu_panel" id="cuPanelSitemap" role="tabpanel"{if $cuOpen != "cuPanelSitemap"} hidden="hidden"{/if}>
		<div class="cu_info">
			{translate key="plugins.generic.canonicalUrl.settings.sitemapUrl"} <code>{$sitemapUrl|escape}</code><br/>
			{if $cacheFiles}
				{translate key="plugins.generic.canonicalUrl.settings.cacheStatus" files=$cacheFiles created=$cacheCreated urls=$cacheUrls}
			{else}
				{translate key="plugins.generic.canonicalUrl.settings.cacheEmpty"}
			{/if}
		</div>
		{fbvFormArea id="cuSitemapArea"}
			{fbvFormSection list=true}
				{fbvElement type="checkbox" id="sitemapFilterGalleys" value="1" checked=$sitemapFilterGalleys label="plugins.generic.canonicalUrl.settings.sitemapFilterGalleys"}
				{fbvElement type="checkbox" id="sitemapFilterThin" value="1" checked=$sitemapFilterThin label="plugins.generic.canonicalUrl.settings.sitemapFilterThin"}
				{fbvElement type="checkbox" id="sitemapLastmod" value="1" checked=$sitemapLastmod label="plugins.generic.canonicalUrl.settings.sitemapLastmod"}
				{fbvElement type="checkbox" id="sitemapAnnouncements" value="1" checked=$sitemapAnnouncements label="plugins.generic.canonicalUrl.settings.sitemapAnnouncements"}
				{fbvElement type="checkbox" id="sitemapCustomPages" value="1" checked=$sitemapCustomPages label="plugins.generic.canonicalUrl.settings.sitemapCustomPages"}
				{fbvElement type="checkbox" id="sitemapCache" value="1" checked=$sitemapCache label="plugins.generic.canonicalUrl.settings.sitemapCache"}
			{/fbvFormSection}
			{fbvFormSection title="plugins.generic.canonicalUrl.settings.sitemapCacheHours"}
				{fbvElement type="text" id="sitemapCacheHours" value=$sitemapCacheHours size=$fbvStyles.size.SMALL required=true}
			{/fbvFormSection}
		{/fbvFormArea}
		<p class="cu_note">{translate key="plugins.generic.canonicalUrl.settings.sitemapCacheNote" min=$cacheHoursMin max=$cacheHoursMax}</p>
	</div>

	<div class="cu_panel" id="cuPanelNoindex" role="tabpanel" hidden="hidden">
		{fbvFormArea id="cuNoindexArea"}
			{fbvFormSection list=true}
				{fbvElement type="checkbox" id="noindexThin" value="1" checked=$noindexThin label="plugins.generic.canonicalUrl.settings.noindexThin"}
				{fbvElement type="checkbox" id="noindexCitations" value="1" checked=$noindexCitations label="plugins.generic.canonicalUrl.settings.noindexCitations"}
			{/fbvFormSection}
		{/fbvFormArea}
		<p class="cu_note">{translate key="plugins.generic.canonicalUrl.settings.noindexNote"}</p>
	</div>

	{fbvFormButtons submitText="common.save"}
</form>
{/if}
