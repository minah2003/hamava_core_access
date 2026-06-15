<?php

return [
    'user_model' => null,

    'tables' => [
        'modules' => 'core_modules',
        'access_nodes' => 'core_access_nodes',
        'teams' => 'core_teams',
        'team_members' => 'core_team_members',
        'team_member_roles' => 'core_team_member_roles',
        'team_scopes' => 'core_team_scopes',
        'resource_grants' => 'core_resource_grants',
    ],

    'operator_global_permissions' => [
        'core.operator_global',
    ],

    'module_permission_suffix' => '.module.view',
];
