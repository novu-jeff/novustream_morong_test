<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('bill', function (Blueprint $table) {
            // Add 'initiated_at' if not yet present
            if (!Schema::hasColumn('bill', 'initiated_at')) {
                $table->timestamp('initiated_at')->nullable()->after('date_paid');
            }

            // Add HitPay tracking fields (optional if not yet present)
            if (!Schema::hasColumn('bill', 'hitpay_reference')) {
                $table->string('hitpay_reference')->nullable()->after('payment_method');
            }

            if (!Schema::hasColumn('bill', 'hitpay_payment_id')) {
                $table->string('hitpay_payment_id')->nullable()->after('hitpay_reference');
            }
        });
    }

    public function down()
    {
        Schema::table('bill', function (Blueprint $table) {
            if (Schema::hasColumn('bill', 'initiated_at')) {
                $table->dropColumn('initiated_at');
            }
            if (Schema::hasColumn('bill', 'hitpay_reference')) {
                $table->dropColumn('hitpay_reference');
            }
            if (Schema::hasColumn('bill', 'hitpay_payment_id')) {
                $table->dropColumn('hitpay_payment_id');
            }
        });
    }
};
