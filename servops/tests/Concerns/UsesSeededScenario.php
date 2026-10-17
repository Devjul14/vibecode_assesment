<?php

namespace Tests\Concerns;

use App\Data\PriorityResult;
use App\Data\RiskResult;
use App\Models\Recommendation;
use App\Models\ServiceRequest;
use App\Models\TeamMember;
use App\Models\User;

/**
 * Data seed lengkap (SR-101..107) dengan waktu simulasi Senin 19 Okt 2026 09:00.
 */
trait UsesSeededScenario
{
    protected function seedScenario(): void
    {
        config(['servops.now' => '2026-10-19T09:00:00+07:00']);
        $this->seed();
    }

    /** Login sebagai akun demo (lihat UserSeeder), mis. "rudi", "sari", "andi", "ops". */
    protected function loginAs(string $account): User
    {
        $user = User::query()->where('email', "{$account}@servops.test")->firstOrFail();
        $this->actingAs($user);

        return $user;
    }

    protected function sr(string $code): ServiceRequest
    {
        return ServiceRequest::query()->where('code', $code)->firstOrFail();
    }

    protected function teamMember(string $name): TeamMember
    {
        return TeamMember::query()->where('name', $name)->firstOrFail();
    }

    /** @return list<string> */
    protected function riskCodes(string $code): array
    {
        return array_column($this->sr($code)->risk_reasons ?? [], 'code');
    }

    /** @return list<string> */
    protected function reviewCodes(string $code): array
    {
        return array_column($this->sr($code)->review_reasons ?? [], 'code');
    }

    /** @return list<string> judul rekomendasi terbuka untuk request */
    protected function openRecommendationTypes(string $code): array
    {
        return Recommendation::query()->open()
            ->where('service_request_id', $this->sr($code)->id)
            ->get()
            ->map(fn (Recommendation $recommendation) => $recommendation->type->value)
            ->all();
    }

    protected function assertPriorityResult(mixed $result): PriorityResult
    {
        $this->assertInstanceOf(PriorityResult::class, $result);

        return $result;
    }

    protected function assertRiskResult(mixed $result): RiskResult
    {
        $this->assertInstanceOf(RiskResult::class, $result);

        return $result;
    }
}
