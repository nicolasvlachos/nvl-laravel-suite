function roleLabel(role: Nvl.Auth.Data.Display.RoleOptionData): string {
    return role.label;
}

function permissionLabel(permission: Nvl.Auth.Data.Display.PermissionOptionData): string {
    return permission.label;
}

function activePrincipalCount(analytics: Nvl.Auth.Data.Display.RoleAnalyticsData): number {
    return analytics.activeUsers;
}

function tenantMembershipOwner(membership: Nvl.Auth.Data.Display.TenantMembershipData): boolean {
    return membership.owner && membership.status === 'active';
}

const enableConsumer = {
    key: 'consumer.enabled',
    value: true,
    expectedRevision: 0,
} satisfies Nvl.Settings.Data.SettingMutationData;

function effectiveSetting(setting: Nvl.Settings.Data.SettingValueData): unknown {
    return setting.value;
}

void [
    roleLabel,
    permissionLabel,
    activePrincipalCount,
    tenantMembershipOwner,
    enableConsumer,
    effectiveSetting,
];
