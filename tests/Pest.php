<?php

use Darvis\Mfa\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

function packageRoot(string $path = ''): string
{
    return dirname(__DIR__).($path === '' ? '' : '/'.$path);
}
