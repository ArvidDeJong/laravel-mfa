<?php

declare(strict_types=1);

/**
 * The README and composer.json name the supported versions each in their own
 * words. These tests fail when one is changed and the other is forgotten.
 */
it('names the PHP and Laravel versions of composer.json in the README', function () {
    $composer = json_decode((string) file_get_contents(packageRoot('composer.json')), true, flags: JSON_THROW_ON_ERROR);
    $readme = (string) file_get_contents(packageRoot('README.md'));

    preg_match('/\d+\.\d+/', $composer['require']['php'], $php);
    preg_match_all('/\^(\d+)/', $composer['require']['laravel/framework'], $laravel);

    $majors = $laravel[1];
    $last = array_pop($majors);

    expect($readme)->toContain("PHP {$php[0]} or newer and Laravel ".implode(', ', $majors)." or {$last}.");
});

it('says in the README that it is for session guards, not for token APIs', function () {
    expect((string) file_get_contents(packageRoot('README.md')))->toContain('session guards');
});
