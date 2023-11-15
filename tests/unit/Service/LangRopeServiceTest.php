<?php

namespace OCA\Cwyd\Tests;

use OCA\Cwyd\AppInfo\Application;

class LangRopeServiceTest extends \PHPUnit\Framework\TestCase {

	public function testDummy() {
		$app = new Application();
		$this->assertEquals('cwyd', $app::APP_ID);
	}
}
