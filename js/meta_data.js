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

	var PENCIL_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">' +
		'<path d="M20.71 7.04c.39-.39.39-1.02 0-1.41l-2.34-2.34c-.39-.39-1.02-.39-1.41 0l-1.84 ' +
		'1.83 3.75 3.75M3 17.25V21h3.75L17.81 9.93l-3.75-3.75L3 17.25Z"/>' +
		'</svg>';

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

	/** Show the server's reason for a refused request (OCS message), else $fallback. */
	function showFailure(xhr, fallback) {
		var msg = fallback;
		try {
			var j = xhr && xhr.responseJSON;
			if (j && j.ocs && j.ocs.data && j.ocs.data.message) { msg = j.ocs.data.message; }
		} catch (e) {}
		if (window.OC && OC.Notification && OC.Notification.showTemporary) { OC.Notification.showTemporary(msg); }
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
			[].concat(raw).map(function(t) { return typeof t === 'string' ? t : (t.text || ''); })
			.filter(function(t) { return t !== ''; });

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
				escapeHtml(tag.name) + '</label>');
			$row.append($cb, $lbl);

			if (assigned) {
				var $btn = $('<button class="metadata-keys-btn" style="margin-left:auto" title="' +
					t('meta_data', 'Edit metadata values') + '">' + PENCIL_SVG + '</button>');
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
			// A refusal (e.g. a read-only share from another server) comes back
			// with its reason; show it and re-render so the box shows the truth.
			var failed = function(xhr) {
				showFailure(xhr, t('meta_data', 'The tag could not be changed'));
				loadAndRenderPanel(el, fileId);
			};
			if ($(this).is(':checked')) {
				ocsPut('filetags', { fileid: fid, tagid: tagId }).done(function() {
					notifyFilesApp(el, tagName, true);
					loadAndRenderPanel(el, fileId);
				}).fail(failed);
			} else {
				ocsDelete('filetags', { fileid: fid, tagid: tagId }).done(function() {
					notifyFilesApp(el, tagName, false);
					loadAndRenderPanel(el, fileId);
				}).fail(failed);
			}
		});

		$container.on('click', '.metadata-keys-btn', function() {
			openKeyEditor($(this).data('fileid'), $(this).data('tagid'), $(this).data('tagname'));
		});

	}

	function openKeyEditor(fileId, tagId, tagName) {
		$.when(
			ocsGet('tags/' + tagId + '/keys'),
			ocsGet('filemeta', { fileid: fileId, tagid: tagId }),
			ocsGet('tags/' + tagId)
		).done(function(keysData, fileKeysData, tagData) {
			var keys     = (keysData    && keysData.keys)  ? keysData.keys  : [];
			var fileVals = {};
			((fileKeysData && fileKeysData.data) || []).forEach(function(kv) { fileVals[kv.keyid] = kv.value; });
			// Adding a field changes the schema for everyone → owner/admin only.
			var editable = !!(tagData && tagData.tag && tagData.tag.editable);
			showKeyDialog(fileId, tagId, tagName, keys, fileVals, editable);
		});
	}

	function escapeHtml(str) {
		return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}

	function showKeyDialog(fileId, tagId, tagName, keys, fileVals, editable) {
		var dialog = document.createElement('dialog');
		dialog.style.cssText = 'width:480px;padding:20px;border:1px solid #ccc;border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.3)';

		var $list = $('<ul class="metadata-key-list" style="list-style:none;padding:0;margin:0"></ul>');

		keys.forEach(function(key) {
			var $li = $('<li style="margin:4px 0;display:flex;gap:6px;align-items:center"></li>');
			$li.append('<span style="flex:0 0 120px;overflow:hidden;text-overflow:ellipsis">' +
				escapeHtml(key.name) + '</span>');
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
			} else if (key.type === 'datetime') {
				// A native datetime-local yields no value unless BOTH date and time
				// are filled, so a date-only pick saved nothing. Use a date picker
				// plus an optional time, combined into a hidden .key-value
				// (date alone -> 00:00) so the save loop is unchanged.
				var raw = fileVals[key.id] || '';
				var dpart = /^\d{4}-\d{2}-\d{2}/.test(raw) ? raw.slice(0, 10) : '';
				var tpart = raw.slice(11, 16) || '';
				$input = $('<span style="flex:1;display:inline-flex;gap:4px;align-items:center"></span>');
				var $hidden = $('<input type="hidden" class="key-value">').data('keyid', key.id).val(raw);
				var $d = $('<input type="date" style="width:auto">').val(dpart);
				var $t = $('<input type="time" style="width:auto;max-width:90px" title="' + t('meta_data', 'Time (optional)') + '">').val(tpart);
				var syncDt = function() {
					var v = $d.val() ? ($d.val() + 'T' + ($t.val() || '00:00')) : '';
					$hidden.val(v);
				};
				$d.on('change', syncDt);
				$t.on('change', syncDt);
				$input.append($hidden, $d, $t);
			} else {
				$input = $('<input type="text" class="key-value" style="flex:1">').data('keyid', key.id).val(fileVals[key.id] || '');
			}
			$li.append($input);
			$list.append($li);
		});

		var $newKeyRow = $('<li style="margin-top:8px;display:flex;gap:6px"></li>');
		if (editable) {
			$newKeyRow.append(
				'<input type="text" class="new-key-name" placeholder="' + t('meta_data', 'New key name') + '" style="flex:1">',
				'<button class="new-key-btn">+</button>'
			);
		}
		$list.append($newKeyRow);

		var $buttons = $('<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px"></div>');
		var $save   = $('<button>' + t('meta_data', 'Save')   + '</button>');
		var $cancel = $('<button>' + t('meta_data', 'Cancel') + '</button>');
		$buttons.append($cancel, $save);

		$(dialog).append(
			'<h3 style="margin:0 0 12px">' + escapeHtml(tagName) + '</h3>',
			$list,
			$buttons
		);
		document.body.appendChild(dialog);
		dialog.showModal();

		$save.on('click', function() {
			$list.find('li:not(:last-child)').each(function() {
				var $v    = $(this).find('.key-value');
				var keyId = $v.data('keyid');
				var val   = $v.val();
				if (keyId !== undefined) {
					ocsPost('filemeta', { fileid: fileId, tagid: tagId, keyid: keyId, value: val })
						.fail(function(xhr) { showFailure(xhr, t('meta_data', 'The value could not be saved')); });
				}
			});
			dialog.close();
			dialog.remove();
		});

		$cancel.on('click', function() { dialog.close(); dialog.remove(); });
		$(dialog).on('close', function() { dialog.remove(); });

		$(dialog).on('click', '.new-key-btn', function() {
			var name = $(dialog).find('.new-key-name').val().trim();
			if (!name) return;
			ocsPost('tags/' + tagId + '/keys', { keyname: name }).done(function(data) {
				if (data && data.key) {
					var $li = $('<li style="margin:4px 0;display:flex;gap:6px;align-items:center"></li>');
					$li.append(
						'<span style="flex:0 0 120px">' + escapeHtml(data.key.name) + '</span>',
						$('<input type="text" class="key-value" style="flex:1">').data('keyid', data.key.id)
					);
					$newKeyRow.before($li);
					$(dialog).find('.new-key-name').val('');
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

	// ─── Pre-load tags for all files in the current folder view ─────────────
	//
	// When the files list loads a directory, batch-fetch tags for every visible
	// file and inject any remote (cross-silo) tags into the node's system-tags
	// attribute so NC's built-in tag chips appear in the files list.

	function preloadFolderTags(nodes) {
		if (!nodes || !nodes.length || !window._nc_event_bus) return;

		var fileids = [];
		var nodeById = {};
		nodes.forEach(function(node) {
			var id = node.fileid || node.id;
			if (id) {
				fileids.push(id);
				nodeById[String(id)] = node;
			}
		});
		if (!fileids.length) return;

		ocsPost('filetags', { fileids: fileids }).done(function(data) {
			var files = (data && data.files) ? data.files : [];
			files.forEach(function(f) {
				if (!f.tags || !f.tags.length) return;
				var node = nodeById[String(f.id)];
				if (!node) return;

				var raw = node.attributes && node.attributes['system-tags'] &&
					node.attributes['system-tags']['system-tag'];
				var existing = raw === undefined ? [] :
					[].concat(raw).map(function(t) { return typeof t === 'string' ? t : (t.text || ''); })
					.filter(function(t) { return t !== ''; });

				var changed = false;
				f.tags.forEach(function(tag) {
					if (existing.indexOf(tag.name) === -1) {
						existing.push(tag.name);
						changed = true;
					}
				});

				if (changed) {
					if (!node.attributes) node.attributes = {};
					node.attributes['system-tags'] = { 'system-tag': existing };
					window._nc_event_bus.emit('systemtags:node:updated', node);
				}
			});
		});
	}

	// ─── Tags for several files at once ──────────────────────────────────────
	//
	// The details panel (and so the Metadata tab) always shows ONE file. With
	// several files selected, the Files toolbar offers "Tags": the same tag list,
	// applied to all of them. A box is ticked when every selected file has the
	// tag, half-ticked when some do; clicking it gives the tag to all (or, when
	// all have it, removes it from all). Each change goes through the same API as
	// the tab, so files shared from another server are tagged on the owner's
	// copy — and a refusal (read-only share) is reported per file.

	var TAGS_MULTIPLE_SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M6.5 10C7.3 10 8 9.3 8 8.5S7.3 7 6.5 7 5 7.7 5 8.5 5.7 10 6.5 10M9 6L16 13L11 18L4 11V6H9M9 4H4C2.9 4 2 4.9 2 6V11C2 11.6 2.2 12.1 2.6 12.4L9.6 19.4C9.9 19.8 10.4 20 11 20S12.1 19.8 12.4 19.4L17.4 14.4C17.8 14 18 13.5 18 13C18 12.4 17.8 11.9 17.4 11.6L10.4 4.6C10.1 4.2 9.6 4 9 4M13.5 5.7L14.5 4.7L21.4 11.6C21.8 12 22 12.5 22 13S21.8 14.1 21.4 14.4L16 19.8L15 18.8L20.7 13L13.5 5.7Z"/></svg>';

	function setNodeTag(node, tagName, added) {
		if (!node || !tagName) return;
		if (!node.attributes) node.attributes = {};
		var raw = node.attributes['system-tags'] && node.attributes['system-tags']['system-tag'];
		var current = raw === undefined ? [] :
			[].concat(raw).map(function(tg) { return typeof tg === 'string' ? tg : (tg.text || ''); })
			.filter(function(tg) { return tg !== ''; });
		if (added) {
			if (current.indexOf(tagName) === -1) current.push(tagName);
		} else {
			current = current.filter(function(n) { return n !== tagName; });
		}
		node.attributes['system-tags'] = { 'system-tag': current };
		if (window._nc_event_bus) {
			window._nc_event_bus.emit('files:node:updated', node);
			window._nc_event_bus.emit('systemtags:node:updated', node);
		}
	}

	function openBulkTagDialog(nodes) {
		var ids = nodes.map(function(n) { return n.fileid; }).filter(Boolean);
		var dialog = document.createElement('dialog');
		dialog.className = 'metadata-bulk-dialog';
		dialog.style.cssText = 'position:fixed;inset:0;margin:auto;height:fit-content;width:420px;max-width:90vw;padding:20px;border:1px solid var(--color-border,#ccc);border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.3);background:var(--color-main-background);color:var(--color-main-text)';
		var $d = $(dialog);
		$d.append($('<h3 style="margin:0 0 4px"></h3>').text(t('meta_data', 'Tags')));
		$d.append($('<p style="margin:0 0 10px;color:var(--color-text-maxcontrast)"></p>')
			.text(n('meta_data', 'For the selected file', 'For the %n selected files', ids.length)));
		var $list = $('<div class="metadata-bulk-list" style="max-height:50vh;overflow:auto"></div>')
			.text(t('meta_data', 'Loading…'));
		var $status = $('<p class="metadata-bulk-status" style="margin:10px 0 0;min-height:1.2em;color:var(--color-text-maxcontrast)"></p>');
		var $close = $('<button type="button" class="primary"></button>').text(t('meta_data', 'Close'));
		$d.append($list, $status, $('<div style="display:flex;justify-content:flex-end;margin-top:12px"></div>').append($close));
		document.body.appendChild(dialog);
		dialog.showModal();
		$close.on('click', function() { dialog.close(); });
		$d.on('close', function() { dialog.remove(); });

		var allTags = [];
		var has = {};   // tagId → {fileid: true}
		function render() {
			$list.empty();
			allTags.forEach(function(tag) {
				var count = Object.keys(has[tag.id] || {}).length;
				var $row = $('<label class="metadata-tag-row" style="display:flex;align-items:center;gap:6px;margin:4px 0;cursor:pointer"></label>');
				var $cb = $('<input type="checkbox" style="margin:0">');
				$cb.prop('checked', count === ids.length && count > 0);
				$cb.prop('indeterminate', count > 0 && count < ids.length);
				var extraStyle = colorStyle(tag.color);
				var $chip = $('<span class="label outline label-' + colorClass(tag.color) + '" style="display:inline-block;width:auto;flex:0 0 auto' + (extraStyle ? ';' + extraStyle : '') + '"></span>')
					.append('<i class="icon-tag" style="display:inline-block;margin-right:3px"></i>')
					.append(document.createTextNode(tag.name));
				$row.append($cb, $chip);
				if (count > 0 && count < ids.length) {
					$row.append($('<span style="color:var(--color-text-maxcontrast);font-size:.9em"></span>')
						.text(t('meta_data', '{n} of {total}').replace('{n}', count).replace('{total}', ids.length)));
				}
				$cb.on('change', function(e) {
					e.preventDefault();
					apply(tag, !(count === ids.length));
				});
				$list.append($row);
			});
		}
		function apply(tag, add) {
			$list.find('input').prop('disabled', true);
			$status.text(t('meta_data', 'Saving…'));
			var todo = nodes.filter(function(node) {
				var hasIt = !!(has[tag.id] || {})[node.fileid];
				return add ? !hasIt : hasIt;
			});
			var failures = [];
			var chain = $.Deferred().resolve().promise();
			todo.forEach(function(node) {
				chain = chain.then(function() {
					var req = add ? ocsPut('filetags', { fileid: node.fileid, tagid: tag.id })
						: ocsDelete('filetags', { fileid: node.fileid, tagid: tag.id });
					return req.then(function() {
						has[tag.id] = has[tag.id] || {};
						if (add) { has[tag.id][node.fileid] = true; } else { delete has[tag.id][node.fileid]; }
						setNodeTag(node, tag.name, add);
					}, function(xhr) {
						var msg = '';
						try { msg = xhr.responseJSON.ocs.data.message || ''; } catch (err) {}
						failures.push((node.basename || node.fileid) + (msg ? ': ' + msg : ''));
						return $.Deferred().resolve().promise();   // carry on with the others
					});
				});
			});
			chain.always(function() {
				$status.text(failures.length
					? n('meta_data', '%n file could not be changed', '%n files could not be changed', failures.length) + ' - ' + failures.join('; ')
					: t('meta_data', 'Saved'));
				render();
			});
		}

		$.when(ocsGet('tags'), ocsPost('filetags', { fileids: ids })).done(function(tagsData, fileTagsData) {
			allTags = (tagsData && tagsData.tags) ? tagsData.tags : [];
			((fileTagsData && fileTagsData.files) || []).forEach(function(f) {
				(f.tags || []).forEach(function(tag) {
					has[tag.id] = has[tag.id] || {};
					has[tag.id][Number(f.id)] = true;   // keys compare as strings, like node.fileid
				});
			});
			render();
		}).fail(function() {
			$list.text(t('meta_data', 'Error loading metadata'));
		});
	}

	var BULK_ACTION = {
		id: 'meta_data:bulk-tags',
		displayName: function() { return t('meta_data', 'Tags'); },
		iconSvgInline: function() { return TAGS_MULTIPLE_SVG; },
		order: 50,
		// Several files only: for one file, the Metadata tab in the details panel.
		enabled: function(ctx) {
			var nodes = (ctx && ctx.nodes) || [];
			return nodes.length > 1 && nodes.every(function(node) { return !!node.fileid; });
		},
		exec: function(ctx) {
			openBulkTagDialog((ctx && ctx.nodes) || []);
			return Promise.resolve(null);
		},
		execBatch: function(ctx) {
			var nodes = (ctx && ctx.nodes) || [];
			openBulkTagDialog(nodes);
			return Promise.resolve(nodes.map(function() { return null; }));
		},
	};

	var _bulkRegistered = false;
	function tryRegisterBulkAction(attemptsLeft) {
		if (_bulkRegistered) return;
		var root = window._nc_files_scope;
		var keys = root ? Object.keys(root) : [];
		for (var i = 0; i < keys.length; i++) {
			var scope = root[keys[i]];
			if (!scope || typeof scope !== 'object') continue;
			scope.fileActions = scope.fileActions || new Map();
			if (!scope.fileActions.has(BULK_ACTION.id)) {
				scope.fileActions.set(BULK_ACTION.id, BULK_ACTION);
				// The Files app re-reads the actions on this event.
				if (scope.registry && typeof scope.registry.dispatchEvent === 'function') {
					scope.registry.dispatchEvent(new CustomEvent('register:action', { detail: BULK_ACTION }));
				}
			}
			_bulkRegistered = true;
		}
		if (!_bulkRegistered && attemptsLeft > 0) {
			setTimeout(function() { tryRegisterBulkAction(attemptsLeft - 1); }, 250);
		}
	}

	// ─── Bootstrap ───────────────────────────────────────────────────────────

	defineCustomElement();

	// Register immediately when script loads — same pattern as Sharing/Activity/etc.
	// This ensures the tab is present before Vue renders the sidebar on opendetails=true.
	tryRegisterSidebarTab(40);
	tryRegisterBulkAction(40);

	$(document).ready(function() {
		tryRegisterSidebarTab(40);
		tryRegisterBulkAction(40);
		if (window._nc_event_bus) {
			window._nc_event_bus.subscribe('files:list:updated', function(event) {
				preloadFolderTags(event && event.contents);
			});
		} else {
			// Event bus may not be ready at DOMContentLoaded; retry briefly.
			var attempts = 0;
			var iv = setInterval(function() {
				if (window._nc_event_bus) {
					clearInterval(iv);
					window._nc_event_bus.subscribe('files:list:updated', function(event) {
						preloadFolderTags(event && event.contents);
					});
				} else if (++attempts > 20) {
					clearInterval(iv);
				}
			}, 250);
		}
	});

})(window.OCA = window.OCA || {}, OC, jQuery);
