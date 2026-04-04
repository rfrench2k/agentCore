<?php
/**
 * AgentCore — Lightweight Cron Expression Parser
 *
 * Handles standard 5-field cron expressions: min hour dom month dow
 * No external dependencies.
 *
 * Supported syntax:
 *   *        any value
 *   5        exact value
 *   1,3,5    list
 *   1-5      range
 *   * /15     every 15 (step)
 *   1-5/2    range with step
 */

class CronExpression
{
    private array $fields; // [minutes, hours, dom, month, dow]

    private const FIELD_RANGES = [
        [0, 59],   // minute
        [0, 23],   // hour
        [1, 31],   // day of month
        [1, 12],   // month
        [0, 6],    // day of week (0=Sunday)
    ];

    public function __construct(string $expression)
    {
        $parts = preg_split('/\s+/', trim($expression));
        if (count($parts) !== 5) {
            throw new InvalidArgumentException("Cron expression must have 5 fields, got " . count($parts) . ": {$expression}");
        }

        $this->fields = [];
        for ($i = 0; $i < 5; $i++) {
            $this->fields[$i] = $this->parseField($parts[$i], self::FIELD_RANGES[$i][0], self::FIELD_RANGES[$i][1]);
        }
    }

    /**
     * Calculate the next run time after the given DateTime.
     */
    public function nextRunAfter(DateTime $after): DateTime
    {
        $dt = clone $after;
        $dt->modify('+1 minute');
        $dt->setTime((int)$dt->format('G'), (int)$dt->format('i'), 0);

        $maxIterations = 525960; // 1 year in minutes
        for ($i = 0; $i < $maxIterations; $i++) {
            $minute = (int)$dt->format('i');
            $hour   = (int)$dt->format('G');
            $dom    = (int)$dt->format('j');
            $month  = (int)$dt->format('n');
            $dow    = (int)$dt->format('w');

            if (in_array($month, $this->fields[3]) &&
                in_array($dom, $this->fields[2]) &&
                in_array($dow, $this->fields[4]) &&
                in_array($hour, $this->fields[1]) &&
                in_array($minute, $this->fields[0])) {
                return $dt;
            }

            $dt->modify('+1 minute');
        }

        throw new RuntimeException("Could not find next run within 1 year for cron expression");
    }

    /**
     * Get a human-readable description of the schedule.
     */
    public function describe(): string
    {
        $parts = [];

        // Time
        if (count($this->fields[0]) === 1 && count($this->fields[1]) === 1) {
            $parts[] = sprintf('%d:%02d', $this->fields[1][0], $this->fields[0][0]);
        } elseif (count($this->fields[0]) === 60 && count($this->fields[1]) === 24) {
            $parts[] = 'every minute';
        } else {
            $parts[] = 'at specific times';
        }

        // Days of week
        $dowNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        if (count($this->fields[4]) < 7) {
            $days = array_map(fn($d) => $dowNames[$d], $this->fields[4]);
            $parts[] = implode(', ', $days);
        } else {
            $parts[] = 'daily';
        }

        return implode(' ', $parts);
    }

    /**
     * Parse a single cron field into an array of valid values.
     */
    private function parseField(string $field, int $min, int $max): array
    {
        $values = [];

        foreach (explode(',', $field) as $part) {
            $part = trim($part);

            // Handle step: */5 or 1-10/2
            $step = 1;
            if (str_contains($part, '/')) {
                [$part, $stepStr] = explode('/', $part, 2);
                $step = (int)$stepStr;
                if ($step < 1) $step = 1;
            }

            if ($part === '*') {
                for ($i = $min; $i <= $max; $i += $step) {
                    $values[] = $i;
                }
            } elseif (str_contains($part, '-')) {
                [$rangeMin, $rangeMax] = explode('-', $part, 2);
                $rangeMin = max((int)$rangeMin, $min);
                $rangeMax = min((int)$rangeMax, $max);
                for ($i = $rangeMin; $i <= $rangeMax; $i += $step) {
                    $values[] = $i;
                }
            } else {
                $val = (int)$part;
                if ($val >= $min && $val <= $max) {
                    $values[] = $val;
                }
            }
        }

        $values = array_unique($values);
        sort($values);
        return $values;
    }
}
