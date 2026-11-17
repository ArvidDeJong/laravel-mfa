<?php

declare(strict_types=1);

/**
 * Guards the rules of the GitHub Pages site in docs/: front matter, Liquid use,
 * single-source facts and the developer credit. Nothing here breaks loudly otherwise.
 */
function docsPath(string $path = ''): string
{
    return dirname(__DIR__).'/docs'.($path === '' ? '' : '/'.$path);
}

/**
 * @return array<string, string>
 */
function frontMatter(string $file): array
{
    preg_match('/\A---\n(.*?)\n---\n/s', (string) file_get_contents($file), $match);

    $values = [];
    foreach (explode("\n", $match[1] ?? '') as $line) {
        if (preg_match('/^(\w+):\s*(.*)$/', $line, $pair)) {
            $value = trim($pair[2]);

            // An unquoted ": " makes the YAML invalid, and Jekyll then silently ignores all front matter.
            expect(str_contains($value, ': ') && ! str_starts_with($value, '"'))
                ->toBeFalse(basename($file).': quote the value of '.$pair[1]);

            $values[$pair[1]] = trim($value, '"');
        }
    }

    return $values;
}

/**
 * Every page of the site: the top level pages and the migration guides under them.
 *
 * @return list<string>
 */
function docsPages(): array
{
    return [...(glob(docsPath('*.md')) ?: []), ...(glob(docsPath('migrating/*.md')) ?: [])];
}

test('every page has a title, a unique description and a nav order', function () {
    $descriptions = [];

    foreach (docsPages() as $page) {
        $meta = frontMatter($page);

        expect($meta)->toHaveKeys(['title', 'description', 'nav_order'], basename($page));
        $descriptions[] = $meta['description'];
    }

    expect(count(docsPages()))->toBeGreaterThan(10);
    expect(array_unique($descriptions))->toHaveCount(count(docsPages()));
});

test('the top level pages each have their own place in the navigation', function () {
    $pages = glob(docsPath('*.md')) ?: [];
    $navOrders = array_map(fn (string $page) => frontMatter($page)['nav_order'], $pages);

    expect(array_unique($navOrders))->toHaveCount(count($pages));
});

test('the migration guides hang under the Migrating page', function () {
    expect(frontMatter(docsPath('migrating.md')))->toHaveKey('has_children');

    foreach (glob(docsPath('migrating/*.md')) ?: [] as $guide) {
        expect(frontMatter($guide)['parent'] ?? null)->toBe('Migrating', basename($guide));
        expect(file_get_contents(docsPath('migrating.md')))->toContain('(migrating/'.basename($guide).')');
    }
});

test('pages use Liquid only where it is intended', function () {
    foreach (docsPages() as $page) {
        if (basename($page) === 'faq.md') {
            continue;
        }

        // Jekyll renders Liquid in pages and in code examples alike, so a Blade echo breaks the build.
        expect(file_get_contents($page))->not->toMatch('/\{\{|\{%/', basename($page));
    }
});

test('the FAQ, structured data and llms.txt read from the shared data', function () {
    $faq = (string) file_get_contents(docsPath('_data/faq.yml'));

    expect(substr_count($faq, "\n- q: ") + (str_starts_with(ltrim($faq), '- q: ') ? 1 : 0))->toBeGreaterThanOrEqual(6);
    expect(substr_count($faq, '- q: '))->toBe(substr_count($faq, '  a: '));

    expect(file_get_contents(docsPath('faq.md')))->toContain('site.data.faq');
    expect(file_get_contents(docsPath('llms.txt')))
        ->toContain('permalink: /llms.txt')
        ->toContain('site.data.faq')
        ->toContain('site.pages');
    expect(file_get_contents(docsPath('_includes/head_custom.html')))
        ->toContain('"FAQPage"')
        ->toContain('"SoftwareSourceCode"')
        ->toContain('site.data.faq');
});

test('the YAML files build, because one bad value fails the whole Pages build', function () {
    // An unquoted ": " makes the YAML invalid. Jekyll then aborts the build and GitHub keeps
    // serving the last version that did build, so the site looks fine while it is months old.
    foreach (['_config.yml', '_data/faq.yml'] as $file) {
        $blockIndent = null;

        foreach (file(docsPath($file), FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
            $indent = strlen($line) - strlen(ltrim($line));

            if ($blockIndent !== null) {
                // Inside a > or | block every line is text, whatever it contains.
                if (trim($line) === '' || $indent > $blockIndent) {
                    continue;
                }

                $blockIndent = null;
            }

            if (! preg_match('/^(\s*)(?:-\s+)?(\w+):(?:\s+(\S.*))?$/', $line, $pair)) {
                continue;
            }

            $value = trim($pair[3] ?? '');

            if ($value === '') {
                continue;
            }

            if (str_starts_with($value, '>') || str_starts_with($value, '|')) {
                $blockIndent = strlen($pair[1]);

                continue;
            }

            $quoted = str_starts_with($value, '"')
                || str_starts_with($value, "'")
                || str_starts_with($value, '[');

            expect(str_contains($value, ': ') && ! $quoted)
                ->toBeFalse($file.' line '.($number + 1).': quote the value of '.$pair[2]);
        }
    }
});

test('the config holds the package facts and the sitemap plugin', function () {
    expect(file_get_contents(docsPath('_config.yml')))
        ->toContain('- jekyll-sitemap')
        ->toContain('name: darvis/laravel-mfa')
        ->toContain('baseurl: /laravel-mfa')
        ->toContain('company: ARVID.NL')
        ->toContain('url: https://arvid.nl')
        ->not->toContain('footer_content');

    expect(is_file(docsPath('assets/images/social-preview.png')))->toBeTrue();
});

test('the footer credits ARVID.NL without a personal name', function () {
    $footer = (string) file_get_contents(docsPath('_includes/footer_custom.html'));

    expect($footer)->toContain('site.developer.company')
        ->toContain('site.developer.url')
        ->not->toContain('Arvid de Jong')
        ->not->toMatch('/developed by|made by/i');

    expect(file_get_contents(docsPath('_includes/head_custom.html')))->not->toContain('"Person"');
});

test('every relative link between pages points at an existing page', function () {
    foreach ([...docsPages(), dirname(__DIR__).'/README.md'] as $page) {
        preg_match_all('/\]\((?!https?:|#|mailto:)([^)#\s]+)(?:#[^)]*)?\)/', (string) file_get_contents($page), $links);

        foreach ($links[1] as $link) {
            expect(is_file(dirname($page).'/'.$link))->toBeTrue(basename($page).' links to a missing file: '.$link);
        }
    }
});

test('every link to a heading on another page points at a heading that exists', function () {
    foreach (docsPages() as $page) {
        preg_match_all('/\]\((?!https?:|mailto:)([^)#\s]+)#([^)\s]+)\)/', (string) file_get_contents($page), $links, PREG_SET_ORDER);

        foreach ($links as [, $file, $anchor]) {
            $target = (string) file_get_contents(dirname($page).'/'.$file);

            preg_match_all('/^#{2,3} (.+?)(?: \{#([a-z0-9-]+)\})?$/m', $target, $headings, PREG_SET_ORDER);

            $anchors = array_map(
                fn (array $heading) => $heading[2] ?? str_replace(' ', '-', trim((string) preg_replace('/[^a-z0-9 -]/', '', strtolower($heading[1])))),
                $headings,
            );

            expect(in_array($anchor, $anchors, true))->toBeTrue(basename($page).' links to a missing heading: '.$file.'#'.$anchor);
        }
    }
});

test('the home page and the README link to every top level page', function () {
    $home = (string) file_get_contents(docsPath('index.md'));
    $readme = (string) file_get_contents(dirname(__DIR__).'/README.md');

    foreach (glob(docsPath('*.md')) ?: [] as $page) {
        if (basename($page) !== 'index.md') {
            expect($home)->toContain('('.basename($page).')');
            expect($readme)->toContain('(docs/'.basename($page).')');
        }
    }
});

test('a beginner finds how to install, how to check it works and what to do when it misbehaves', function () {
    expect(file_get_contents(docsPath('installation.md')))
        ->toContain('composer require darvis/laravel-mfa')
        ->toContain('php artisan migrate')
        ->toContain('## Check that it works');

    expect(file_get_contents(docsPath('first-login.md')))->toContain("->defaults('guard', 'staff')");

    expect(file_get_contents(docsPath('troubleshooting.md')))
        ->toContain('{#no-mail-arrives}')
        ->toContain('{#i-locked-myself-out}')
        ->toContain('php artisan config:clear');
});

test('the configuration page names every setting the config file ships', function () {
    /** @var array<string, array<string, mixed>> $config */
    $config = require dirname(__DIR__).'/config/mfa.php';
    $page = (string) file_get_contents(docsPath('configuration.md'));

    foreach ($config as $group => $settings) {
        foreach (array_keys($group === 'guards' ? $settings['web'] : $settings) as $key) {
            expect($page)->toContain($group === 'guards' ? 'guards.<guard>.'.$key : $group.'.'.$key);
        }
    }
});

test('the commands page describes every command the package registers', function () {
    $page = (string) file_get_contents(docsPath('commands.md'));

    foreach (glob(dirname(__DIR__).'/src/Console/*Command.php') ?: [] as $command) {
        preg_match("/signature = '(mfa:[a-z-]+)/", (string) file_get_contents($command), $match);

        expect($page)->toContain('## '.$match[1]);
    }
});

test('troubleshooting quotes the messages of the package literally', function () {
    /** @var array<string, string> $messages */
    $messages = require dirname(__DIR__).'/resources/lang/en/mfa.php';
    $page = (string) file_get_contents(docsPath('troubleshooting.md'));

    foreach (['blocked_attempts', 'blocked_country', 'blocked_ip'] as $key) {
        expect($page)->toContain($messages[$key]);
    }

    $source = file_get_contents(dirname(__DIR__).'/src/Mfa.php').file_get_contents(dirname(__DIR__).'/src/Firewall/DatabaseCountryResolver.php');

    foreach ([
        'The MFA link route is switched off (mfa.link.enabled). Call Mfa::linkUrlUsing() to point the mailed link at a route of the application.',
        'The "database" country driver needs the geoip2/geoip2 package: composer require geoip2/geoip2.',
    ] as $message) {
        expect($source)->toContain($message);
        expect($page)->toContain($message);
    }
});
