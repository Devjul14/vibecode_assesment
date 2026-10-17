<?php

namespace Tests\Unit;

use App\Enums\ActorRole;
use App\Enums\CapacityEntryType;
use App\Enums\DependencyType;
use App\Enums\EstimateConfidence;
use App\Enums\ImpactLevel;
use App\Enums\RecommendationType;
use App\Enums\RequesterPriority;
use App\Enums\RequestStatus;
use App\Enums\ReworkCause;
use App\Enums\RiskLevel;
use App\Enums\Seniority;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kontrak enum terhadap BLUEPRINT §3–§4 dan §11.
 */
class EnumContractTest extends TestCase
{
    /**
     * Diagram BLUEPRINT §4.2 (tanpa cancelled, yang diuji terpisah).
     *
     * @return array<string, array{RequestStatus, list<RequestStatus>}>
     */
    public static function blueprintTransitions(): array
    {
        return [
            'new' => [RequestStatus::New, [RequestStatus::Triage]],
            'triage' => [RequestStatus::Triage, [RequestStatus::NeedsClarification, RequestStatus::Ready]],
            'needs_clarification' => [RequestStatus::NeedsClarification, [RequestStatus::Triage]],
            'ready' => [RequestStatus::Ready, [RequestStatus::Assigned]],
            'assigned' => [RequestStatus::Assigned, [RequestStatus::Ready, RequestStatus::InProgress]],
            'in_progress' => [RequestStatus::InProgress, [RequestStatus::Blocked, RequestStatus::Delivered, RequestStatus::Triage]],
            'blocked' => [RequestStatus::Blocked, [RequestStatus::InProgress]],
            'delivered' => [RequestStatus::Delivered, [RequestStatus::Accepted, RequestStatus::Returned]],
            'returned' => [RequestStatus::Returned, [RequestStatus::InProgress]],
            'accepted' => [RequestStatus::Accepted, []],
            'cancelled' => [RequestStatus::Cancelled, []],
        ];
    }

    /** @param  list<RequestStatus>  $expected */
    #[DataProvider('blueprintTransitions')]
    public function test_transitions_match_blueprint_state_diagram(RequestStatus $from, array $expected): void
    {
        $actual = array_values(array_filter(
            $from->allowedTransitions(),
            fn (RequestStatus $to) => $to !== RequestStatus::Cancelled,
        ));

        $this->assertEqualsCanonicalizing($expected, $actual);

        foreach (RequestStatus::cases() as $to) {
            if ($to === RequestStatus::Cancelled) {
                continue;
            }

            $this->assertSame(
                in_array($to, $expected, true),
                $from->canTransitionTo($to),
                "{$from->value} → {$to->value}",
            );
        }
    }

    public function test_no_status_transitions_to_itself(): void
    {
        foreach (RequestStatus::cases() as $status) {
            $this->assertFalse($status->canTransitionTo($status), $status->value);
        }
    }

    public function test_accepted_cannot_be_cancelled_but_every_other_open_status_can(): void
    {
        $this->assertFalse(RequestStatus::Accepted->canTransitionTo(RequestStatus::Cancelled));

        foreach (RequestStatus::openStatuses() as $status) {
            $this->assertTrue($status->canTransitionTo(RequestStatus::Cancelled), $status->value);
        }
    }

    public function test_load_statuses_exclude_intake_and_closed_statuses(): void
    {
        foreach ([RequestStatus::New, RequestStatus::Triage, RequestStatus::NeedsClarification, RequestStatus::Accepted, RequestStatus::Cancelled] as $status) {
            $this->assertFalse($status->countsAsLoad(), $status->value);
        }

        foreach ([RequestStatus::Ready, RequestStatus::Assigned, RequestStatus::InProgress, RequestStatus::Blocked, RequestStatus::Returned] as $status) {
            $this->assertTrue($status->countsAsLoad(), $status->value);
        }
    }

    public function test_every_status_has_label_and_color(): void
    {
        foreach (RequestStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
            $this->assertNotSame('', $status->color());
        }
    }

    /**
     * @return array<string, array{class-string, list<string>}>
     */
    public static function blueprintEnumValues(): array
    {
        return [
            'RiskLevel' => [RiskLevel::class, ['on_track', 'at_risk', 'will_breach', 'breached']],
            'ImpactLevel' => [ImpactLevel::class, ['unknown', 'low', 'medium', 'high', 'critical']],
            'RequesterPriority' => [RequesterPriority::class, ['low', 'medium', 'high']],
            'EstimateConfidence' => [EstimateConfidence::class, ['low', 'medium', 'high']],
            'DependencyType' => [DependencyType::class, ['internal', 'external']],
            'CapacityEntryType' => [CapacityEntryType::class, ['leave', 'sick', 'meeting', 'training', 'other']],
            'ReworkCause' => [ReworkCause::class, ['quality', 'requirement_change', 'unclear_scope']],
            'Seniority' => [Seniority::class, ['junior', 'mid', 'senior']],
            'ActorRole' => [ActorRole::class, ['manager', 'team_lead', 'member', 'requester', 'system']],
            'RecommendationType' => [RecommendationType::class, [
                'reassign', 'rebalance', 'escalate_dependency', 'clarify_scope', 'triage_impact', 'start_early',
                'pairing', 'negotiate_deadline', 'split_scope', 'add_capacity', 'cross_train',
            ]],
        ];
    }

    /**
     * @param  class-string  $enum
     * @param  list<string>  $expected
     */
    #[DataProvider('blueprintEnumValues')]
    public function test_enum_values_match_blueprint(string $enum, array $expected): void
    {
        $this->assertEqualsCanonicalizing($expected, $enum::values());
        $this->assertCount(count($expected), $enum::options());
    }
}
