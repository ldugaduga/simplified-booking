import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;

export interface AvailabilityRule {
    weekday: number;
    start_time: string;
    end_time: string;
}

export interface BookingSettings {
    timezone: string;
    slot_minutes: number;
    buffer_minutes: number;
    min_notice_hours: number;
    max_days_ahead: number;
}

export interface BlockedDate {
    id: number;
    date: string;
    reason: string | null;
    is_past: boolean;
}
