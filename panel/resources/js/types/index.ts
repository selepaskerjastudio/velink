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
        plainDbUserPassword: string | null;
        plainDbUserUsername: string | null;
    };
    server_provisioning: boolean;
    [key: string]: unknown;
}

export interface Server {
    id: string;
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

export interface ApplicationSummary {
    id: string;
    name: string;
    domain: string | null;
    php_version: string;
    status: string;
}

export interface Application {
    id: string;
    name: string;
    domain: string | null;
    root_path: string;
    directory_size_bytes: number | null;
    linux_user: string;
    php_version: string;
    app_type: string;
    stack_mode: string;
    status: string;
    created_at?: string;
    env_content: string | null;
    repository: string | null;
    branch: string;
    deploy_mode: string;
    deploy_script: string | null;
    git_credential_id: string | null;
    webhook_secret: string | null;
    webhook_url: string;
    webhook_url_gitlab: string;
    ssl_enabled: boolean;
    ssl_enabled_at?: string | null;
    ssl_provider: string | null;
}

export interface GitCredential {
    id: string;
    account_username: string | null;
    created_at?: string;
    provider: {
        type: string;
        name: string;
    };
}

export interface SshKeyServerDeployment {
    id: string;
    name: string;
    public_ip: string | null;
}

export interface SshKey {
    id: string;
    name: string;
    fingerprint: string;
    type: string;
    comment: string | null;
    created_at?: string;
    servers: SshKeyServerDeployment[];
}

export interface SystemUserSummary {
    id: string;
    username: string;
    shell: string;
    is_sudo: boolean;
    is_system_reserved: boolean;
    ssh_keys_count: number;
}

export type DeploymentStatus = 'pending' | 'running' | 'success' | 'failed';

export interface Deployment {
    id: string;
    branch: string | null;
    mode: string;
    status: DeploymentStatus;
    triggered_by: string;
    agent_job_uuid: string | null;
    log: string | null;
    started_at: string | null;
    finished_at: string | null;
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

export type WorkerStatus = 'unknown' | 'running' | 'stopped';

export interface WorkerSummary {
    id: number;
    name: string;
    command: string;
    status: WorkerStatus;
    config: { numprocs?: number } | null;
}

export type SystemdServiceStatus =
    | 'waiting'
    | 'installing'
    | 'running'
    | 'stopped'
    | 'restarting'
    | 'not_installed'
    // legacy values still possible on older rows
    | 'unknown'
    | 'active'
    | 'inactive'
    | 'failed';

export interface SystemdService {
    id: number;
    name: string;
    type?: 'systemd' | 'tool';
    status: SystemdServiceStatus;
    config: { enabled?: boolean; label?: string; component?: string; php_version?: string } | null;
    cpu_percent: number | null;
    memory_usage: number | null;
}

export type DatabaseEngine = 'mysql' | 'mariadb' | 'postgres' | 'mongodb';

export interface DatabaseInstanceSummary {
    id: string;
    engine: DatabaseEngine;
    name: string;
    charset: string | null;
    collation: string | null;
    created_at: string | null;
}

export type CronJobStatus = 'active' | 'paused';

export interface CronJobSummary {
    id: number;
    application_id: number | null;
    application_name: string | null;
    user: string;
    command: string;
    schedule: string;
    status: CronJobStatus;
    last_run_at: string | null;
}

export interface CronApplicationOption {
    id: number;
    name: string;
    linux_user: string;
}

export interface ServerMetricPoint {
    cpu: number;
    ram: number;
    disk: number;
    load1: number;
    ts: string;
}

export type DatabaseGrants = Record<string, string[]>;

export interface DatabaseUserSummary {
    id: string;
    engine: DatabaseEngine;
    username: string;
    host: string;
    grants: DatabaseGrants | null;
}
