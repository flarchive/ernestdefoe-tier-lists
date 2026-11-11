<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/*
 * Pictures held by FoF Upload are tracked here too (name `fof:<uuid>`, with
 * their URL), so a picture uploaded for a list is deleted with it. The URL is
 * looked up with whereIn, hence the index (Laravel names it; 255 × 4 bytes in
 * utf8mb4 is within InnoDB's 3072-byte key limit).
 */
return [
    'up' => function (Builder $schema) {
        $schema->table('tier_list_images', function (Blueprint $table) {
            $table->string('url', 255)->nullable();
            $table->index('url');
        });
    },
    'down' => function (Builder $schema) {
        $schema->table('tier_list_images', function (Blueprint $table) {
            $table->dropIndex(['url']);
            $table->dropColumn('url');
        });
    },
];
