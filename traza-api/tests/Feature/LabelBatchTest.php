<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Labels\ZplRenderer;
use App\Domain\Tagging\Epc\EpcCodecFactory;
use App\Enums\RoleCode;
use App\Enums\TagState;
use App\Models\Location;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Tag;
use App\Models\TagBatch;
use App\Models\User;
use App\Services\LabelBatchService;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** Tarea 4.6: catálogo y lotes de etiquetas. ZPL según `docs/04` §5. */
#[Group('pgsql')]
final class LabelBatchTest extends TestCase
{
    private Organization $organization;

    private Location $tienda;

    private Product $product;

    private ProductVariant $variant;

    private LabelBatchService $batches;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Requiere PostgreSQL.');
        }

        DB::statement('TRUNCATE role_user, roles, tags, tag_batches, product_variant_counters,
                       stock_movements, users RESTART IDENTITY CASCADE');
        (new RoleSeeder)->run();

        config(['traza.epc.scheme' => 'sgtin-96', 'traza.epc.gs1_company_prefix' => '7751234']);

        $this->batches = app(LabelBatchService::class);

        $suffix = uniqid();
        $this->organization = Organization::create(['name' => 'VivaTech Pruebas']);
        $this->tienda = Location::create([
            'organization_id' => $this->organization->id,
            'code' => "LIM-{$suffix}", 'name' => 'Gamarra 1',
        ]);
        $this->product = Product::create([
            'organization_id' => $this->organization->id,
            'code' => "P-{$suffix}", 'name' => 'Polera Oversize',
        ]);
        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'sku' => "POL-{$suffix}",
            'size' => 'M', 'color' => 'Negro',
            'barcode' => '7751234123456',
            'item_reference' => '012345',
            'sale_price' => 89.90, 'currency' => 'PEN',
        ]);
    }

    // ------------------------------------------------------------- el lote

    public function test_un_lote_de_cien_etiquetas_genera_cien_epc_correlativos(): void
    {
        $batch = $this->batches->create($this->variant, 100);

        $this->assertSame(100, $batch->quantity);
        $this->assertSame(100, $batch->serial_to - $batch->serial_from + 1);
        $this->assertSame(100, Tag::where('tag_batch_id', $batch->id)->count());
    }

    public function test_los_epc_del_lote_decodifican_a_su_variante(): void
    {
        // Es la comprobación que importa del criterio de aceptación: no basta
        // con que el ZPL sea sintácticamente válido, tiene que llevar los EPC
        // que corresponden a esta variante.
        $batch = $this->batches->create($this->variant, 100);
        $codec = app(EpcCodecFactory::class);

        $epcs = Tag::where('tag_batch_id', $batch->id)->orderBy('id')->pluck('epc');
        $this->assertCount(100, $epcs);

        $serials = [];
        foreach ($epcs as $epc) {
            $decoded = $codec->decode($epc);

            $this->assertSame('sgtin-96', $decoded['scheme']);
            $this->assertSame('7751234', $decoded['companyPrefix']);
            $this->assertSame('012345', $decoded['itemReference']);
            $serials[] = $decoded['serial'];
        }

        $this->assertSame(range($batch->serial_from, $batch->serial_to), $serials);
        $this->assertCount(100, array_unique($serials));
    }

    public function test_los_tags_nacen_en_creado_y_no_en_stock(): void
    {
        /*
         * Una etiqueta impresa no es una prenda en la tienda. Si naciera en
         * `en_stock`, el inventario contaría mercadería que todavía está en
         * un rollo encima de la impresora.
         */
        $batch = $this->batches->create($this->variant, 10);

        $states = Tag::where('tag_batch_id', $batch->id)->distinct()->pluck('state');

        $this->assertSame([TagState::Creado], $states->all());
    }

    public function test_dos_lotes_seguidos_no_repiten_ningun_serial(): void
    {
        $first = $this->batches->create($this->variant, 50);
        $second = $this->batches->create($this->variant, 50);

        $this->assertSame($first->serial_to + 1, $second->serial_from);
        $this->assertSame(100, Tag::whereIn('tag_batch_id', [$first->id, $second->id])
            ->distinct()->count('epc'));
    }

    public function test_un_lote_vacio_o_desmesurado_se_rechaza(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->batches->create($this->variant, 0);
    }

    public function test_no_se_emiten_mas_de_cinco_mil_de_golpe(): void
    {
        // Un rollo de colgantes ronda las 1 000 etiquetas: un lote de 50 000
        // es un dedo de más en el teclado, y quema 50 000 seriales.
        $this->expectExceptionMessageMatches('/no puede pasar de 5000/');
        $this->batches->create($this->variant, 50_000);
    }

    // -------------------------------------------------------------- el ZPL

    public function test_el_zpl_lleva_los_comandos_rfid_del_documento(): void
    {
        $batch = $this->batches->create($this->variant, 3);
        $zpl = $this->batches->zpl($batch);

        foreach (['^XA', '^RS8,,,3,N', '^RFW,H,1,12,1', '^WV,Y', '^XZ', '^RQ'] as $command) {
            $this->assertStringContainsString($command, $zpl, "Falta {$command}.");
        }
    }

    public function test_cada_etiqueta_del_zpl_lleva_su_epc(): void
    {
        $batch = $this->batches->create($this->variant, 100);
        $zpl = $this->batches->zpl($batch);

        $epcs = Tag::where('tag_batch_id', $batch->id)->pluck('epc');

        foreach ($epcs as $epc) {
            $this->assertStringContainsString("^FD{$epc}^FS", $zpl);
        }

        // Cien etiquetas, cien bloques: ni uno de más ni de menos.
        $this->assertSame(100, substr_count($zpl, '^RFW,H,1,12,1'));
        $this->assertSame(101, substr_count($zpl, '^XA'), 'Falta el bloque ^RQ de resultado.');
    }

    public function test_el_zpl_lleva_los_datos_visibles_de_la_prenda(): void
    {
        // El operario cuelga la etiqueta mirando el texto, no el EPC. Si el
        // texto no coincide con la prenda, el error se descubre en caja.
        $zpl = $this->batches->zpl($this->batches->create($this->variant, 1));

        $this->assertStringContainsString('POLERA OVERSIZE', $zpl);
        $this->assertStringContainsString('Talla: M   Color: Negro', $zpl);
        $this->assertStringContainsString('S/ 89.90', $zpl);
        $this->assertStringContainsString('7751234123456', $zpl);
        $this->assertStringContainsString('SKU '.$this->variant->sku, $zpl);
    }

    public function test_un_epc_invalido_no_llega_a_la_impresora(): void
    {
        // Escribir basura en el banco EPC deja el inlay inservible y la
        // etiqueta ya va pegada a la prenda.
        $this->expectExceptionMessageMatches('/no es hexadecimal/');
        (new ZplRenderer)->label('NO-ES-UN-EPC', ['name' => 'X', 'sku' => 'X']);
    }

    public function test_los_prefijos_de_comando_del_nombre_se_neutralizan(): void
    {
        /*
         * `^` y `~` abren comando en ZPL. Un producto llamado «CAMISA ~
         * OFERTA» partiría la etiqueta en dos y la impresora haría cualquier
         * cosa con la segunda mitad.
         */
        $zpl = (new ZplRenderer)->label(
            '3035D919080C0E403B9ACA2A',
            ['name' => 'Camisa ^XZ ~ oferta', 'sku' => 'S1'],
        );

        $this->assertSame(1, substr_count($zpl, '^XZ'));
        $this->assertStringContainsString('CAMISA XZ - OFERTA', $zpl);
    }

    // ------------------------------------------------------- reimpresión

    public function test_reimprimir_no_consume_seriales_nuevos(): void
    {
        // Si la impresora se atasca a mitad de rollo, volver a emitir el lote
        // duplicaría el inventario de la variante.
        $batch = $this->batches->create($this->variant, 10);
        $epcs = Tag::where('tag_batch_id', $batch->id)->limit(3)->pluck('epc')->all();

        $zpl = $this->batches->reprint($epcs);

        $this->assertSame(3, substr_count($zpl, '^RFW,H,1,12,1'));
        $this->assertSame(10, Tag::count());
        $this->assertSame(1, TagBatch::count());
    }

    public function test_no_se_reimprime_un_epc_que_no_existe(): void
    {
        $this->expectExceptionMessageMatches('/no están emitidos/');
        $this->batches->reprint(['3035D9FFFFFFFFFFFFFFFFFF']);
    }

    // ---------------------------------------------------- inlays fallidos

    public function test_el_cierre_calcula_la_tasa_de_void(): void
    {
        $batch = $this->batches->create($this->variant, 100);

        $this->batches->complete($batch, printedOk: 97, printedVoid: 3);

        $this->assertSame(3.0, $batch->voidRate());
        $this->assertTrue($batch->voidRate() > LabelBatchService::VOID_RATE_THRESHOLD);
        $this->assertNotNull($batch->completed_at);
    }

    public function test_no_se_reportan_mas_etiquetas_de_las_emitidas(): void
    {
        $batch = $this->batches->create($this->variant, 10);

        $this->expectExceptionMessageMatches('/tiene 10 etiquetas/');
        $this->batches->complete($batch, printedOk: 10, printedVoid: 5);
    }

    public function test_un_lote_sin_cerrar_no_divide_entre_cero(): void
    {
        $this->assertSame(0.0, $this->batches->create($this->variant, 10)->voidRate());
    }

    // ------------------------------------------------------ superficie HTTP

    public function test_almacen_emite_un_lote_y_descarga_el_zpl(): void
    {
        $almacen = $this->userWith(RoleCode::Almacen);

        $response = $this->actingAs($almacen)
            ->withHeaders(['Idempotency-Key' => 'lote-'.uniqid()])
            ->postJson('/api/v1/label-batches', [
                'product_variant_id' => $this->variant->id,
                'quantity' => 100,
            ])
            ->assertCreated()
            ->assertJsonPath('quantity', 100);

        $zpl = $this->actingAs($almacen)->get($response->json('zpl_url'));

        $zpl->assertOk();
        $zpl->assertHeader('content-type', 'application/octet-stream');
        $this->assertSame(100, substr_count($zpl->content(), '^RFW'));
    }

    public function test_un_vendedor_no_puede_emitir_etiquetas(): void
    {
        // `label.print` es de almacén: emitir etiquetas consume seriales de
        // forma irreversible.
        $this->actingAs($this->userWith(RoleCode::Vendedor))
            ->withHeaders(['Idempotency-Key' => 'lote-'.uniqid()])
            ->postJson('/api/v1/label-batches', [
                'product_variant_id' => $this->variant->id,
                'quantity' => 10,
            ])
            ->assertForbidden();
    }

    public function test_no_se_descarga_el_zpl_de_otra_organizacion(): void
    {
        $batch = $this->batches->create($this->variant, 5);

        $otra = Organization::create(['name' => 'Otra empresa']);
        $intruso = User::create([
            'organization_id' => $otra->id,
            'name' => 'Intruso', 'email' => uniqid().'@otra.pe', 'password' => 'x',
        ]);
        $intruso->roles()->attach(Role::where('code', RoleCode::Admin->value)->value('id'));

        $this->actingAs($intruso->fresh())
            ->get("/api/v1/label-batches/{$batch->id}/zpl")
            ->assertForbidden();
    }

    public function test_el_listado_avisa_de_un_lote_con_demasiados_void(): void
    {
        $batch = $this->batches->create($this->variant, 100);
        $this->batches->complete($batch, printedOk: 95, printedVoid: 5);

        $this->actingAs($this->userWith(RoleCode::Almacen))
            ->getJson('/api/v1/label-batches')
            ->assertOk()
            ->assertJsonPath('data.0.void_rate', 5)
            ->assertJsonPath('data.0.void_rate_exceeded', true);
    }

    // ---------------------------------------------------------- catálogo

    public function test_se_crea_un_producto_con_su_variante(): void
    {
        $almacen = $this->userWith(RoleCode::Almacen);

        $product = $this->actingAs($almacen)->postJson('/api/v1/products', [
            'code' => 'NUEVO-'.uniqid(),
            'name' => 'Camiseta nueva',
            'rfid_difficulty' => 1,
        ])->assertCreated();

        $this->actingAs($almacen)
            ->postJson("/api/v1/products/{$product->json('id')}/variants", [
                'sku' => 'SKU-'.uniqid(),
                'size' => 'L',
                'item_reference' => '099999',
                'sale_price' => 39.90,
            ])
            ->assertCreated()
            ->assertJsonPath('size', 'L');
    }

    public function test_la_referencia_de_articulo_no_se_puede_cambiar(): void
    {
        /*
         * Va dentro del EPC de cada etiqueta ya impresa. Cambiarla haría que
         * el sistema decodificara esas prendas como otra variante, en
         * silencio y sin forma de detectarlo.
         */
        $original = $this->variant->item_reference;

        $this->actingAs($this->userWith(RoleCode::Almacen))
            ->patchJson("/api/v1/variants/{$this->variant->id}", [
                'item_reference' => '000001',
                'color' => 'Azul',
            ])
            ->assertOk();

        $this->assertSame($original, $this->variant->refresh()->item_reference);
        $this->assertSame('Azul', $this->variant->color);
    }

    public function test_el_catalogo_dice_cuantos_seriales_quedan(): void
    {
        $this->batches->create($this->variant, 100);

        $this->actingAs($this->userWith(RoleCode::Almacen))
            ->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonPath('data.0.variants.0.serials_remaining', (2 ** 38) - 1 - 100);
    }

    private function userWith(RoleCode $role): User
    {
        $user = User::create([
            'organization_id' => $this->organization->id,
            'name' => $role->label(),
            'email' => uniqid($role->value.'-').'@vivatech-peru.com',
            'password' => 'secreto',
            'default_location_id' => $this->tienda->id,
        ]);

        $user->roles()->attach(Role::where('code', $role->value)->value('id'));

        return $user->fresh();
    }
}
