<?php

namespace chaseburklund\entryporter\tests;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testAutoloadWorks(): void
    {
        $this->assertTrue(class_exists(\chaseburklund\entryporter\Plugin::class));
    }
}
