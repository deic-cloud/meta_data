<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index',              'url' => '/',                      'verb' => 'GET'],
		['name' => 'internal#syncTag',           'url' => '/internal/tags/sync',           'verb' => 'POST'],
		['name' => 'internal#deleteTag',         'url' => '/internal/tags/delete',         'verb' => 'POST'],
		['name' => 'internal#getFileTagsByToken', 'url' => '/internal/filetags-by-token',  'verb' => 'POST'],
	],
	'ocs' => [
		// Tags
		['name' => 'api#getTags',     'url' => '/api/v1/tags',          'verb' => 'GET'],
		['name' => 'api#newTag',      'url' => '/api/v1/tags',          'verb' => 'POST'],
		['name' => 'api#updateTag',   'url' => '/api/v1/tags/{tagId}',  'verb' => 'PUT'],
		['name' => 'api#deleteTag',   'url' => '/api/v1/tags/{tagId}',  'verb' => 'DELETE'],
		['name' => 'api#getTagById',  'url' => '/api/v1/tags/{tagId}',  'verb' => 'GET'],

		// Keys
		['name' => 'api#getKeys',     'url' => '/api/v1/tags/{tagId}/keys',          'verb' => 'GET'],
		['name' => 'api#newKey',      'url' => '/api/v1/tags/{tagId}/keys',          'verb' => 'POST'],
		['name' => 'api#updateKey',   'url' => '/api/v1/tags/{tagId}/keys/{keyId}',  'verb' => 'PUT'],
		['name' => 'api#deleteKey',   'url' => '/api/v1/tags/{tagId}/keys/{keyId}',  'verb' => 'DELETE'],

		// File-tag associations
		['name' => 'api#getFileTags',    'url' => '/api/v1/filetags',           'verb' => 'POST'],
		['name' => 'api#addFileTag',     'url' => '/api/v1/filetags',           'verb' => 'PUT'],
		['name' => 'api#removeFileTag',  'url' => '/api/v1/filetags',           'verb' => 'DELETE'],
		['name' => 'api#getTaggedFiles', 'url' => '/api/v1/tags/{tagId}/files', 'verb' => 'GET'],
		['name' => 'api#searchFiles',    'url' => '/api/v1/searchfiles',        'verb' => 'GET'],

		// File key-value metadata
		['name' => 'api#getFileKeys',    'url' => '/api/v1/filemeta',     'verb' => 'GET'],
		['name' => 'api#updateFileKey',  'url' => '/api/v1/filemeta',     'verb' => 'POST'],
		['name' => 'api#getMetadata',    'url' => '/api/v1/getmetadata',  'verb' => 'GET'],
	],
];
