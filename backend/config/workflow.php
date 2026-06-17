<?php

return [
    'states' => ['draft', 'in_review', 'approved', 'scheduled', 'published', 'archived'],
    'default_state' => 'draft',
    'transitions' => [
        'draft' => ['in_review', 'archived'],
        'in_review' => ['approved', 'draft'],
        'approved' => ['scheduled', 'published', 'draft'],
        'scheduled' => ['published', 'draft'],
        'published' => ['archived', 'draft'],
        'archived' => ['draft'],
    ],
];
