<?php
/** @var \OCP\IL10N $l */
\OCP\Util::addStyle('meta_data', 'meta_data');
\OCP\Util::addScript('meta_data', 'main');
?>
<div id="app">
<div id="app-content">
<div id="app-content-wrapper">

<div id="app-content-meta_data">
<div class="tags-toolbar">
	<button id="new-tag-btn" class="button primary"><?php p($l->t('New tag')); ?></button>
</div>
<table id="tagstable">
<thead>
<tr>
	<th class="column-name"><?php p($l->t('Tag')); ?></th>
	<th class="column-files"><?php p($l->t('Files')); ?></th>
	<th class="column-actions"></th>
</tr>
</thead>
<tbody id="tagList"></tbody>
<tfoot id="tagSummary"></tfoot>
</table>
</div><!-- #app-content-meta_data -->

<div id="tag-details">
	<div class="tag-details-header">
		<h2 class="tag-details-title"></h2>
		<button class="tag-details-close" title="<?php p($l->t('Close')); ?>">&#x2715;</button>
	</div>
	<div class="tag-details-body">
		<div class="tag-details-field">
			<label><?php p($l->t('Tag name')); ?></label>
			<input class="edittag" type="text">
		</div>
		<div class="tag-details-field">
			<label><?php p($l->t('Color')); ?></label>
			<div class="color-row">
				<input class="editcolor" type="color" value="#0082c9">
				<button type="button" class="clear-color" title="<?php p($l->t('Remove color')); ?>">&#x2715; <?php p($l->t('No color')); ?></button>
			</div>
		</div>
		<div class="tag-details-field">
			<label><?php p($l->t('Description')); ?></label>
			<textarea class="editdesc"></textarea>
		</div>
		<h3><?php p($l->t('Metadata fields')); ?></h3>
		<div id="emptysearch"><?php p($l->t('No metadata fields defined')); ?></div>
		<ul id="meta_data_keys"></ul>
		<button id="add_key" class="button"><?php p($l->t('Add field')); ?></button>
	</div>
	<div class="tag-details-footer">
		<button id="details-save" class="button primary"><?php p($l->t('Save')); ?></button>
		<button id="details-cancel" class="button"><?php p($l->t('Cancel')); ?></button>
	</div>
</div><!-- #tag-details -->

</div><!-- #app-content-wrapper -->
</div><!-- #app-content -->
</div><!-- #app -->
