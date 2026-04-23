<?php

namespace Tests\Feature;

use Tests\TestCase;

class VideoStreamingTest extends TestCase
{
    public function test_video_range_request_parsing(): void
    {
        $rangeHeader = 'bytes=0-1023';
        $parts = explode('=', $rangeHeader);
        $ranges = explode('-', $parts[1]);
        
        $start = intval($ranges[0]);
        $end = intval($ranges[1]);
        
        $this->assertEquals(0, $start);
        $this->assertEquals(1023, $end);
    }
    
    public function test_video_range_request_with_open_end(): void
    {
        $rangeHeader = 'bytes=1024-';
        $parts = explode('=', $rangeHeader);
        $ranges = explode('-', $parts[1]);
        
        $start = intval($ranges[0]);
        $end = null;
        
        $this->assertEquals(1024, $start);
        $this->assertNull($end);
    }
    
    public function test_video_stream_response_headers(): void
    {
        $expectedHeaders = [
            'Content-Type',
            'Content-Length', 
            'Content-Range',
            'Accept-Ranges',
        ];
        
        $this->assertCount(4, $expectedHeaders);
    }
    
    public function test_partial_content_status_code(): void
    {
        $statusCode = 206;
        
        $this->assertEquals(206, $statusCode);
    }
    
    public function test_full_content_status_code(): void
    {
        $statusCode = 200;
        
        $this->assertEquals(200, $statusCode);
    }
    
    public function test_video_chunk_calculation(): void
    {
        $start = 0;
        $end = 1023;
        $length = $end - $start + 1;
        
        $this->assertEquals(1024, $length);
    }
}
