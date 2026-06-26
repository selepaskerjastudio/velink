<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dns_records', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cloudflare_token_id')->constrained()->cascadeOnDelete();
            $table->string('zone_id');        // Cloudflare zone ID
            $table->string('record_id');      // Cloudflare DNS record ID (for update/delete)
            $table->string('type', 10);       // A, AAAA, CNAME, TXT
            $table->string('name');           // full FQDN
            $table->string('content');        // IP / target
            $table->boolean('proxied')->default(false);
            $table->unsignedInteger('ttl')->default(1); // 1 = auto
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_records');
    }
};
