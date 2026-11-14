<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The signed e-invoice for one of your invoices, and what ZATCA said about it.
     */
    public function up(): void
    {
        Schema::create('zatca_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('zatca_device_id')->constrained('zatca_devices');
            $table->nullableMorphs('documentable');

            $table->string('invoice_number');
            $table->uuid('uuid')->unique();
            $table->string('invoice_type');
            $table->string('document_type');
            $table->unsignedBigInteger('icv');
            $table->string('previous_invoice_hash');
            $table->string('invoice_hash');
            $table->decimal('total_amount', 15, 2);
            $table->decimal('vat_amount', 15, 2);

            $table->longText('signed_xml');
            $table->longText('cleared_xml')->nullable();
            $table->text('qr_code');

            $table->string('status')->default('pending');
            $table->json('response')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->unique(['zatca_device_id', 'icv']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_documents');
    }
};
