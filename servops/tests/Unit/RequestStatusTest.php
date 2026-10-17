<?php

namespace Tests\Unit;

use App\Enums\RequestStatus;
use PHPUnit\Framework\TestCase;

class RequestStatusTest extends TestCase
{
    public function test_only_accepted_counts_as_done(): void
    {
        $done = array_filter(RequestStatus::cases(), fn (RequestStatus $status) => $status->isDone());

        $this->assertSame([RequestStatus::Accepted], array_values($done));
    }

    public function test_delivered_must_be_accepted_or_returned_before_closing(): void
    {
        $this->assertTrue(RequestStatus::Delivered->canTransitionTo(RequestStatus::Accepted));
        $this->assertTrue(RequestStatus::Delivered->canTransitionTo(RequestStatus::Returned));
        $this->assertFalse(RequestStatus::InProgress->canTransitionTo(RequestStatus::Accepted));
    }

    public function test_returned_work_goes_back_to_in_progress_and_counts_as_load(): void
    {
        $this->assertTrue(RequestStatus::Returned->canTransitionTo(RequestStatus::InProgress));
        $this->assertTrue(RequestStatus::Returned->countsAsLoad());
        $this->assertFalse(RequestStatus::Returned->isDone());
    }

    public function test_unclear_scope_cannot_jump_straight_to_ready(): void
    {
        $this->assertFalse(RequestStatus::NeedsClarification->canTransitionTo(RequestStatus::Ready));
        $this->assertTrue(RequestStatus::NeedsClarification->canTransitionTo(RequestStatus::Triage));
    }

    public function test_closed_statuses_have_no_transitions(): void
    {
        $this->assertSame([], RequestStatus::Accepted->allowedTransitions());
        $this->assertSame([], RequestStatus::Cancelled->allowedTransitions());
    }

    public function test_every_open_status_can_be_cancelled(): void
    {
        foreach (RequestStatus::openStatuses() as $status) {
            $this->assertTrue($status->canTransitionTo(RequestStatus::Cancelled), $status->value);
        }
    }

    public function test_waiting_statuses_include_rework_queue_but_not_active_work(): void
    {
        $this->assertTrue(RequestStatus::Returned->isWaiting());
        $this->assertTrue(RequestStatus::Blocked->isWaiting());
        $this->assertFalse(RequestStatus::InProgress->isWaiting());
    }
}
