<?php

declare(strict_types=1);

use YellowTwins\Snapshot\Command\DoctorCommand;
use YellowTwins\Snapshot\Command\ListEnvironmentsCommand;
use YellowTwins\Snapshot\Command\PullCommand;

return [
    'snapshot:pull' => [
        'class' => PullCommand::class,
    ],
    'snapshot:doctor' => [
        'class' => DoctorCommand::class,
    ],
    'snapshot:list-envs' => [
        'class' => ListEnvironmentsCommand::class,
    ],
];
