<?php

namespace OCA\ContextChat\Tests;

use OCA\ContextChat\AppInfo\Application;

class LangRopeServiceTest extends \PHPUnit\Framework\TestCase {

	public function testDummy() {
		$app = new Application();
		$this->assertEquals('context_chat', $app::APP_ID);
	}
}
