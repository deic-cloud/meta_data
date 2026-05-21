/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Metadata Tags app — Nextcloud 30+ compatible JS
 *
 * Sidebar tab registration via OCA.Files.Sidebar.registerTab (NC30) or
 * window._nc_files_scope registry (NC31+).
 *
 * NC30 tab API: onMount(el, fileInfo, context) / update(fileInfo) / destroy()
 * NC31+ tab API: tagName (custom element) + onInit()
 */

(function(OCA, OC, $) {
	'use strict';

	var TAG_ICON_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">' +
		'<path d="M5.5 7A1.5 1.5 0 0 0 4 8.5 1.5 1.5 0 0 0 5.5 10 1.5 1.5 0 0 0 7 8.5' +
		' 1.5 1.5 0 0 0 5.5 7M11.86 2C11.31 2 10.77 2.21 10.36 2.61L3 10A2 2 0 0 0 3' +
		' 12.83L9.17 19C9.56 19.39 10.09 19.59 10.62 19.59S11.68 19.39 12.07 19L19.39' +
		' 11.68C19.79 11.28 20 10.74 20 10.2V4C20 2.9 19.1 2 18 2M18 10.2L10.62 17.59' +
		' 4.41 11.41 11.8 4H18Z"/></svg>';

	// ─── OCS API helpers ──────────────────────────────────────────────────────

	$.ajaxSetup({ headers: { 'OCS-APIREQUEST': 'true', 'requesttoken': OC.requestToken } });

	function apiUrl(path) {
		return OC.linkToOCS('apps/meta_data/api/v1', 2) + path;
	}

	function ocsGet(endpoint, data) {
		return $.ajax({ url: apiUrl(endpoint), type: 'GET', data: data || {}, dataType: 'json' })
			.then(function(r) { return r.ocs.data; });
	}

	function ocsPost(endpoint, data) {
		return $.ajax({ url: apiUrl(endpoint), type: 'POST', data: data || {}, dataType: 'json' })
			.then(function(r) { return r.ocs.data; });
	}

	function ocsPut(endpoint, data) {
		return $.ajax({ url: apiUrl(endpoint), type: 'PUT', data: data || {}, dataType: 'json' })
			.then(function(r) { return r.ocs.data; });
	}

	function ocsDelete(endpoint, data) {
		return $.ajax({ url: apiUrl(endpoint), type: 'DELETE', data: data || {}, dataType: 'json' })
			.then(function(r) { return r.ocs.data; });
	}

	// ─── Color helpers ────────────────────────────────────────────────────────

	function colorClass(color) {
		if (!color) return 'default';
		// Legacy color-N CSS class names
		var legacyMap = {'color-1': 'default', 'color-2': 'primary', 'color-3': 'success',
			'color-4': 'info', 'color-5': 'warning', 'color-6': 'danger'};
		if (legacyMap[color]) return legacyMap[color];
		return 'default';
	}

	// Returns inline style string for a hex color (6 hex chars without #), empty string otherwise.
	function colorStyle(color) {
		if (color && /^[0-9a-fA-F]{6}$/.test(color)) {
			return 'background-color:#' + color + ';color:#fff';
		}
		return '';
	}

	// ─── Extract file ID from NC30 FileInfo or NC31+ INode ───────────────────

	function getFileId(fileInfo) {
		if (!fileInfo) return null;
		if (fileInfo.fileid) return fileInfo.fileid;
		if (fileInfo.id)     return fileInfo.id;
		if (typeof fileInfo.get === 'function') return fileInfo.get('id');
		return null;
	}

	// ─── Notify NC files app of a tag assignment change ──────────────────────

	function notifyFilesApp(el, tagName, added) {
		if (!window._nc_event_bus || !tagName) return;
		var node = el && el._node;
		if (!node) return;
		if (!node.attributes) node.attributes = {};

		// NC stores system tags at node.attributes['system-tags']['system-tag']
		// (mirrors the WebDAV property structure; see systemtags/src/utils.ts)
		var raw = node.attributes['system-tags'] && node.attributes['system-tags']['system-tag'];
		var current = raw === undefined ? [] :
			[].concat(raw).map(function(t) { return typeof t === 'string' ? t : (t.text || ''); });

		if (added) {
			if (current.indexOf(tagName) === -1) current.push(tagName);
		} else {
			current = current.filter(function(n) { return n !== tagName; });
		}

		node.attributes['system-tags'] = { 'system-tag': current };
		window._nc_event_bus.emit('files:node:updated', node);
		window._nc_event_bus.emit('systemtags:node:updated', node);
	}

	// ─── Shared rendering functions ───────────────────────────────────────────

	function loadAndRenderPanel(el, fileId) {
		if (!fileId) return;
		$(el).html('<div style="padding:12px">' + t('meta_data', 'Loading\u2026') + '</div>');

		$.when(
			ocsGet('tags'),
			ocsPost('filetags', { fileids: [fileId] })
		).done(function(tagsData, fileTagsData) {
			var allTags   = (tagsData    && tagsData.tags)    ? tagsData.tags       : [];
			var fileTags  = {};
			var filesList = (fileTagsData && fileTagsData.files) ? fileTagsData.files : [];
			for (var i = 0; i < filesList.length; i++) {
				if (Number(filesList[i].id) === Number(fileId)) {
					(filesList[i].tags || []).forEach(function(tag) { fileTags[tag.id] = true; });
					break;
				}
			}
			renderTagList(el, fileId, allTags, fileTags);
		}).fail(function() {
			$(el).html('<div style="padding:12px">' + t('meta_data', 'Error loading metadata') + '</div>');
		});
	}

	function renderTagList(el, fileId, allTags, fileTags) {
		var $container = $('<div class="metadata-panel" style="padding:8px"></div>');

		allTags.forEach(function(tag) {
			var assigned = !!fileTags[tag.id];
			var $row = $('<div class="metadata-tag-row" style="display:flex;align-items:center;margin:4px 0"></div>');
			var $cb  = $('<input type="checkbox" class="metadata-tag-check" style="margin-right:6px">');
			$cb.prop('checked', assigned).data('tagid', tag.id).data('fileid', fileId);
			var extraStyle = colorStyle(tag.color);
			var lblStyle = 'cursor:pointer' + (extraStyle ? ';' + extraStyle : '');
			var $lbl = $('<label class="label outline label-' + colorClass(tag.color) + '" style="' + lblStyle + '">' +
				'<i class="icon-tag" style="display:inline-block;margin-right:3px"></i>' +
				_.escape(tag.name) + '</label>');
			$row.append($cb, $lbl);

			if (assigned) {
				var $btn = $('<button class="metadata-keys-btn" style="margin-left:auto" title="' +
					t('meta_data', 'Edit metadata values') + '">&#9998;</button>');
				$btn.data('tagid', tag.id).data('fileid', fileId).data('tagname', tag.name);
				$row.append($btn);
			}
			$container.append($row);
		});

		$(el).empty().append($container);

		$container.on('change', '.metadata-tag-check', function() {
			var tagId = $(this).data('tagid');
			var fid   = $(this).data('fileid');
			var tagName = null;
			for (var i = 0; i < allTags.length; i++) {
				if (allTags[i].id === tagId) { tagName = allTags[i].name; break; }
			}
			if ($(this).is(':checked')) {
				ocsPut('filetags', { fileid: fid, tagid: tagId }).done(function() {
					notifyFilesApp(el, tagName, true);
					loadAndRenderPanel(el, fileId);
				});
			} else {
				ocsDelete('filetags', { fileid: fid, tagid: tagId }).done(function() {
					notifyFilesApp(el, tagName, false);
					loadAndRenderPanel(el, fileId);
				});
			}
		});

		$container.on('click', '.metadata-keys-btn', function() {
			openKeyEditor($(this).data('fileid'), $(this).data('tagid'), $(this).data('tagname'));
		});

	}

	function openKeyEditor(fileId, tagId, tagName) {
		$.when(
			ocsGet('tags/' + tagId + '/keys'),
			ocsGet('filemeta', { fileid: fileId, tagid: tagId })
		).done(function(keysData, fileKeysData) {
			var keys     = (keysData    && keysData.keys)  ? keysData.keys  : [];
			var fileVals = {};
			((fileKeysData && fileKeysData.data) || []).forEach(function(kv) { fileVals[kv.keyid] = kv.value; });
			showKeyDialog(fileId, tagId, tagName, keys, fileVals);
		});
	}

	function showKeyDialog(fileId, tagId, tagName, keys, fileVals) {
		var $dialog = $('<div class="metadata-key-dialog"></div>');
		$dialog.append('<h3 style="margin:0 0 8px">' + _.escape(tagName) + '</h3>');
		var $list = $('<ul class="metadata-key-list" style="list-style:none;padding:0;margin:0"></ul>');

		keys.forEach(function(key) {
			var $li = $('<li style="margin:4px 0;display:flex;gap:6px;align-items:center"></li>');
			$li.append('<span style="flex:0 0 120px;overflow:hidden;text-overflow:ellipsis">' +
				_.escape(key.name) + '</span>');
			var $input;
			if (key.allowed_values) {
				var allowed = [];
				try { allowed = JSON.parse(key.allowed_values); } catch (e) {}
				$input = $('<select class="key-value" style="flex:1"></select>').data('keyid', key.id);
				$input.append('<option value=""></option>');
				allowed.forEach(function(v) {
					$('<option></option>').val(v).text(v)
						.prop('selected', fileVals[key.id] === v)
						.appendTo($input);
				});
			} else {
				$input = $('<input type="text" class="key-value" style="flex:1">').data('keyid', key.id).val(fileVals[key.id] || '');
			}
			$li.append($input);
			$list.append($li);
		});

		var $newKeyRow = $('<li style="margin-top:8px;display:flex;gap:6px"></li>');
		$newKeyRow.append(
			'<input type="text" class="new-key-name" placeholder="' + t('meta_data', 'New key name') + '" style="flex:1">',
			'<button class="new-key-btn">+</button>'
		);
		$list.append($newKeyRow);
		$dialog.append($list);

		$dialog.dialog({
			title: t('meta_data', 'Metadata values'),
			modal: true,
			width: 480,
			buttons: [
				{
					text: t('meta_data', 'Save'),
					click: function() {
						$list.find('li:not(:last-child)').each(function() {
							var $v    = $(this).find('.key-value');
							var keyId = $v.data('keyid');
							var val   = $v.val();
							if (keyId !== undefined) {
								ocsPost('filemeta', { fileid: fileId, tagid: tagId, keyid: keyId, value: val });
							}
						});
						$(this).dialog('close');
					},
				},
				{ text: t('meta_data', 'Cancel'), click: function() { $(this).dialog('close'); } },
			],
			close: function() { $(this).dialog('destroy').remove(); },
		});

		$dialog.on('click', '.new-key-btn', function() {
			var name = $dialog.find('.new-key-name').val().trim();
			if (!name) return;
			ocsPost('tags/' + tagId + '/keys', { keyname: name }).done(function(data) {
				if (data && data.key) {
					var $li = $('<li style="margin:4px 0;display:flex;gap:6px;align-items:center"></li>');
					$li.append(
						'<span style="flex:0 0 120px">' + _.escape(data.key.name) + '</span>',
						$('<input type="text" class="key-value" style="flex:1">').data('keyid', data.key.id)
					);
					$newKeyRow.before($li);
					$dialog.find('.new-key-name').val('');
				}
			});
		});
	}

	// ─── Custom element for NC31+ sidebar ────────────────────────────────────
	//
	// Vue 3 sidebar passes `node` and `active` as DOM properties.

	var SIDEBAR_TAG = 'meta-data-sidebar-tab';

	class MetaDataSidebarElement extends HTMLElement {
		constructor() {
			super();
			this._active        = false;
			this._node          = null;
			this._currentFileId = null;
			this._eventHandlers = null;
		}

		set active(val) {
			var wasActive = this._active;
			this._active = !!val;
			if (this._active && !wasActive) { this._currentFileId = null; }
			this._maybeRender();
		}
		set node(val)   { this._node = val; this._currentFileId = null; this._maybeRender(); }
		set folder(val) {}
		set view(val)   {}

		connectedCallback() {
			this._maybeRender();
			this._subscribeEvents();
		}

		disconnectedCallback() {
			this._unsubscribeEvents();
		}

		_subscribeEvents() {
			if (!window._nc_event_bus || this._eventHandlers) return;
			var self = this;

			var onNodeUpdated = function(node) {
				var nodeId = node && (node.fileid || node.id);
				if (nodeId && Number(nodeId) === Number(self._currentFileId)) {
					loadAndRenderPanel(self, self._currentFileId);
				}
			};
			var onTagChanged = function() {
				if (self._currentFileId) loadAndRenderPanel(self, self._currentFileId);
			};

			window._nc_event_bus.subscribe('systemtags:node:updated', onNodeUpdated);
			window._nc_event_bus.subscribe('systemtags:tag:created', onTagChanged);
			window._nc_event_bus.subscribe('systemtags:tag:updated', onTagChanged);
			window._nc_event_bus.subscribe('systemtags:tag:deleted', onTagChanged);

			this._eventHandlers = { onNodeUpdated: onNodeUpdated, onTagChanged: onTagChanged };
		}

		_unsubscribeEvents() {
			if (!window._nc_event_bus || !this._eventHandlers) return;
			var h = this._eventHandlers;
			window._nc_event_bus.unsubscribe('systemtags:node:updated', h.onNodeUpdated);
			window._nc_event_bus.unsubscribe('systemtags:tag:created', h.onTagChanged);
			window._nc_event_bus.unsubscribe('systemtags:tag:updated', h.onTagChanged);
			window._nc_event_bus.unsubscribe('systemtags:tag:deleted', h.onTagChanged);
			this._eventHandlers = null;
		}

		_maybeRender() {
			if (!this._active || !this._node || !this.isConnected) return;
			var fileId = getFileId(this._node);
			if (!fileId || fileId === this._currentFileId) return;
			this._currentFileId = fileId;
			loadAndRenderPanel(this, fileId);
		}
	}

	function defineCustomElement() {
		if (!window.customElements.get(SIDEBAR_TAG)) {
			window.customElements.define(SIDEBAR_TAG, MetaDataSidebarElement);
		}
	}

	// ─── Tab definition (supports both NC30 and NC31+ APIs) ──────────────────

	function makeTabDef() {
		var label          = t('meta_data', 'Metadata');
		var mountedEl      = null;
		var mountedFileId  = null;

		return {
			id:            'metadata',
			name:          label,   // NcAppSidebarTab prop (NC30 sort key)
			displayName:   label,   // @nextcloud/files ISidebarTab (NC31+)
			iconSvgInline: TAG_ICON_SVG,
			order:         50,
			tagName:       SIDEBAR_TAG,

			enabled: function() { return true; },

			// NC30 API
			mount: function(el, fileInfo) {  // SidebarTab.vue computed wraps this as onMount
				mountedEl = el;
				mountedFileId = getFileId(fileInfo);
				loadAndRenderPanel(el, mountedFileId);
			},
			onMount: function(el, fileInfo) { // alias: some code paths call onMount directly
				mountedEl = el;
				mountedFileId = getFileId(fileInfo);
				loadAndRenderPanel(el, mountedFileId);
			},
			update: function(fileInfo) {
				mountedFileId = getFileId(fileInfo);
				if (mountedEl) loadAndRenderPanel(mountedEl, mountedFileId);
			},
			destroy: function() {
				if (mountedEl) $(mountedEl).empty();
				mountedEl = null;
			},

			// NC30 compat stubs — Sidebar.vue calls these when toggling active state
			setIsActive: function(active) { if (active && mountedEl) loadAndRenderPanel(mountedEl, mountedFileId); },
			setActive: function() {},
			setFileInfo: function(fileInfo) {
				if (mountedEl) loadAndRenderPanel(mountedEl, getFileId(fileInfo));
			},

			// NC31+ API
			onInit: function() {
				return Promise.resolve(defineCustomElement());
			},
		};
	}

	// ─── Register sidebar tab ─────────────────────────────────────────────────

	var _tabRegistered = false;

	function tryRegisterSidebarTab(attemptsLeft) {
		if (_tabRegistered) return;

		// NC30: OCA.Files.Sidebar.registerTab()
		if (OCA.Files && OCA.Files.Sidebar && typeof OCA.Files.Sidebar.registerTab === 'function') {
			defineCustomElement();
			OCA.Files.Sidebar.registerTab(makeTabDef());
			_tabRegistered = true;
			return;
		}

		// NC31+: write directly to @nextcloud/files window registry
		if (window._nc_files_scope) {
			var keys = Object.keys(window._nc_files_scope);
			for (var i = 0; i < keys.length; i++) {
				var candidate = window._nc_files_scope[keys[i]];
				if (candidate && typeof candidate === 'object') {
					defineCustomElement();
					var tabs = new Map(candidate.filesSidebarTabs || []);
					tabs.set('metadata', makeTabDef());
					candidate.filesSidebarTabs = tabs;
					_tabRegistered = true;
					return;
				}
			}
		}

		if (attemptsLeft > 0) {
			setTimeout(function() { tryRegisterSidebarTab(attemptsLeft - 1); }, 250);
		}
	}

	// ─── Bootstrap ───────────────────────────────────────────────────────────

	defineCustomElement();

	// Register immediately when script loads — same pattern as Sharing/Activity/etc.
	// This ensures the tab is present before Vue renders the sidebar on opendetails=true.
	tryRegisterSidebarTab(40);

	$(document).ready(function() {
		tryRegisterSidebarTab(40);
	});

})(window.OCA = window.OCA || {}, OC, jQuery);
