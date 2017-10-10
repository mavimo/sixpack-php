<?php

declare(strict_types=1);

namespace SeatGeek\Sixpack\Test\Reponse;

use PHPUnit\Framework\TestCase;
use SeatGeek\Sixpack\Response\Conversion;

class ConversionTest extends TestCase
{
    public function testDecodeValidJson()
    {
        $conversion = new Conversion('{"client_id": "custom-client-id"}', [
            'http_code' => 200,
            'url' => 'http://test.org',
        ]);

        $this->assertEquals(200, $conversion->getStatus());
        $this->assertTrue($conversion->getSuccess());
    }
}
