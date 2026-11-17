<?php

namespace Darvis\Mfa\Console;

use Darvis\Mfa\Firewall\DatabaseCountryResolver;
use Darvis\Mfa\Support\MfaConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Downloads this month's DB-IP IP to Country Lite database, checks it and
 * swaps it in for the file the country filter reads.
 *
 * DB-IP publishes a new file on the first of every month; until it is out,
 * last month's is fetched. The new file is written next to the old one and
 * moved over it in one rename, so a failed download leaves the old file in
 * place. The data is CC BY 4.0 and asks for a link to db-ip.com.
 */
class GeoipUpdateCommand extends Command
{
    public const URL = 'https://download.db-ip.com/free/dbip-country-lite-%s.mmdb.gz';

    /**
     * The RIPE NCC in Amsterdam: a file that places it anywhere else is not
     * the database we expect.
     */
    private const KNOWN_ADDRESS = '193.0.6.139';

    private const KNOWN_COUNTRY = 'NL';

    protected $signature = 'mfa:geoip-update';

    protected $description = 'Download the country database the login firewall reads';

    public function handle(): int
    {
        $path = MfaConfig::countryDatabase();
        $temporary = $path.'.download';

        File::ensureDirectoryExists(dirname($path));

        foreach ([now(), now()->subMonthNoOverflow()] as $month) {
            try {
                $response = Http::timeout(120)->get(sprintf(self::URL, $month->format('Y-m')));
            } catch (Throwable) {
                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            $database = @gzdecode($response->body());

            if ($database === false) {
                continue;
            }

            File::put($temporary, $database);

            try {
                $country = (new DatabaseCountryResolver($temporary))->country(self::KNOWN_ADDRESS);
            } catch (Throwable $exception) {
                File::delete($temporary);
                $this->components->error($exception->getMessage());

                return self::FAILURE;
            }

            if ($country !== self::KNOWN_COUNTRY) {
                File::delete($temporary);
                $this->components->error('The download is not a usable country database; the current file is kept.');

                return self::FAILURE;
            }

            File::move($temporary, $path);

            $this->components->info("Country database of {$month->format('F Y')} is in place: {$path}");

            return self::SUCCESS;
        }

        $this->components->error('Could not download the country database; the current file is kept.');

        return self::FAILURE;
    }
}
