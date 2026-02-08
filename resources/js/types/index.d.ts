import { InertiaLinkProps } from '@inertiajs/react';
import type { IconDefinition } from '@fortawesome/fontawesome-svg-core';
import type { ScoreColorConfig } from './score-colors';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: IconDefinition | null;
    isActive?: boolean;
}

export interface Tenant {
    id: number;
    uuid: string;
}

export interface SharedData {
    name: string;
    auth: Auth;
    tenant: Tenant | null;
    viewingAs: number | null;
    locale: 'en' | 'sv';
    appEnv: string;
    sidebarOpen: boolean;
    scoreColors: ScoreColorConfig;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
}
