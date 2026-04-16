<?php

namespace App\Services\Channel;

class RoomSwitchDecisionService
{
    /**
     * availability 任意一天 status=1 视为开房，否则关房。
     *
     * @param array<int, array{date:string,status:int}> $availability
     */
    public function shouldOpen(array $availability): bool
    {
        return collect($availability)
            ->contains(fn (array $item): bool => ((int) ($item['status'] ?? 0)) === 1);
    }
}
