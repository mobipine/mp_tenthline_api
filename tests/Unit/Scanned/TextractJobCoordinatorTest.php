<?php

namespace Tests\Unit\Scanned;

use App\Services\Scanned\TextractClientFactory;
use App\Services\Scanned\TextractGeometryMapper;
use App\Services\Scanned\TextractJobCoordinator;
use App\Services\Scanned\TextractLineNormalizer;
use Aws\Textract\TextractClient;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class TextractJobCoordinatorTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_fetches_and_normalizes_textract_pages_from_paginated_results(): void
    {
        Storage::fake('local');

        config([
            'textract.max_results' => 2,
        ]);

        $client = Mockery::mock(TextractClient::class);
        $client->shouldReceive('getDocumentTextDetection')
            ->once()
            ->with([
                'JobId' => 'job-123',
                'MaxResults' => 2,
            ])
            ->andReturn($this->fakeTextractResponse([
                'JobStatus' => 'SUCCEEDED',
                'DocumentMetadata' => ['Pages' => 2],
                'Blocks' => [
                    ['Id' => 'page-1', 'BlockType' => 'PAGE', 'Page' => 1],
                    [
                        'Id' => 'line-1',
                        'BlockType' => 'LINE',
                        'Page' => 1,
                        'Text' => 'Hello world',
                        'Confidence' => 99.0,
                        'Geometry' => [
                            'BoundingBox' => [
                                'Left' => 0.1,
                                'Top' => 0.2,
                                'Width' => 0.3,
                                'Height' => 0.03,
                            ],
                        ],
                        'Relationships' => [
                            [
                                'Type' => 'CHILD',
                                'Ids' => ['word-1', 'word-2'],
                            ],
                        ],
                    ],
                    [
                        'Id' => 'word-1',
                        'BlockType' => 'WORD',
                        'Page' => 1,
                        'Text' => 'Hello',
                        'Confidence' => 98.0,
                        'Geometry' => [
                            'BoundingBox' => [
                                'Left' => 0.1,
                                'Top' => 0.2,
                                'Width' => 0.12,
                                'Height' => 0.03,
                            ],
                        ],
                    ],
                    [
                        'Id' => 'word-2',
                        'BlockType' => 'WORD',
                        'Page' => 1,
                        'Text' => 'world',
                        'Confidence' => 97.0,
                        'Geometry' => [
                            'BoundingBox' => [
                                'Left' => 0.24,
                                'Top' => 0.2,
                                'Width' => 0.14,
                                'Height' => 0.03,
                            ],
                        ],
                    ],
                ],
                'NextToken' => 'next-token',
            ]));

        $client->shouldReceive('getDocumentTextDetection')
            ->once()
            ->with([
                'JobId' => 'job-123',
                'MaxResults' => 2,
                'NextToken' => 'next-token',
            ])
            ->andReturn($this->fakeTextractResponse([
                'JobStatus' => 'SUCCEEDED',
                'DocumentMetadata' => ['Pages' => 2],
                'Blocks' => [
                    ['Id' => 'page-2', 'BlockType' => 'PAGE', 'Page' => 2],
                    [
                        'Id' => 'line-2',
                        'BlockType' => 'LINE',
                        'Page' => 2,
                        'Text' => 'Second page',
                        'Confidence' => 96.0,
                        'Geometry' => [
                            'BoundingBox' => [
                                'Left' => 0.15,
                                'Top' => 0.25,
                                'Width' => 0.28,
                                'Height' => 0.03,
                            ],
                        ],
                        'Relationships' => [
                            [
                                'Type' => 'CHILD',
                                'Ids' => ['word-3', 'word-4'],
                            ],
                        ],
                    ],
                    [
                        'Id' => 'word-3',
                        'BlockType' => 'WORD',
                        'Page' => 2,
                        'Text' => 'Second',
                        'Confidence' => 95.0,
                        'Geometry' => [
                            'BoundingBox' => [
                                'Left' => 0.15,
                                'Top' => 0.25,
                                'Width' => 0.16,
                                'Height' => 0.03,
                            ],
                        ],
                    ],
                    [
                        'Id' => 'word-4',
                        'BlockType' => 'WORD',
                        'Page' => 2,
                        'Text' => 'page',
                        'Confidence' => 94.0,
                        'Geometry' => [
                            'BoundingBox' => [
                                'Left' => 0.33,
                                'Top' => 0.25,
                                'Width' => 0.1,
                                'Height' => 0.03,
                            ],
                        ],
                    ],
                ],
            ]));

        $factory = Mockery::mock(TextractClientFactory::class);
        $factory->shouldReceive('make')->once()->andReturn($client);

        $coordinator = new TextractJobCoordinator(
            $factory,
            new TextractLineNormalizer(new TextractGeometryMapper)
        );

        $pages = $coordinator->fetchNormalizedPages('job-123', [
            1 => ['width' => 612.0, 'height' => 792.0],
            2 => ['width' => 612.0, 'height' => 792.0],
        ], [
            'pdf_job_id' => 'pdf-job-123',
        ]);

        $this->assertCount(2, $pages);
        $this->assertSame('Hello world', $pages[1]['raw_lines'][0]['text']);
        $this->assertSame('Second page', $pages[2]['raw_lines'][0]['text']);
        $this->assertCount(2, $pages[1]['raw_lines'][0]['words']);
        $this->assertCount(2, $pages[2]['raw_lines'][0]['words']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_it_can_store_and_reload_normalized_pages_using_a_stream(): void
    {
        Storage::fake('local');

        $factory = Mockery::mock(TextractClientFactory::class);
        $coordinator = new TextractJobCoordinator(
            $factory,
            new TextractLineNormalizer(new TextractGeometryMapper)
        );

        $path = $coordinator->storeNormalizedPages('pdf-job-123', [
            1 => [
                'page_no' => 1,
                'engine' => 'ocr',
                'ocr_provider' => 'textract',
                'page_width' => 612.0,
                'page_height' => 792.0,
                'page_rotation' => 0,
                'raw_lines' => [
                    [
                        'id' => 'line-1',
                        'text' => 'Hello world',
                        'x_start' => 72.0,
                        'x_end' => 216.0,
                        'y' => 680.0,
                        'top' => 690.0,
                        'bottom' => 670.0,
                        'height' => 20.0,
                        'char_count' => 11,
                        'words' => [],
                        'source' => 'textract',
                        'confidence' => 99.0,
                    ],
                ],
                'diagnostic' => [
                    'engine' => 'ocr',
                    'ocr_provider' => 'textract',
                ],
            ],
        ]);

        $loaded = $coordinator->loadNormalizedPages($path);

        $this->assertSame('pdf-jobs/pdf-job-123/textract-pages.json', $path);
        $this->assertSame('Hello world', $loaded[1]['raw_lines'][0]['text']);
    }

    private function fakeTextractResponse(array $payload): object
    {
        return new class($payload)
        {
            public function __construct(
                private readonly array $payload
            ) {}

            public function toArray(): array
            {
                return $this->payload;
            }
        };
    }
}
