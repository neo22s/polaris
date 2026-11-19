<?php

declare(strict_types=1);

namespace Polaris\Tests;

use PHPUnit\Framework\TestCase;
use Polaris\Auth\AdminPromotion;
use Polaris\Auth\UserStore;

final class AdminPromotionTest extends TestCase
{
    public function testAListedEmailIsPromotedAndTheReturnedRowIsUpdated(): void
    {
        $store = $this->store();
        $promotion = new AdminPromotion($store, [' Boss@Example.com ']);

        $this->assertTrue($promotion->shouldBeAdmin('boss@example.com'));
        $this->assertFalse($promotion->shouldBeAdmin('nobody@example.com'));

        $user = $promotion->maybePromote(['id' => 7, 'email' => 'boss@example.com', 'role' => 'member']);

        $this->assertSame('admin', $user['role']);
        $this->assertSame([7], $store->promoted);
    }

    public function testAnExistingAdminAndAnUnlistedEmailAreLeftAlone(): void
    {
        $store = $this->store();
        $promotion = new AdminPromotion($store, ['boss@example.com']);

        $this->assertSame(
            ['id' => 1, 'email' => 'boss@example.com', 'role' => 'admin'],
            $promotion->maybePromote(['id' => 1, 'email' => 'boss@example.com', 'role' => 'admin']),
        );
        $this->assertSame(
            ['id' => 2, 'email' => 'other@example.com', 'role' => 'member'],
            $promotion->maybePromote(['id' => 2, 'email' => 'other@example.com', 'role' => 'member']),
        );
        $this->assertSame([], $store->promoted);
    }

    private function store(): UserStore
    {
        return new class implements UserStore {
            /** @var array<int,int> */
            public array $promoted = [];

            public function findById(int $id): ?array
            {
                return null;
            }

            public function findByEmail(string $email): ?array
            {
                return null;
            }

            public function create(array $data): int
            {
                return 0;
            }

            public function update(int $id, array $data): void
            {
            }

            public function markEmailVerified(int $id): void
            {
            }

            public function promote(int $id): void
            {
                $this->promoted[] = $id;
            }

            public function setStatus(int $id, string $status): void
            {
            }
        };
    }
}
