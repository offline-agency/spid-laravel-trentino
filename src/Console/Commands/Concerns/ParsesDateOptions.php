<?php

declare(strict_types=1);

namespace OfflineAgency\SpidLaravelTrentino\Console\Commands\Concerns;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use InvalidArgumentException;

trait ParsesDateOptions
{
    /**
     * Start of the day given as a YYYY-MM-DD option, in the application timezone.
     *
     * @throws InvalidArgumentException for a value that is not a YYYY-MM-DD date
     */
    private function dateOption(string $option): ?CarbonImmutable
    {
        $value = $this->option($option);

        if ($value === null) {
            return null;
        }

        $value = is_string($value) ? $value : '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, CarbonImmutable::now()->getTimezone());

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("Invalid --{$option} date [{$value}]; expected YYYY-MM-DD.");
        }

        return CarbonImmutable::instance($date);
    }
}
