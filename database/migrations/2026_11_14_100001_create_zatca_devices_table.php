<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per EGS unit (device / POS / branch). Each has its own CSID and ICV/PIH chain.
     */
    public function up(): void
    {
        Schema::create('zatca_devices', function (Blueprint $table) {
            $table->id();
            $table->string('environment')->default('sandbox');

            // Seller
            $table->string('seller_name');
            $table->string('vat_number', 15);
            $table->string('crn')->nullable();
            $table->string('street');
            $table->string('building_number', 4);
            $table->string('plot_identification')->nullable();
            $table->string('district');
            $table->string('city');
            $table->string('postal_code', 5);

            // EGS unit (goes into the CSR)
            $table->string('egs_name');
            $table->string('egs_serial');
            $table->string('branch_name');
            $table->string('industry');
            $table->string('invoice_types', 4)->default('1100');

            // Keys and certificates
            $table->text('private_key')->nullable();
            $table->text('csr')->nullable();
            $table->string('compliance_request_id')->nullable();
            $table->text('compliance_certificate')->nullable();
            $table->text('compliance_secret')->nullable();
            $table->json('compliance_checks')->nullable();
            $table->string('production_request_id')->nullable();
            $table->text('production_certificate')->nullable();
            $table->text('production_secret')->nullable();
            $table->timestamp('production_expires_at')->nullable();

            // Invoice chain
            $table->unsignedBigInteger('icv')->default(0);
            $table->string('previous_invoice_hash')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_devices');
    }
};
