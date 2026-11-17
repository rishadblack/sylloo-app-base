<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * rishadblack/sylloo-app-base: the offline waiting list (writes made offline, sent later in order) and the saved-data
 * frame (lists kept on the device). Fields, files and rows are stored encrypted with the app key.
 */
return new class extends Migration
{
    public function up(): void
    {
        $connection = config('sylloo-app-base.rest.connection');

        Schema::connection($connection)->create((string) config('sylloo-app-base.rest.waiting.table', 'sylloo_waiting_writes'), function (Blueprint $table) {
            $table->id();
            $table->string('scope', 64)->index();
            $table->string('action', 120);
            $table->text('fields');
            $table->text('files')->nullable();
            $table->string('key', 64)->unique();
            $table->string('status', 16)->default('waiting')->index();
            $table->unsignedInteger('tries')->default(0);
            $table->string('code', 64)->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('next_try_at')->nullable();
            $table->timestamp('made_at');
            $table->timestamps();
        });

        Schema::connection($connection)->create((string) config('sylloo-app-base.rest.saved.lists_table', 'sylloo_saved_lists'), function (Blueprint $table) {
            $table->id();
            $table->string('scope', 64);
            $table->string('list', 64);
            $table->string('since', 64)->nullable();
            $table->timestamp('saved_at')->nullable();
            $table->unique(['scope', 'list']);
        });

        Schema::connection($connection)->create((string) config('sylloo-app-base.rest.saved.rows_table', 'sylloo_saved_rows'), function (Blueprint $table) {
            $table->id();
            $table->string('scope', 64);
            $table->string('list', 64);
            $table->string('row_id', 64);
            $table->text('data');
            $table->unique(['scope', 'list', 'row_id']);
        });
    }

    public function down(): void
    {
        $connection = config('sylloo-app-base.rest.connection');

        Schema::connection($connection)->dropIfExists((string) config('sylloo-app-base.rest.saved.rows_table', 'sylloo_saved_rows'));
        Schema::connection($connection)->dropIfExists((string) config('sylloo-app-base.rest.saved.lists_table', 'sylloo_saved_lists'));
        Schema::connection($connection)->dropIfExists((string) config('sylloo-app-base.rest.waiting.table', 'sylloo_waiting_writes'));
    }
};
