<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * One item on one list. `key` is the item's identity across a remix family:
 * a remix copies its parent's keys, so the crowd average groups by it.
 * `tier` is the row (0 = top); null means the item is still in the pool.
 */
return Migration::createTable('tier_list_items', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('tier_list_id');
    $table->string('key', 16);
    $table->string('label', 80);
    $table->string('image', 255)->nullable();
    $table->unsignedTinyInteger('tier')->nullable();
    $table->unsignedSmallInteger('position')->default(0);

    $table->foreign('tier_list_id')->references('id')->on('tier_lists')->cascadeOnDelete();
    $table->index(['tier_list_id', 'tier', 'position']);
    $table->index('key');
});
