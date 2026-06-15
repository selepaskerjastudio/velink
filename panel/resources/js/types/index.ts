import { LucideIcon } from 'lucide-react';

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
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    flash: {
        plainAgentToken: string | null;
        installCommand: string | null;
    };
    [key: string]: unknown;
}

export interface Server {
    id: number;
    name: string;
    hostname: string | null;
    public_ip: string | null;
    private_ip: string | null;
    os: string | null;
    status: string;
    agent_version: string | null;
    last_seen_at: string | null;
    created_at?: string;
    updated_at?: string;
}

export type AgentJobStatus = 'pending' | 'dispatched' | 'running' | 'succeeded' | 'failed' | 'timeout';

export interface AgentJob {
    uuid: string;
    type: string;
    label: string | null;
    status: AgentJobStatus;
    exit_code: number | null;
    output: string | null;
    created_at?: string;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}
