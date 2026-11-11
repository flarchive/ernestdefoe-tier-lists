<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

/*
 * A tier list. A remix points at the list it was made from (parent_id) and at
 * the first list of its family (root_id): the crowd's placement of an item is
 * the average over the root and every list with that root_id.
 */
return Migration::createTable('tier_lists', function (Blueprint $table) {
    $table->increments('id');
    $table->unsignedInteger('user_id')->nullable();
    $table->unsignedInteger('root_id')->nullable();
    $table->unsignedInteger('parent_id')->nullable();
    $table->string('title', 100);
    $table->text('description')->nullable();
    // [{label, color}], top tier first.
    $table->text('tiers');
    $table->unsignedTinyInteger('tier_count');
    $table->boolean('is_template')->default(false);
    $table->unsignedInteger('discussion_id')->nullable();
    $table->unsignedInteger('items_count')->default(0);
    $table->unsignedInteger('likes_count')->default(0);
    $table->unsignedInteger('remixes_count')->default(0);
    $table->timestamp('created_at')->nullable();
    $table->timestamp('updated_at')->nullable();

    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
    $table->foreign('discussion_id')->references('id')->on('discussions')->nullOnDelete();
    $table->index('root_id');
    $table->index('created_at');
    $table->index('likes_count');
    $table->index('remixes_count');
});
