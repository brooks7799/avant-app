<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Use raw SQL to handle this properly
        // First, create an index on company_id so we can drop the unique constraint
        DB::statement('CREATE INDEX documents_company_id_index ON documents (company_id)');

        // Drop the unique constraint
        DB::statement('ALTER TABLE documents DROP INDEX documents_company_id_document_type_id_source_url_unique');

        // Make document_type_id nullable
        DB::statement('ALTER TABLE documents MODIFY document_type_id BIGINT UNSIGNED NULL');

        // Re-add the foreign key for document_type_id
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_document_type_id_foreign FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE SET NULL');

        // Add new unique constraint on company_id and source_url only
        DB::statement('CREATE UNIQUE INDEX documents_company_id_source_url_unique ON documents (company_id, source_url)');
    }

    public function down(): void
    {
        // Drop new unique constraint
        DB::statement('ALTER TABLE documents DROP INDEX documents_company_id_source_url_unique');

        // Drop the foreign key
        DB::statement('ALTER TABLE documents DROP FOREIGN KEY documents_document_type_id_foreign');

        // Make document_type_id not nullable (need to handle nulls first)
        DB::statement('ALTER TABLE documents MODIFY document_type_id BIGINT UNSIGNED NOT NULL');

        // Drop the company_id index we created
        DB::statement('ALTER TABLE documents DROP INDEX documents_company_id_index');

        // Re-add original unique constraint
        DB::statement('CREATE UNIQUE INDEX documents_company_id_document_type_id_source_url_unique ON documents (company_id, document_type_id, source_url)');

        // Re-add original foreign key
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_document_type_id_foreign FOREIGN KEY (document_type_id) REFERENCES document_types(id) ON DELETE CASCADE');
    }
};
