<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Fuente: `sql/schema.sql` §3. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE suppliers (
                id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                organization_id BIGINT NOT NULL REFERENCES organizations(id),
                code            VARCHAR(32) NOT NULL,
                name            VARCHAR(200) NOT NULL,
                tax_id          VARCHAR(20),
                contact         JSONB NOT NULL DEFAULT '{}'::jsonb,
                -- Rango EPC delegado al proveedor para source tagging (Fase 3)
                delegated_epc_range JSONB,
                is_active       BOOLEAN NOT NULL DEFAULT TRUE,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT suppliers_code_unique UNIQUE (organization_id, code)
            );

            CREATE TABLE categories (
                id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                organization_id BIGINT NOT NULL REFERENCES organizations(id),
                parent_id       BIGINT REFERENCES categories(id) ON DELETE SET NULL,
                code            VARCHAR(32) NOT NULL,
                name            VARCHAR(120) NOT NULL,
                path            TEXT,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT categories_code_unique UNIQUE (organization_id, code)
            );

            CREATE TABLE seasons (
                id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                organization_id BIGINT NOT NULL REFERENCES organizations(id),
                code            VARCHAR(24) NOT NULL,
                name            VARCHAR(80) NOT NULL,
                starts_on       DATE,
                ends_on         DATE,
                CONSTRAINT seasons_code_unique UNIQUE (organization_id, code)
            );

            CREATE TABLE products (
                id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                organization_id BIGINT NOT NULL REFERENCES organizations(id),
                category_id     BIGINT REFERENCES categories(id),
                season_id       BIGINT REFERENCES seasons(id),
                supplier_id     BIGINT REFERENCES suppliers(id),
                code            VARCHAR(48) NOT NULL,
                name            VARCHAR(200) NOT NULL,
                description     TEXT,
                brand           VARCHAR(120),
                composition     VARCHAR(200),
                -- El material afecta al rendimiento RFID; se registra para diagnóstico
                rfid_difficulty SMALLINT NOT NULL DEFAULT 1
                    CONSTRAINT products_rfid_difficulty_range CHECK (rfid_difficulty BETWEEN 1 AND 5),
                is_active       BOOLEAN NOT NULL DEFAULT TRUE,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT products_code_unique UNIQUE (organization_id, code)
            );

            CREATE TABLE product_variants (
                id              BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
                product_id      BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
                sku             VARCHAR(64) NOT NULL,
                size            VARCHAR(24),
                color           VARCHAR(48),
                color_hex       CHAR(7),
                barcode         VARCHAR(20),
                gtin13          VARCHAR(14),
                item_reference  VARCHAR(8),
                cost_price      NUMERIC(12,4),
                sale_price      NUMERIC(12,4),
                currency        CHAR(3) NOT NULL DEFAULT 'PEN',
                min_stock       INT NOT NULL DEFAULT 0,
                is_active       BOOLEAN NOT NULL DEFAULT TRUE,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT product_variants_sku_unique UNIQUE (sku),
                CONSTRAINT product_variants_gtin_unique UNIQUE (gtin13)
            );

            CREATE INDEX product_variants_product_idx ON product_variants (product_id);
            CREATE INDEX product_variants_barcode_idx ON product_variants (barcode) WHERE barcode IS NOT NULL;

            CREATE TRIGGER suppliers_touch BEFORE UPDATE ON suppliers
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
            CREATE TRIGGER categories_touch BEFORE UPDATE ON categories
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
            CREATE TRIGGER products_touch BEFORE UPDATE ON products
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
            CREATE TRIGGER product_variants_touch BEFORE UPDATE ON product_variants
                FOR EACH ROW EXECUTE FUNCTION touch_updated_at();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS product_variants CASCADE;
            DROP TABLE IF EXISTS products CASCADE;
            DROP TABLE IF EXISTS seasons CASCADE;
            DROP TABLE IF EXISTS categories CASCADE;
            DROP TABLE IF EXISTS suppliers CASCADE;
        SQL);
    }
};
