<?php

namespace ErnestDefoe\TierLists;

final class Settings
{
    public const PREFIX = 'ernestdefoe-tier-lists.';

    /** The tag a list's discussion starts in, when flarum/tags is on. */
    public const TAG = self::PREFIX.'tag';
    public const NAV = self::PREFIX.'nav';

    public const VIEW = 'ernestdefoe-tier-lists.view';
    public const CREATE = 'ernestdefoe-tier-lists.create';
    public const MODERATE = 'ernestdefoe-tier-lists.moderate';

    public const MAX_TIERS = 10;
    public const MIN_TIERS = 2;
    public const MAX_ITEMS = 'ernestdefoe-tier-lists.max_items';

    /** The most items a list may hold, by default and at most (the board stays quick well past this). */
    public const DEFAULT_MAX_ITEMS = 200;
    public const CEILING_ITEMS = 500;

    public static function maxItems(\Flarum\Settings\SettingsRepositoryInterface $settings): int
    {
        $value = (int) $settings->get(self::MAX_ITEMS);

        return $value > 0 ? min($value, self::CEILING_ITEMS) : self::DEFAULT_MAX_ITEMS;
    }

    /** The rows a new list starts with. */
    public const DEFAULT_TIERS = [
        ['label' => 'S', 'color' => '#ff7f7f'],
        ['label' => 'A', 'color' => '#ffbf7f'],
        ['label' => 'B', 'color' => '#ffdf7f'],
        ['label' => 'C', 'color' => '#ffff7f'],
        ['label' => 'D', 'color' => '#bfff7f'],
        ['label' => 'F', 'color' => '#7fbfff'],
    ];
}
