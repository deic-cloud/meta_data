<?php

declare(strict_types=1);

namespace OCA\MetaData\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getId()
 * @method int getTagid()
 * @method void setTagid(int $tagid)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getAllowedValues()
 * @method void setAllowedValues(string $allowedValues)
 * @method string getType()
 * @method void setType(string $type)
 */
class MetaKey extends Entity {
	/** @var int */
	public $tagid = 0;
	/** @var string */
	public $name = '';
	/** @var string */
	public $allowedValues = '';
	/** @var string */
	public $type = '';

	public function __construct() {
		$this->addType('tagid', Types::INTEGER);
		$this->addType('name', Types::STRING);
		$this->addType('allowedValues', Types::STRING);
		$this->addType('type', Types::STRING);
	}

	public function jsonSerialize(): array {
		return [
			'id'             => $this->id,
			'tagid'          => $this->tagid,
			'name'           => $this->name,
			'allowed_values' => $this->allowedValues,
			'type'           => $this->type,
		];
	}
}
