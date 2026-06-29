<?php

return [
    'user_model' => null,

    'tables' => [
        'modules' => 'core_modules',
        'access_nodes' => 'core_access_nodes',
        'teams' => 'core_teams',
        'team_members' => 'core_team_members',
        'team_member_roles' => 'core_team_member_roles',
        'team_roles' => 'core_team_roles',
        'team_scopes' => 'core_team_scopes',
        'resource_grants' => 'core_resource_grants',
        'scope_entity_providers' => 'core_scope_entity_providers',
        'access_node_scope_rules' => 'core_access_node_scope_rules',
    ],

    'operator_global_permissions' => [
        'core.operator_global',
    ],

    'module_permission_suffix' => '.module.view',
];
