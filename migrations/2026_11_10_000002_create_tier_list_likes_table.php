<?php

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('tier_list_likes', function (Blueprint $table) {
    $table->unsignedInteger('tier_list_id');
    $table->unsignedInteger('user_id');
    $table->timestamp('created_at')->nullable();

    $table->primary(['tier_list_id', 'user_id']);
    $table->foreign('tier_list_id')->references('id')->on('tier_lists')->cascadeOnDelete();
    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
});
