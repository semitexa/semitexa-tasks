<?php

declare(strict_types=1);

namespace Semitexa\Tasks\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Semitexa\Tasks\Application\Service\CreateTaskSkill;

/**
 * The planner emits the user's LOCAL wall-clock deadline; tasks store/compare
 * the deadline as a UTC instant. Parsing the wall-clock in the server zone
 * (UTC) instead of the user's zone lands the deadline offset hours off — a bug
 * even on a correctly-UTC server, since the user is elsewhere. This pins the
 * conversion the skill delegates to.
 */
final class CreateTaskDeadlineTzTest extends TestCase
{
    #[Test]
    public function a_local_wall_clock_deadline_becomes_the_right_utc_instant(): void
    {
        // Kyiv is UTC+3 in July (summer time): 18:00 local == 15:00 UTC.
        $utc = CreateTaskSkill::toUtcInstant('2026-07-06 18:00', new \DateTimeZone('Europe/Kyiv'));

        self::assertSame('2026-07-06 15:00:00', $utc->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $utc->getTimezone()->getName());
    }

    #[Test]
    public function it_honours_the_dst_offset_of_the_zone(): void
    {
        // Kyiv is UTC+2 in January (standard time): 18:00 local == 16:00 UTC.
        $utc = CreateTaskSkill::toUtcInstant('2026-01-06 18:00', new \DateTimeZone('Europe/Kyiv'));

        self::assertSame('2026-01-06 16:00:00', $utc->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function an_explicit_offset_in_the_input_wins_over_the_zone(): void
    {
        // A string carrying its own offset is authoritative; $tz is ignored.
        $utc = CreateTaskSkill::toUtcInstant('2026-07-06T18:00:00+00:00', new \DateTimeZone('Europe/Kyiv'));

        self::assertSame('2026-07-06 18:00:00', $utc->format('Y-m-d H:i:s'));
    }
}
