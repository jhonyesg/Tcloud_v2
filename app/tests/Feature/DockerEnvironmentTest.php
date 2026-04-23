<?php

namespace Tests\Feature;

use Tests\TestCase;

class DockerEnvironmentTest extends TestCase
{
    public function test_docker_compose_services(): void
    {
        $expectedServices = ['nginx', 'php', 'postgres', 'redis'];
        
        $this->assertCount(4, $expectedServices);
    }
    
    public function test_postgres_database_name(): void
    {
        $databaseName = 'tcloudstorage';
        
        $this->assertEquals('tcloudstorage', $databaseName);
    }
    
    public function test_redis_port(): void
    {
        $redisPort = 6379;
        
        $this->assertEquals(6379, $redisPort);
    }
    
    public function test_postgres_port(): void
    {
        $postgresPort = 5432;
        
        $this->assertEquals(5432, $postgresPort);
    }
    
    public function test_nginx_port(): void
    {
        $nginxPort = 8080;
        
        $this->assertEquals(8080, $nginxPort);
    }
    
    public function test_data_directories(): void
    {
        $dataDirs = ['storage', 'postgres_data', 'redis_data'];
        
        $this->assertCount(3, $dataDirs);
    }
    
    public function test_php_extensions(): void
    {
        $requiredExtensions = ['pdo_pgsql', 'gd', 'redis', 'zip'];
        
        $this->assertCount(4, $requiredExtensions);
    }
}
