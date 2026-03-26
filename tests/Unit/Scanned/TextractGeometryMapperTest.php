<?php

namespace Tests\Unit\Scanned;

use App\Services\Scanned\TextractGeometryMapper;
use Tests\TestCase;

class TextractGeometryMapperTest extends TestCase
{
    public function test_it_maps_normalized_textract_geometry_into_pdf_points(): void
    {
        $mapper = new TextractGeometryMapper();

        $coordinates = $mapper->mapBoundingBox([
            'Left' => 0.1,
            'Top' => 0.2,
            'Width' => 0.5,
            'Height' => 0.1,
        ], 600.0, 800.0, 0.82);

        $this->assertSame(60.0, $coordinates['x_start']);
        $this->assertSame(360.0, $coordinates['x_end']);
        $this->assertSame(640.0, $coordinates['top']);
        $this->assertSame(560.0, $coordinates['bottom']);
        $this->assertSame(80.0, $coordinates['height']);
        $this->assertEqualsWithDelta(625.6, $coordinates['y'], 0.001);
    }
}
