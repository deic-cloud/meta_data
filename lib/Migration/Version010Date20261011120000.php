<?php

declare(strict_types=1);

namespace OCA\MetaData\Migration;

/**
 * Seeds the 'DataCite' schema (files_publish "Assign DOI…", agreed with
 * Frederik 2026-10-11) on existing installs. Same idempotent pass as
 * Version006 over predefined_schemas.php: missing schemas and keys are
 * created, nothing a user customised is touched.
 */
class Version010Date20261011120000 extends Version006Date20260906120000 {
}
