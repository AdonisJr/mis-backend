<?php

namespace Tests\Feature;

use App\Models\FeeItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\InteractsWithFees;
use Tests\TestCase;

class FeeItemAccessTest extends TestCase
{
    use InteractsWithFees;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFeeFixtures();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function provideFeeItemRoutes(): array
    {
        return [
            'list' => ['GET', self::FEE_ITEMS_URL],
            'create' => ['POST', self::FEE_ITEMS_URL],
            'update' => ['PUT', self::FEE_ITEMS_URL.'/{feeItem}'],
            'deactivate' => ['PUT', self::FEE_ITEMS_URL.'/{feeItem}/deactivate'],
            'reactivate' => ['PUT', self::FEE_ITEMS_URL.'/{feeItem}/reactivate'],
        ];
    }

    public function test_admin_can_use_every_fee_item_endpoint(): void
    {
        $createdFeeItemId = $this->withToken($this->adminToken)
            ->postJson(self::FEE_ITEMS_URL, ['name' => self::ID_CARD, 'category' => FeeItem::CATEGORY_OTHER])
            ->assertCreated()
            ->json('fee_item.id');
        $feeItemUrl = self::FEE_ITEMS_URL.'/'.$createdFeeItemId;

        $this->withToken($this->adminToken)->getJson(self::FEE_ITEMS_URL)->assertOk()->assertJsonPath('fee_items.0.name', self::ID_CARD);
        $this->withToken($this->adminToken)
            ->putJson($feeItemUrl, ['name' => self::LIBRARY_FEE, 'category' => FeeItem::CATEGORY_MISCELLANEOUS])
            ->assertOk()
            ->assertJsonPath('fee_item.name', self::LIBRARY_FEE);
        $this->withToken($this->adminToken)->putJson($feeItemUrl.'/deactivate')->assertOk()->assertJsonPath('fee_item.is_active', false);
        $this->withToken($this->adminToken)->putJson($feeItemUrl.'/reactivate')->assertOk()->assertJsonPath('fee_item.is_active', true);
    }

    #[DataProvider('provideFeeItemRoutes')]
    public function test_guest_gets_401_on_fee_item_routes(string $method, string $uriPattern): void
    {
        $targetFeeItem = $this->createFeeItem(self::ID_CARD, FeeItem::CATEGORY_OTHER);

        $this->json($method, $this->buildTargetUri($uriPattern, $targetFeeItem), $this->buildChangedPayload())
            ->assertUnauthorized()
            ->assertExactJson(self::UNAUTHENTICATED_BODY);

        $this->assertTargetUnchanged($targetFeeItem);
    }

    #[DataProvider('provideFeeItemRoutes')]
    public function test_registrar_gets_403_on_fee_item_routes(string $method, string $uriPattern): void
    {
        $targetFeeItem = $this->createFeeItem(self::ID_CARD, FeeItem::CATEGORY_OTHER);

        $this->withToken($this->createRegistrarToken())
            ->json($method, $this->buildTargetUri($uriPattern, $targetFeeItem), $this->buildChangedPayload())
            ->assertForbidden()
            ->assertExactJson(self::FORBIDDEN_BODY);

        $this->assertTargetUnchanged($targetFeeItem);
    }

    private function buildTargetUri(string $uriPattern, FeeItem $targetFeeItem): string
    {
        return strtr($uriPattern, ['{feeItem}' => $targetFeeItem->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildChangedPayload(): array
    {
        return ['name' => 'Changed', 'category' => FeeItem::CATEGORY_TUITION];
    }

    private function assertTargetUnchanged(FeeItem $targetFeeItem): void
    {
        $reloadedFeeItem = $targetFeeItem->fresh();

        $this->assertSame(self::ID_CARD, $reloadedFeeItem->name);
        $this->assertSame(FeeItem::CATEGORY_OTHER, $reloadedFeeItem->category);
        $this->assertTrue($reloadedFeeItem->is_active);
        $this->assertDatabaseCount('fee_items', 1);
    }
}
