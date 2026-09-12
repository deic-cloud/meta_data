<?php

declare(strict_types=1);

namespace OCA\MetaData\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getSystemtagId()
 * @method void setSystemtagId(int $systemtagId)
 * @method string getDescription()
 * @method void setDescription(string $description)
 * @method ?string getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 */
class TagExtra extends Entity {
	protected int $systemtagId = 0;
	protected string $description = '';
	/** uid of the tag's owner; '' = nobody (seeded or pre-ownership tag → admins only) */
	protected ?string $createdBy = null;

	public function __construct() {
		$this->addType('systemtagId', 'int');
	}
}
