<?php

namespace Tests\Concerns;

use App\Data\Actor;
use App\Enums\ActorRole;
use App\Exceptions\WorkflowException;
use App\Services\Calendar\Clock;
use App\Services\Workflow\RequestChangeService;
use App\Services\Workflow\WorkflowTransitionService;
use Carbon\CarbonImmutable;
use Closure;

trait InteractsWithWorkflow
{
    protected function freezeClock(string $at = '2026-10-19 09:00'): CarbonImmutable
    {
        return app(Clock::class)->freeze(CarbonImmutable::parse($at, 'Asia/Jakarta'));
    }

    protected function advanceClock(float $hours): CarbonImmutable
    {
        return app(Clock::class)->advance($hours);
    }

    protected function workflow(): WorkflowTransitionService
    {
        return app(WorkflowTransitionService::class);
    }

    protected function changes(): RequestChangeService
    {
        return app(RequestChangeService::class);
    }

    protected function lead(): Actor
    {
        return new Actor(ActorRole::TeamLead, 'Lead');
    }

    protected function member(string $name = 'Anggota'): Actor
    {
        return new Actor(ActorRole::Member, $name);
    }

    protected function requester(): Actor
    {
        return new Actor(ActorRole::Requester, 'Pemohon');
    }

    protected function assertWorkflowRule(string $rule, Closure $callback): void
    {
        try {
            $callback();
        } catch (WorkflowException $exception) {
            $this->assertSame($rule, $exception->rule, $exception->getMessage());

            return;
        }

        $this->fail("WorkflowException [{$rule}] tidak dilempar.");
    }
}
