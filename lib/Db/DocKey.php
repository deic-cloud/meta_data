<?php

declare(strict_types=1);

namespace OCA\MetaData\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getId()
 * @method int getFileid()
 * @method void setFileid(int $fileid)
 * @method int getTagid()
 * @method void setTagid(int $tagid)
 * @method int getKeyid()
 * @method void setKeyid(int $keyid)
 * @method string getValue()
 * @method void setValue(string $value)
 */
class DocKey extends Entity {
	/** @var int */
	public $fileid = 0;
	/** @var int */
	public $tagid = 0;
	/** @var int */
	public $keyid = 0;
	/** @var string */
	public $value = '';

	public function __construct() {
		$this->addType('fileid', Types::INTEGER);
		$this->addType('tagid', Types::INTEGER);
		$this->addType('keyid', Types::INTEGER);
		$this->addType('value', Types::STRING);
	}
}
