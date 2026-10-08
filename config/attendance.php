<?php

return [
    // A member whose clock-in was marked "late" and who clocks out more
    // than this many minutes before the expected end of their day is
    // marked "half_day". Change this one number to tune the rule.
    'early_leave_minutes' => 120,
];