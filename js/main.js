/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Metadata Tags — management page JS
 */

(function($) {
	'use strict';

	$.ajaxSetup({ headers: { 'OCS-APIREQUEST': 'true', 'requesttoken': OC.requestToken } });

	function apiUrl(path) {
		return OC.linkToOCS('apps/meta_data/api/v1', 2) + path;
	}

	function ocsGet(path, data) {
		return $.ajax({ url: apiUrl(path), type: 'GET', data: data || {}, dataType: 'json' })
			.then(function(r) { return r.ocs.data; });
	}

	function ocsPost(path, data) {
		return $.ajax({ url: apiUrl(path), type: 'POST', data: data || {}, dataType: 'json' })
			.then(function(r) { return r.ocs.data; });
	}

	function ocsPut(path, data) {
		return $.ajax({ url: apiUrl(path), type: 'PUT', data: data || {}, dataType: 'json' })
			.then(function(r) { return r.ocs.data; });
	}

	function ocsDelete(path, data) {
		return $.ajax({ url: apiUrl(path), type: 'DELETE', data: data || {}, dataType: 'json' })
			.then(function(r) { return r.ocs.data; });
	}

	// ── Color helpers ──────────────────────────────────────────────────────────

	function isHex(color) {
		return color && /^[0-9a-fA-F]{6}$/.test(color);
	}

	function colorToHex(color) {
		if (!color) return '';
		return color.replace('#', '');
	}

	function hexToInput(color) {
		if (!color) return '#0082c9';
		return color.startsWith('#') ? color : '#' + color;
	}

	// ── Tag list ──────────────────────────────────────────────────────────────

	function updateTagsView() {
		$('#tagList').empty();
		$('#tagSummary').empty();

		ocsGet('tags', { fileCount: true }).done(function(data) {
			var tags = data.tags || [];
			var total = 0;

			tags.forEach(function(tag) {
				addTagRow(tag);
				total += parseInt(tag.size || 0);
			});

			$('#tagSummary').append(
				'<tr><td colspan="3">' +
				tags.length + ' ' + t('meta_data', 'tags') + ' &mdash; ' +
				total + ' ' + t('meta_data', 'files') +
				'</td></tr>'
			);
		});
	}

	function addTagRow(tag) {
		var hex = isHex(tag.color) ? tag.color : '';
		var labelClass = 'tag-label' + (hex ? ' has-color' : '');
		var labelStyle = hex ? 'background:#' + hex + ';border-color:#' + hex : '';

		$('#tagList').append(
			'<tr data-id="' + tag.id + '" data-name="' + _.escape(tag.name) +
			'" data-color="' + _.escape(tag.color || '') +
			'" data-desc="' + _.escape(tag.description || '') + '">' +
			'<td class="column-name">' +
			'<span class="taginfo" title="' + _.escape(tag.description || '') + '">' +
			'<a class="action-meta_data ' + labelClass + '" href="#" style="' + labelStyle + '">' +
			'<span class="tagname">' + _.escape(tag.name) + '</span>' +
			'</a></span></td>' +
			'<td class="column-files">' +
			'<a href="' + OC.generateUrl('/apps/files') + '?dir=%2F&view=tag-' + tag.id + '">' +
			(tag.size || 0) + '</a></td>' +
			'<td class="column-actions">' +
			'<span class="delete-tag icon icon-delete" title="' + t('meta_data', 'Delete tag') + '" data-id="' + tag.id + '" data-name="' + _.escape(tag.name) + '"></span>' +
			'</td>' +
			'</tr>'
		);
	}

	// ── Schema editor panel ───────────────────────────────────────────────────

	var currentTagId = null;  // null = creating new tag
	var detailsReadonly = false;

	function getNoColorHex() {
		var el = document.getElementById('app-content-meta_data') || document.body;
		var rgb = getComputedStyle(el).backgroundColor;
		var m = rgb.match(/\d+/g);
		if (!m || m.length < 3) return '#ffffff';
		return '#' + m.slice(0, 3).map(function(v) {
			return ('0' + parseInt(v).toString(16)).slice(-2);
		}).join('');
	}

	function setColorState($input, hasColor) {
		$input.data('use-color', hasColor);
		if (!hasColor) {
			$input.val(getNoColorHex());
		}
	}

	function openSchemaEditor(tagid, tagname, tagcolor, tagdesc, readonly) {
		currentTagId = tagid;
		detailsReadonly = readonly;

		$('#meta_data_keys').empty();
		$('#emptysearch').show();
		$('#tag-details .edittag').val(tagname).prop('readonly', readonly);

		var $colorInput = $('#tag-details .editcolor');
		var hasColor = !!tagcolor;
		$colorInput.val(hasColor ? hexToInput(tagcolor) : getNoColorHex()).prop('disabled', readonly);
		setColorState($colorInput, hasColor);
		$('#tag-details .clear-color').toggle(!readonly && hasColor);

		$('#tag-details .editdesc').val(tagdesc || '').prop('readonly', readonly);
		$('#tag-details .tag-details-title').text(tagname || t('meta_data', 'New tag'));
		$('#add_key').toggle(!readonly);
		$('#details-save').toggle(!readonly);
		$('#tag-details').addClass('open');

		$('#tagList tr').removeClass('active');
		if (tagid) {
			$('#tagList tr[data-id="' + tagid + '"]').addClass('active');

			ocsGet('tags/' + tagid + '/keys').done(function(data) {
				var keys = data.keys || [];
				if (keys.length > 0) {
					$('#emptysearch').hide();
					keys.forEach(function(key) {
						$('#meta_data_keys').append(newKeyEntry(key, readonly, false));
					});
				}
			});
		}
	}

	function closeSchemaEditor() {
		$('#tag-details').removeClass('open');
		$('#tagList tr').removeClass('active');
		currentTagId = null;
	}

	function saveSchema() {
		var newname = $('#tag-details input.edittag').val().trim();
		var newdesc = $('#tag-details textarea.editdesc').val().trim();
		var $colorInput = $('#tag-details input.editcolor');
		var newcolor = $colorInput.data('use-color') ? colorToHex($colorInput.val().trim()) : '';

		if (!newname) {
			$('#tag-details input.edittag').addClass('highlight').focus();
			setTimeout(function() { $('#tag-details input.edittag').removeClass('highlight'); }, 1500);
			return;
		}

		if (!currentTagId) {
			// ── Create new tag ────────────────────────────────────────────
			ocsPost('tags', { name: newname, color: newcolor }).done(function(data) {
				if (!data || !data.tag) return;
				var tag = data.tag;
				currentTagId = tag.id;

				// Save any keys that were added before the tag existed.
				saveKeys(tag.id, function() {
					tag.description = newdesc;
					tag.size = 0;
					addTagRow(tag);
					if (newdesc) {
						ocsPut('tags/' + tag.id, { description: newdesc });
					}
					updateTagsView();
					closeSchemaEditor();
				});
			});
			return;
		}

		// ── Update existing tag ───────────────────────────────────────────
		var tagid = currentTagId;
		ocsPut('tags/' + tagid, { name: newname, description: newdesc, color: newcolor }).done(function() {
			var hex = isHex(newcolor) ? newcolor : '';
			var labelStyle = hex ? 'background:#' + hex + ';border-color:#' + hex : '';
			var $tr = $('#tagList tr[data-id="' + tagid + '"]');
			$tr.find('.tagname').text(newname);
			$tr.find('.taginfo').attr('title', newdesc);
			$tr.find('a.action-meta_data').attr('style', labelStyle);
			if (hex) {
				$tr.find('a.action-meta_data').addClass('has-color');
			} else {
				$tr.find('a.action-meta_data').removeClass('has-color');
			}
			$tr.attr('data-name', newname).attr('data-color', newcolor).attr('data-desc', newdesc);
			$('#tag-details .tag-details-title').text(newname);
		});

		saveKeys(tagid, null);
	}

	function saveKeys(tagid, callback) {
		var pending = [];
		$('#meta_data_keys li').each(function() {
			var $li = $(this);
			var keyname = $li.find('input.edit').val().trim();
			if (!keyname) return;

			var type = $li.find('select.type').val() || '';
			var controlled = '';
			if (type === 'controlled') {
				var arr = $li.find('input.controlled_values').val().match(/(?=\S)[^,]+?(?=\s*(,|$))/g) || [];
				controlled = JSON.stringify(arr);
				type = '';
			}

			if ($li.hasClass('new')) {
				pending.push(
					ocsPost('tags/' + tagid + '/keys', { keyname: keyname, type: type, controlledvalues: controlled })
						.done(function(data) {
							if (data && data.key) {
								$li.removeClass('new').attr('id', data.key.id);
							}
						})
				);
			} else if ($li.hasClass('del')) {
				pending.push(ocsDelete('tags/' + tagid + '/keys/' + $li.attr('id')));
			} else if ($li.hasClass('alt')) {
				pending.push(ocsPut('tags/' + tagid + '/keys/' + $li.attr('id'),
					{ keyname: keyname, type: type, controlledvalues: controlled }));
			}
			$li.removeClass('new alt del');
		});

		if (callback) {
			$.when.apply($, pending).done(callback);
		}
	}

	function newKeyEntry(entry, readonly, isnew) {
		var optionSelect = '<select class="type" title="' + t('meta_data', 'Non-string types') + '">' +
			'<option value="">' + t('meta_data', 'Type') + '</option>' +
			'<option value="controlled">' + t('meta_data', 'Controlled') + '</option>' +
			'<option value="datetime">' + t('meta_data', 'Date & time') + '</option>' +
			'<option value="json">JSON</option></select>' +
			'<input placeholder="value1, value2, ..." class="controlled_values" type="text" value="" />';

		var $li;
		if (!entry) {
			$li = $('<li class="new">' +
				'<input class="edit" type="text" placeholder="' + t('meta_data', 'New key name') + '" value="">' +
				optionSelect +
				'<span class="deletekey">&#10006;</span>' +
				'</li>');
		} else {
			$li = $('<li ' + (isnew ? 'class="new"' : 'id="' + entry.id + '"') + '>' +
				'<input class="edit" type="text" value="' + _.escape(entry.name) + '"' + (readonly ? ' readonly' : '') + '>' +
				(readonly ? '' : optionSelect + '<span class="deletekey">&#10006;</span>') +
				'</li>');
			if (entry.allowed_values) {
				$li.find('select.type').val('controlled');
				try {
					$li.find('input.controlled_values').val(JSON.parse(entry.allowed_values).join(', '));
				} catch(e) {}
			} else if (entry.type) {
				$li.find('select.type').val(entry.type);
			}
			$li.find('input.controlled_values').toggle(
				$li.find('select.type').val() === 'controlled'
			);
		}

		$li.find('select.type').on('change', function() {
			$(this).closest('li').addClass('alt');
			$(this).closest('li').find('input.controlled_values')
				.toggle($(this).val() === 'controlled');
		});

		return $li;
	}

	// ── Bootstrap ─────────────────────────────────────────────────────────────

	$(document).ready(function() {
		updateTagsView();

		// New tag button
		$('#new-tag-btn').on('click', function() {
			openSchemaEditor(null, '', '', '', false);
		});

		// Open schema editor on tag name click
		$(document).on('click', '#tagList tr td.column-name a.action-meta_data', function(e) {
			e.preventDefault();
			var $tr = $(this).closest('tr');
			openSchemaEditor(
				$tr.attr('data-id'),
				$tr.attr('data-name'),
				$tr.attr('data-color') || '',
				$tr.find('.taginfo').attr('title') || '',
				false
			);
		});

		// Delete tag
		$(document).on('click', '#tagList tr td.column-actions .delete-tag', function(e) {
			e.stopPropagation();
			var tagid   = $(this).data('id');
			var tagname = $(this).data('name');
			OC.dialogs.confirm(
				t('meta_data', 'Delete tag "{name}" and all its metadata?', { name: tagname }),
				t('meta_data', 'Delete tag'),
				function(confirmed) {
					if (!confirmed) return;
					ocsDelete('tags/' + tagid).done(function() {
						$('#tagList tr[data-id="' + tagid + '"]').remove();
						if (currentTagId === tagid) closeSchemaEditor();
						updateTagsView();
					});
				},
				true
			);
		});

		// Details panel: save
		$('#details-save').on('click', function() {
			saveSchema();
		});

		// Details panel: cancel / close
		$('#details-cancel, .tag-details-close').on('click', function() {
			closeSchemaEditor();
		});

		// Colour: clear to no-color
		$('#tag-details').on('click', '.clear-color', function() {
			var $input = $('#tag-details .editcolor');
			setColorState($input, false);
			$(this).hide();
		});

		// Colour: picking a colour re-enables it
		$('#tag-details').on('change', '.editcolor', function() {
			setColorState($(this), true);
			$('#tag-details .clear-color').show();
		});

		// Add key
		$('#tag-details').on('click', '#add_key', function() {
			var $last = $('#meta_data_keys li:last-child');
			if ($last.length && $last.find('input.edit').val() === '') {
				$last.find('input.edit').addClass('highlight').focus();
				setTimeout(function() { $last.find('input.edit').removeClass('highlight'); }, 1500);
				return;
			}
			$('#emptysearch').hide();
			var $entry = newKeyEntry(null, false, true);
			$('#meta_data_keys').append($entry);
			$entry.find('input.edit').focus();
		});

		// Delete key row
		$('#tag-details').on('click', '#meta_data_keys li span.deletekey', function() {
			var $li = $(this).closest('li');
			if ($li.siblings(':visible').length === 0) {
				$('#emptysearch').show();
			}
			if ($li.hasClass('new')) {
				$li.remove();
			} else {
				$li.addClass('del').hide();
			}
		});

		// Mark row modified when edited
		$('#tag-details').on('input', '#meta_data_keys li input.edit', function() {
			var $li = $(this).closest('li');
			if (!$li.hasClass('new')) $li.addClass('alt');
		});
	});

})(jQuery);
