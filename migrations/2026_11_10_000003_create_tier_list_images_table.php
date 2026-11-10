<?php

use ErnestDefoe\TierLists\Images;
use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Pictures uploaded to this extension's own disk (when FoF Upload is not
 * there to hold them). An image no item shows any more is deleted.
 */
return [
    'up' => function (Builder $schema) {
        $schema->create('tier_list_images', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name', 40)->unique();
            $table->unsignedInteger('user_id')->nullable();
            // Set once a saved list shows it; only unclaimed uploads expire.
            $table->boolean('claimed')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['claimed', 'created_at']);
        });
    },
    'down' => function (Builder $schema) {
        // Purging the extension deletes the files too; disabling keeps them.
        Images::purge(resolve(\Illuminate\Contracts\Container\Container::class));
        $schema->dropIfExists('tier_list_images');
    },
];
