/**
 * Tags are managed in one place: the Metadata tab of the Files details panel.
 *
 * Nextcloud's own tag app adds two more ways — "Manage tags" in a file's action
 * menu and "Add tags" in the details panel's ⋯ menu. Besides being a second
 * place to look, they assign tags on THIS server only: for a file shared from
 * another server they tag the local copy, which the owner and everyone else
 * never see, while the Metadata tab writes to the owner's copy. So both are
 * switched off here (their tags still show; only these two editors go).
 *
 * @nextcloud/files keeps its registries on window._nc_files_scope.<version>:
 * fileActions and filesSidebarActions, Maps by id. The Files app asks each
 * action's enabled() whenever it builds a menu, so answering false there
 * removes the entry without touching anything already rendered. The tag app
 * may register after this script runs, hence the short retry.
 */
(function () {
	'use strict';
	var FILE_ACTION = 'systemtags:bulk';  // "Manage tags"
	var SIDEBAR_ACTION = 'systemtags';    // "Add tags"

	function scopes() {
		var root = window._nc_files_scope;
		return root ? Object.keys(root).map(function (k) { return root[k]; }) : [];
	}
	function disable(map, id) {
		var action = map && typeof map.get === 'function' ? map.get(id) : null;
		if (!action || action.__metaDataDisabled) { return !!action; }
		// An own property shadows a class getter as well as a plain field.
		Object.defineProperty(action, 'enabled', { value: function () { return false; }, configurable: true });
		Object.defineProperty(action, '__metaDataDisabled', { value: true });
		return true;
	}
	function run() {
		var done = 0;
		scopes().forEach(function (s) {
			if (disable(s.fileActions, FILE_ACTION)) { done++; }
			if (disable(s.filesSidebarActions, SIDEBAR_ACTION)) { done++; }
		});
		return done >= 2;
	}
	var tries = 0;
	(function again() {
		if (run() || ++tries > 50) { return; }
		setTimeout(again, 200);
	})();
})();
