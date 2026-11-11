<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

// Everyone may look; members may make, remix and like; moderators may edit or delete any list.
return Migration::addPermissions([
    'ernestdefoe-tier-lists.view' => Group::GUEST_ID,
    'ernestdefoe-tier-lists.create' => Group::MEMBER_ID,
    'ernestdefoe-tier-lists.moderate' => Group::MODERATOR_ID,
]);
