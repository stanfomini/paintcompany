<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\LifeCommitment;
use App\Models\User;
use Carbon\Carbon;
use DateTimeInterface;
use Spatie\IcalendarGenerator\Components\Calendar;
use Spatie\IcalendarGenerator\Components\Event;
use Spatie\IcalendarGenerator\Enums\RecurrenceDay;
use Spatie\IcalendarGenerator\Enums\RecurrenceFrequency;
use Spatie\IcalendarGenerator\Enums\RecurrenceMonth;
use Spatie\IcalendarGenerator\ValueObjects\RRule;

class CalendarFeedService
{
    /**
     * Generate an iCal feed for a user's active life commitments.
     */
    public function generateFeed(User $user): string
    {
        $settings = CompanySetting::getInstance();

        $commitments = LifeCommitment::query()
            ->where('company_setting_id', $settings->id)
            ->active()
            ->ordered()
            ->get();

        $calendar = Calendar::create('Apex Negotiations - Life Commitments')
            ->description('Life commitments and goals from your business plan')
            ->refreshInterval(720)
            ->productIdentifier('-//Apex Negotiations//Life Commitments//EN');

        foreach ($commitments as $commitment) {
            $event = $this->buildEvent($commitment);
            if ($event) {
                $calendar->event($event);
            }
        }

        return $calendar->get();
    }

    protected function buildEvent(LifeCommitment $commitment): ?Event
    {
        $startDate = Carbon::parse($commitment->start_date ?? today());
        $event = Event::create($commitment->commitment)
            ->description("Area: {$commitment->area}\nAction Type: ".ucfirst($commitment->action_type))
            ->uniqueIdentifier("life-commitment-{$commitment->id}@apexnegotiations.com");

        return match ($commitment->action_type) {
            'daily' => $this->buildDailyEvent($event, $startDate, $commitment->recurrence_time, $commitment->recurrence_end_time),
            'weekly' => $this->buildWeeklyEvent($event, $startDate, $commitment->recurrence_days, $commitment->recurrence_time, $commitment->recurrence_end_time),
            'monthly' => $this->buildMonthlyEvent($event, $startDate, $commitment->recurrence_day_of_month, $commitment->recurrence_time, $commitment->recurrence_end_time),
            'quarterly' => $this->buildQuarterlyEvent($event, $startDate, $commitment->recurrence_day_of_month, $commitment->recurrence_time, $commitment->recurrence_end_time),
            'annual' => $this->buildAnnualEvent($event, $startDate, $commitment->recurrence_time, $commitment->recurrence_end_time),
            'ongoing' => $this->buildOngoingEvent($event, $startDate),
            default => null,
        };
    }

    protected function buildDailyEvent(Event $event, DateTimeInterface $startDate, ?string $time, ?string $endTime = null): Event
    {
        [$hour, $minute] = $this->parseTime($time);
        [$endHour, $endMinute] = $endTime ? $this->parseTime($endTime) : [$hour + 1, $minute];

        return $event
            ->startsAt(Carbon::parse($startDate)->setTime($hour, $minute))
            ->endsAt(Carbon::parse($startDate)->setTime($endHour, $endMinute))
            ->rrule(RRule::frequency(RecurrenceFrequency::Daily));
    }

    /** @param  array<int>|null  $recurrenceDays */
    protected function buildWeeklyEvent(Event $event, DateTimeInterface $startDate, ?array $recurrenceDays, ?string $time, ?string $endTime = null): Event
    {
        $days = array_map(
            fn (int $d) => $this->mapRecurrenceDay($d),
            ! empty($recurrenceDays) ? $recurrenceDays : [1]
        );

        [$hour, $minute] = $this->parseTime($time);
        [$endHour, $endMinute] = $endTime ? $this->parseTime($endTime) : [$hour + 1, $minute];

        $rrule = RRule::frequency(RecurrenceFrequency::Weekly);
        foreach ($days as $day) {
            $rrule = $rrule->onWeekDay($day);
        }

        return $event
            ->startsAt(Carbon::parse($startDate)->setTime($hour, $minute))
            ->endsAt(Carbon::parse($startDate)->setTime($endHour, $endMinute))
            ->rrule($rrule);
    }

    protected function buildMonthlyEvent(Event $event, DateTimeInterface $startDate, ?int $dayOfMonth, ?string $time, ?string $endTime = null): Event
    {
        $monthDay = $dayOfMonth ?? 1;
        [$hour, $minute] = $this->parseTime($time);
        [$endHour, $endMinute] = $endTime ? $this->parseTime($endTime) : [$hour + 1, $minute];

        return $event
            ->startsAt(Carbon::parse($startDate)->setTime($hour, $minute))
            ->endsAt(Carbon::parse($startDate)->setTime($endHour, $endMinute))
            ->rrule(
                RRule::frequency(RecurrenceFrequency::Monthly)
                    ->onMonthDay($monthDay)
            );
    }

    protected function buildQuarterlyEvent(Event $event, DateTimeInterface $startDate, ?int $dayOfMonth, ?string $time, ?string $endTime = null): Event
    {
        $monthDay = $dayOfMonth ?? 1;
        [$hour, $minute] = $this->parseTime($time);
        [$endHour, $endMinute] = $endTime ? $this->parseTime($endTime) : [$hour + 1, $minute];

        return $event
            ->startsAt(Carbon::parse($startDate)->setTime($hour, $minute))
            ->endsAt(Carbon::parse($startDate)->setTime($endHour, $endMinute))
            ->rrule(
                RRule::frequency(RecurrenceFrequency::Monthly)
                    ->interval(3)
                    ->onMonthDay($monthDay)
                    ->onMonth([
                        RecurrenceMonth::January,
                        RecurrenceMonth::April,
                        RecurrenceMonth::July,
                        RecurrenceMonth::October,
                    ])
            );
    }

    protected function buildAnnualEvent(Event $event, DateTimeInterface $startDate, ?string $time, ?string $endTime = null): Event
    {
        [$hour, $minute] = $this->parseTime($time);
        [$endHour, $endMinute] = $endTime ? $this->parseTime($endTime) : [$hour + 1, $minute];

        return $event
            ->startsAt(Carbon::parse($startDate)->setTime($hour, $minute))
            ->endsAt(Carbon::parse($startDate)->setTime($endHour, $endMinute))
            ->rrule(RRule::frequency(RecurrenceFrequency::Yearly));
    }

    protected function buildOngoingEvent(Event $event, DateTimeInterface $startDate): Event
    {
        return $event
            ->startsAt(Carbon::parse($startDate))
            ->fullDay();
    }

    /**
     * Parse a time string "HH:MM" into [hour, minute]. Defaults to 9:00.
     *
     * @return array{0: int, 1: int}
     */
    protected function parseTime(?string $time): array
    {
        if ($time && preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches)) {
            return [(int) $matches[1], (int) $matches[2]];
        }

        return [9, 0];
    }

    protected function mapRecurrenceDay(int $dayNumber): RecurrenceDay
    {
        return match ($dayNumber) {
            1 => RecurrenceDay::Monday,
            2 => RecurrenceDay::Tuesday,
            3 => RecurrenceDay::Wednesday,
            4 => RecurrenceDay::Thursday,
            5 => RecurrenceDay::Friday,
            6 => RecurrenceDay::Saturday,
            7 => RecurrenceDay::Sunday,
            default => RecurrenceDay::Monday,
        };
    }
}
