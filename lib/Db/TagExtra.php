<?php

declare(strict_types=1);

namespace OCA\MetaData\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getSystemtagId()
 * @method void setSystemtagId(int $systemtagId)
 * @method string getDescription()
 * @method void setDescription(string $description)
 */
class TagExtra extends Entity {
	protected int $systemtagId = 0;
	protected string $description = '';

	public function __construct() {
		$this->addType('systemtagId', 'int');
	}
}
