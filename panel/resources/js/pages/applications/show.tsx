import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import echo from '@/echo';
import ServerLayout from '@/layouts/server-layout';
import { cn } from '@/lib/utils';
import {
    type AgentJob,
    type AgentJobStatus,
    type Application,
    type BreadcrumbItem,
    type Deployment,
    type DeploymentStatus,
    type GitCredential,
    type PhpSettings,
} from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ChevronDownIcon, ExternalLinkIcon, ShieldCheckIcon, TerminalIcon, Trash2Icon, TriangleAlertIcon } from 'lucide-react';
import { type FormEvent, useEffect, useRef, useState } from 'react';

const PROVIDER_LABELS: Record<string, string> = {
    github: 'GitHub',
    gitlab: 'GitLab',
};

const NO_CREDENTIAL = 'none';

type SidebarItem = { id: string; label: string } | { header: string };

const SECTIONS: SidebarItem[] = [
    { id: 'dashboard', label: 'Dashboard' },
    { id: 'ssl', label: 'SSL / HTTPS' },
    { id: 'deploy', label: 'Git & Deploy' },
    { header: 'Web Settings' },
    { id: 'nginx', label: 'NGINX Config' },
    { id: 'php-fpm', label: 'PHP & FPM' },
    { id: 'settings', label: 'Settings' },
    { id: 'backups', label: 'Backups' },
    { id: 'activity', label: 'Activity Log' },
];

const SECTION_IDS = SECTIONS.filter((s): s is { id: string; label: string } => 'id' in s).map((s) => s.id);
const DEFAULT_SECTION = 'dashboard';

function sectionFromUrl(): string {
    if (typeof window === 'undefined') return DEFAULT_SECTION;
    const tab = new URLSearchParams(window.location.search).get('tab');
    return tab && SECTION_IDS.includes(tab) ? tab : DEFAULT_SECTION;
}

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' | 'success' {
    switch (status) {
        case 'succeeded':
        case 'success':
            return 'success';
        case 'failed':
        case 'timeout':
            return 'destructive';
        case 'running':
            return 'outline';
        default:
            return 'secondary';
    }
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes / 1024;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }
    return `${value.toFixed(value >= 10 ? 0 : 1)} ${units[unit]}`;
}

function deploymentStatusFromJob(status: AgentJobStatus): DeploymentStatus {
    switch (status) {
        case 'succeeded':
            return 'success';
        case 'failed':
        case 'timeout':
            return 'failed';
        default:
            return 'running';
    }
}

interface AgentJobUpdatedEvent {
    uuid: string;
    type: string;
    label: string | null;
    status: AgentJobStatus;
    exit_code: number | null;
    output: string | null;
}

export default function ApplicationsShow({
    application,
    server,
    phpVersions,
    phpSettingsPresets,
    jobs,
    deployments,
    gitCredentials,
    defaultDeployScript,
}: {
    application: Application;
    server: { id: string; name: string; public_ip?: string | null; status: string; os?: string | null; uses_edge_proxy?: boolean };
    phpVersions: string[];
    phpSettingsPresets: Record<string, PhpSettings>;
    jobs: AgentJob[];
    deployments: Deployment[];
    gitCredentials: GitCredential[];
    defaultDeployScript: string;
}) {
    const { errors: pageErrors } = usePage().props as { errors: Record<string, string> };

    const availablePhpVersions = (() => {
        const m = server.os?.match(/Ubuntu\s+(\d+)/i);
        return m && parseInt(m[1], 10) >= 24 ? phpVersions.filter((v) => v !== '7.4') : phpVersions;
    })();

    const [section, setSection] = useState(sectionFromUrl);
    const publicPath = `${application.root_path}/public`;

    // Keep the active tab in the URL (?tab=) so switching updates the link and a
    // refresh restores the same tab. pushState also makes back/forward move tabs.
    const selectSection = (id: string) => {
        setSection(id);
        if (typeof window === 'undefined') return;
        const url = new URL(window.location.href);
        url.searchParams.set('tab', id);
        window.history.pushState({}, '', url);
    };

    useEffect(() => {
        const onPop = () => setSection(sectionFromUrl());
        window.addEventListener('popstate', onPop);
        return () => window.removeEventListener('popstate', onPop);
    }, []);

    const [liveJobs, setLiveJobs] = useState<AgentJob[]>(jobs ?? []);
    const [liveDeployments, setLiveDeployments] = useState<Deployment[]>(deployments ?? []);

    useEffect(() => setLiveJobs(jobs ?? []), [jobs]);
    useEffect(() => setLiveDeployments(deployments ?? []), [deployments]);

    useEffect(() => {
        const channel = echo.private(`server.${server.id}`);

        channel.listen('.agent-job.updated', (event: AgentJobUpdatedEvent) => {
            setLiveJobs((current) => {
                const index = current.findIndex((job) => job.uuid === event.uuid);
                if (index === -1) {
                    return current;
                }
                const next = [...current];
                next[index] = { ...next[index], ...event };
                return next;
            });

            setLiveDeployments((current) => {
                const index = current.findIndex((deployment) => deployment.agent_job_uuid === event.uuid);
                if (index === -1) {
                    return current;
                }
                const next = [...current];
                next[index] = { ...next[index], status: deploymentStatusFromJob(event.status), log: event.output };
                return next;
            });
        });

        return () => {
            echo.leave(`server.${server.id}`);
        };
    }, [server.id]);

    const phpForm = useForm<{ php_version: string }>({
        php_version: application.php_version,
    });

    const submitPhpVersion = () => {
        phpForm.patch(route('applications.php-version', application.id), { preserveScroll: true });
    };

    const domainForm = useForm<{ domain: string }>({
        domain: application.domain ?? '',
    });

    const submitDomain = () => {
        domainForm.transform((data) => ({
            domain: data.domain.trim() || undefined,
        }));
        domainForm.patch(route('applications.domain', application.id), { preserveScroll: true });
    };

    const envForm = useForm<{ env_content: string }>({
        env_content: application.env_content ?? '',
    });

    const submitEnv = () => {
        envForm.patch(route('applications.env', application.id), { preserveScroll: true });
    };

    const deployForm = useForm<{
        repository: string;
        branch: string;
        deploy_mode: string;
        git_credential_id: string;
        deploy_script: string;
    }>({
        repository: application.repository ?? '',
        branch: application.branch,
        deploy_mode: application.deploy_mode,
        git_credential_id: application.git_credential_id ? String(application.git_credential_id) : NO_CREDENTIAL,
        deploy_script: application.deploy_script ?? defaultDeployScript,
    });

    const submitDeploySettings = () => {
        deployForm.transform((data) => ({
            ...data,
            git_credential_id: data.git_credential_id === NO_CREDENTIAL ? null : data.git_credential_id,
        }));
        deployForm.patch(route('applications.deploy-settings', application.id), { preserveScroll: true });
    };

    const sslForm = useForm({});

    const submitSsl = () => {
        sslForm.post(route('applications.ssl', application.id), { preserveScroll: true });
    };

    const nginxForm = useForm<{ config: string }>({
        config: '',
    });

    const submitNginx = () => {
        nginxForm.post(route('applications.nginx-config', application.id), { preserveScroll: true });
    };

    const defaultPhpSettings: PhpSettings = {
        pm: 'dynamic',
        pm_max_children: 5,
        pm_start_servers: 2,
        pm_min_spare_servers: 1,
        pm_max_spare_servers: 3,
        pm_max_requests: 0,
        pm_process_idle_timeout: '10s',
        memory_limit: '128M',
        max_execution_time: 30,
        max_input_time: 60,
        upload_max_filesize: '2M',
        post_max_size: '8M',
    };

    const phpSettingsForm = useForm<PhpSettings>(application.php_settings ?? defaultPhpSettings);

    const submitPhpSettings = () => {
        phpSettingsForm.patch(route('applications.php-settings', application.id), { preserveScroll: true });
    };

    const applyPreset = (preset: PhpSettings) => {
        phpSettingsForm.setData(preset);
    };

    const sizeForm = useForm({});

    const [deleteOpen, setDeleteOpen] = useState(false);
    const deleteForm = useForm<{ confirmation: string }>({ confirmation: '' });

    const closeDeleteModal = () => {
        setDeleteOpen(false);
        deleteForm.reset();
        deleteForm.clearErrors();
    };

    const submitDelete = (e: FormEvent) => {
        e.preventDefault();
        deleteForm.delete(route('applications.destroy', application.id), {
            preserveScroll: true,
            onError: () => deleteForm.reset(),
        });
    };

    const deployNowForm = useForm({});

    const submitDeployNow = () => {
        deployNowForm.post(route('applications.deployments.store', application.id), { preserveScroll: true });
    };

    type RepoOption = { full_name: string; private: boolean; default_branch: string };
    const [repoOptions, setRepoOptions] = useState<RepoOption[]>([]);
    const [repoLoading, setRepoLoading] = useState(false);
    const [repoOpen, setRepoOpen] = useState(false);
    const repoTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const selectedCredential = gitCredentials.find((c) => c.id === deployForm.data.git_credential_id);
    const isGitHub = selectedCredential?.provider.type === 'github';

    // Which webhook provider to show. Follow the selected credential's provider;
    // with no credential (public repo) we can't tell, so show both.
    const webhookProvider = selectedCredential?.provider.type;
    const showGitHubWebhook = webhookProvider === undefined || webhookProvider === 'github';
    const showGitLabWebhook = webhookProvider === undefined || webhookProvider === 'gitlab';

    useEffect(() => {
        setRepoOptions([]);
        setRepoOpen(false);
    }, [deployForm.data.git_credential_id]);

    const fetchRepos = (query: string, credentialId: string) => {
        setRepoLoading(true);
        const url = new URL(route('github.repos'), window.location.origin);
        url.searchParams.set('credential', credentialId);
        if (query) url.searchParams.set('q', query);
        fetch(url.toString(), { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then((r) => r.json())
            .then((data) => {
                setRepoOptions(data.repos ?? []);
                setRepoLoading(false);
            })
            .catch(() => setRepoLoading(false));
    };

    const handleRepoInput = (value: string) => {
        deployForm.setData('repository', value);
        setRepoOpen(true);
        if (repoTimerRef.current) clearTimeout(repoTimerRef.current);
        repoTimerRef.current = setTimeout(() => {
            if (isGitHub && deployForm.data.git_credential_id !== NO_CREDENTIAL) {
                fetchRepos(value, deployForm.data.git_credential_id);
            }
        }, 300);
    };

    const handleRepoFocus = () => {
        if (!isGitHub) return;
        setRepoOpen(true);
        if (repoOptions.length === 0) fetchRepos(deployForm.data.repository, deployForm.data.git_credential_id);
    };

    const selectRepo = (repo: RepoOption) => {
        deployForm.setData('repository', repo.full_name);
        deployForm.setData('branch', repo.default_branch);
        setRepoOpen(false);
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: application.name, href: `/apps/${application.id}` },
    ];

    return (
        <ServerLayout
            breadcrumbs={breadcrumbs}
            server={{ id: server.id, name: server.name, public_ip: server.public_ip ?? null, status: server.status }}
        >
            <Head title={application.name} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex flex-col gap-1.5">
                        <h1 className="text-xl font-semibold">{application.name}</h1>
                        <div className="text-muted-foreground flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
                            <span className="font-mono">{application.linux_user}</span>
                            {application.app_type !== 'static' && <span>PHP {application.php_version}</span>}
                            <span className="capitalize">{application.app_type}</span>
                            <Badge variant={statusVariant(application.status)}>{application.status}</Badge>
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        {application.domain && (
                            <Button asChild variant="outline" size="sm">
                                <a href={`${application.ssl_enabled ? 'https' : 'http'}://${application.domain}`} target="_blank" rel="noreferrer">
                                    <ExternalLinkIcon className="h-4 w-4" />
                                    Open Site
                                </a>
                            </Button>
                        )}
                        <Button asChild variant="outline" size="sm">
                            <Link href={route('workers.index', application.id)}>Workers</Link>
                        </Button>
                    </div>
                </div>

                {server.status !== 'online' && (
                    <Alert variant="destructive">
                        <TriangleAlertIcon className="h-4 w-4" />
                        <AlertTitle>Server offline</AlertTitle>
                        <AlertDescription>
                            The agent on this server is not connected. Actions that require the agent (deploy, PHP switch, .env write, SSL) will be
                            queued but won't run until the server reconnects.
                        </AlertDescription>
                    </Alert>
                )}

                <div className="flex flex-col gap-4 lg:flex-row lg:items-start">
                    {/* App-scoped sidebar */}
                    <nav className="flex flex-row flex-wrap gap-1 lg:w-52 lg:shrink-0 lg:flex-col">
                        {SECTIONS.map((item) =>
                            'header' in item ? (
                                <p
                                    key={`h-${item.header}`}
                                    className="text-muted-foreground px-3 pt-3 pb-1 text-xs font-medium tracking-wide uppercase"
                                >
                                    {item.header}
                                </p>
                            ) : (
                                <button
                                    key={item.id}
                                    type="button"
                                    onClick={() => selectSection(item.id)}
                                    className={cn(
                                        'rounded-md px-3 py-2 text-left text-sm transition-colors',
                                        section === item.id ? 'bg-muted text-foreground font-medium' : 'text-muted-foreground hover:bg-muted/50',
                                    )}
                                >
                                    {item.label}
                                </button>
                            ),
                        )}
                    </nav>

                    {/* Section content */}
                    <div className="flex min-w-0 flex-1 flex-col gap-4">
                        {section === 'dashboard' && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Summary</CardTitle>
                                </CardHeader>
                                <CardContent className="grid gap-2 text-sm">
                                    <div className="flex justify-between gap-4">
                                        <span className="text-muted-foreground">Domain</span>
                                        <span className="truncate text-right">{application.domain ?? '—'}</span>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <span className="text-muted-foreground">Application type</span>
                                        <span className="capitalize">{application.app_type}</span>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <span className="text-muted-foreground">Mode</span>
                                        <span className="capitalize">{application.stack_mode}</span>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <span className="text-muted-foreground">Root path</span>
                                        <span className="truncate text-right font-mono">{application.root_path}</span>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <span className="text-muted-foreground">Public path</span>
                                        <span className="truncate text-right font-mono">{publicPath}</span>
                                    </div>
                                    <div className="flex items-center justify-between gap-4">
                                        <span className="text-muted-foreground">Directory size</span>
                                        <span className="flex items-center gap-2">
                                            <span>{application.directory_size_bytes ? formatBytes(application.directory_size_bytes) : '—'}</span>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                disabled={sizeForm.processing}
                                                onClick={() =>
                                                    sizeForm.post(route('applications.directory-size', application.id), { preserveScroll: true })
                                                }
                                            >
                                                {sizeForm.processing ? 'Measuring…' : 'Refresh'}
                                            </Button>
                                        </span>
                                    </div>
                                    {application.app_type !== 'static' && (
                                        <div className="flex justify-between gap-4">
                                            <span className="text-muted-foreground">PHP version</span>
                                            <span>{application.php_version}</span>
                                        </div>
                                    )}
                                    <div className="flex justify-between gap-4">
                                        <span className="text-muted-foreground">Linux user</span>
                                        <span className="font-mono">{application.linux_user}</span>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <span className="text-muted-foreground">SSL provider</span>
                                        <span>{application.ssl_enabled ? "Let's Encrypt" : 'Not enabled'}</span>
                                    </div>
                                    {application.repository && (
                                        <div className="flex justify-between gap-4">
                                            <span className="text-muted-foreground">Repository</span>
                                            <span className="truncate text-right font-mono">{application.repository}</span>
                                        </div>
                                    )}
                                    <div className="flex justify-between gap-4">
                                        <span className="text-muted-foreground">Branch</span>
                                        <span className="font-mono">{application.branch}</span>
                                    </div>
                                    <div className="flex justify-between gap-4">
                                        <span className="text-muted-foreground">Status</span>
                                        <Badge variant={statusVariant(application.status)}>{application.status}</Badge>
                                    </div>
                                </CardContent>
                            </Card>
                        )}

                        {section === 'settings' && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Domain</CardTitle>
                                    <CardDescription>
                                        The domain serving this application. Changing it rewrites the nginx config and invalidates any existing SSL
                                        certificate.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-3">
                                    <Input
                                        value={domainForm.data.domain}
                                        onChange={(e) => domainForm.setData('domain', e.target.value)}
                                        placeholder="app.example.com"
                                        className="font-mono"
                                    />
                                    <InputError message={domainForm.errors.domain} />
                                    {application.ssl_enabled && (
                                        <p className="text-xs text-yellow-600">
                                            ⚠ SSL is currently active — changing the domain will invalidate the certificate.
                                        </p>
                                    )}
                                    <Button onClick={submitDomain} disabled={domainForm.processing} size="sm">
                                        {domainForm.processing ? 'Saving…' : 'Save domain'}
                                    </Button>
                                </CardContent>
                            </Card>
                        )}

                        {section === 'settings' && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>PHP version</CardTitle>
                                    <CardDescription>Switch the PHP-FPM pool used by this application. Nginx is not reloaded.</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <Select value={phpForm.data.php_version} onValueChange={(value) => phpForm.setData('php_version', value)}>
                                        <SelectTrigger className="max-w-40">
                                            <SelectValue placeholder="Select a PHP version" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {availablePhpVersions.map((version) => (
                                                <SelectItem key={version} value={version}>
                                                    PHP {version}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </CardContent>
                                <CardFooter>
                                    <Button
                                        onClick={submitPhpVersion}
                                        disabled={phpForm.processing || phpForm.data.php_version === application.php_version}
                                    >
                                        Switch PHP version
                                    </Button>
                                </CardFooter>
                            </Card>
                        )}

                        {section === 'ssl' && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>SSL / HTTPS</CardTitle>
                                    <CardDescription>
                                        Issue a free Let's Encrypt certificate via certbot. Requires certbot to be installed on the server and the
                                        domain to point to this server.
                                    </CardDescription>
                                </CardHeader>
                                {server.uses_edge_proxy ? (
                                    <CardContent>
                                        <p className="text-muted-foreground text-sm">
                                            TLS for this server is handled by the edge proxy (Caddy) — a certificate is issued automatically on first
                                            request. Point <strong>{application.domain ?? 'the domain'}</strong> at the edge's public IP. Per-app
                                            certbot is disabled here.
                                        </p>
                                    </CardContent>
                                ) : application.ssl_enabled ? (
                                    <>
                                        <CardContent>
                                            <div className="flex flex-wrap items-center gap-2 text-sm">
                                                <Badge variant="success" className="gap-1">
                                                    <ShieldCheckIcon className="h-3.5 w-3.5" />
                                                    HTTPS active
                                                </Badge>
                                                <span className="text-muted-foreground">
                                                    Let's Encrypt
                                                    {application.ssl_enabled_at
                                                        ? ` · since ${new Date(application.ssl_enabled_at).toLocaleDateString()}`
                                                        : ''}
                                                </span>
                                            </div>
                                        </CardContent>
                                        <CardFooter>
                                            <Button variant="outline" onClick={submitSsl} disabled={sslForm.processing || !application.domain}>
                                                Re-issue certificate
                                            </Button>
                                        </CardFooter>
                                    </>
                                ) : (
                                    <CardFooter>
                                        <div className="flex flex-col gap-1">
                                            <Button
                                                variant="secondary"
                                                onClick={submitSsl}
                                                disabled={sslForm.processing || !application.domain || application.status === 'pending'}
                                            >
                                                Enable SSL
                                            </Button>
                                            {!application.domain && <p className="text-muted-foreground text-xs">No domain set</p>}
                                        </div>
                                    </CardFooter>
                                )}
                            </Card>
                        )}

                        {section === 'nginx' && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>NGINX configuration</CardTitle>
                                    <CardDescription>
                                        Written to{' '}
                                        <code className="text-xs">
                                            /etc/nginx/sites-available/{application.domain ?? application.linux_user}.conf
                                        </code>{' '}
                                        and reloaded via <code className="text-xs">nginx -t &amp;&amp; systemctl reload nginx</code>.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid gap-2">
                                    <Textarea
                                        value={nginxForm.data.config}
                                        onChange={(e) => nginxForm.setData('config', e.target.value)}
                                        rows={16}
                                        placeholder={`server {\n    listen 80;\n    server_name ${application.domain ?? 'example.com'};\n    root ${application.root_path}/public;\n    ...\n}`}
                                        className="font-mono text-sm"
                                        spellCheck={false}
                                    />
                                    <InputError message={nginxForm.errors.config} />
                                    <p className="text-muted-foreground text-xs">
                                        Paste your full server block. The agent validates the syntax before reloading.
                                    </p>
                                </CardContent>
                                <CardFooter>
                                    <Button onClick={submitNginx} disabled={nginxForm.processing}>
                                        Save &amp; reload NGINX
                                    </Button>
                                </CardFooter>
                            </Card>
                        )}

                        {section === 'php-fpm' && application.app_type !== 'static' && (
                            <div className="grid gap-6">
                                <Card>
                                    <CardHeader>
                                        <CardTitle>PHP-FPM Process Manager</CardTitle>
                                        <CardDescription>
                                            Tuning for the <code className="text-xs">{application.app_slug}</code> pool. Changes rewrite{' '}
                                            <code className="text-xs">
                                                /etc/php/{application.php_version}/fpm/pool.d/{application.app_slug}.conf
                                            </code>{' '}
                                            and reload PHP-FPM.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="grid gap-4">
                                        <div className="flex flex-wrap gap-2">
                                            <span className="text-muted-foreground self-center text-xs">Presets:</span>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => applyPreset(phpSettingsPresets.low ?? defaultPhpSettings)}
                                            >
                                                Low traffic
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => applyPreset(phpSettingsPresets.medium ?? defaultPhpSettings)}
                                            >
                                                Medium traffic
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => applyPreset(phpSettingsPresets.high ?? defaultPhpSettings)}
                                            >
                                                High traffic
                                            </Button>
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="pm">Process manager (pm)</Label>
                                            <Select
                                                value={phpSettingsForm.data.pm}
                                                onValueChange={(v) => phpSettingsForm.setData('pm', v)}
                                            >
                                                <SelectTrigger id="pm" className="max-w-xs">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="dynamic">dynamic</SelectItem>
                                                    <SelectItem value="static">static</SelectItem>
                                                    <SelectItem value="ondemand">ondemand</SelectItem>
                                                </SelectContent>
                                            </Select>
                                            <p className="text-muted-foreground text-xs">
                                                <code>dynamic</code> spawns workers on demand up to <code>max_children</code>;{' '}
                                                <code>static</code> keeps a fixed count; <code>ondemand</code> idles at zero.
                                            </p>
                                        </div>

                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <div className="grid gap-2">
                                                <Label htmlFor="pm_max_children">pm.max_children</Label>
                                                <Input
                                                    id="pm_max_children"
                                                    type="number"
                                                    min={1}
                                                    className="max-w-xs"
                                                    value={phpSettingsForm.data.pm_max_children}
                                                    onChange={(e) => phpSettingsForm.setData('pm_max_children', e.target.value)}
                                                />
                                                <InputError message={phpSettingsForm.errors.pm_max_children} />
                                            </div>

                                            {phpSettingsForm.data.pm === 'static' && (
                                                <p className="text-muted-foreground self-end text-xs">
                                                    In <code>static</code> mode, <code>max_children</code> is the fixed worker count.
                                                </p>
                                            )}
                                        </div>

                                        {phpSettingsForm.data.pm === 'dynamic' && (
                                            <div className="grid gap-4 sm:grid-cols-3">
                                                <div className="grid gap-2">
                                                    <Label htmlFor="pm_start_servers">pm.start_servers</Label>
                                                    <Input
                                                        id="pm_start_servers"
                                                        type="number"
                                                        min={1}
                                                        value={phpSettingsForm.data.pm_start_servers}
                                                        onChange={(e) => phpSettingsForm.setData('pm_start_servers', e.target.value)}
                                                    />
                                                    <InputError message={phpSettingsForm.errors.pm_start_servers} />
                                                </div>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="pm_min_spare_servers">pm.min_spare_servers</Label>
                                                    <Input
                                                        id="pm_min_spare_servers"
                                                        type="number"
                                                        min={1}
                                                        value={phpSettingsForm.data.pm_min_spare_servers}
                                                        onChange={(e) => phpSettingsForm.setData('pm_min_spare_servers', e.target.value)}
                                                    />
                                                    <InputError message={phpSettingsForm.errors.pm_min_spare_servers} />
                                                </div>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="pm_max_spare_servers">pm.max_spare_servers</Label>
                                                    <Input
                                                        id="pm_max_spare_servers"
                                                        type="number"
                                                        min={1}
                                                        value={phpSettingsForm.data.pm_max_spare_servers}
                                                        onChange={(e) => phpSettingsForm.setData('pm_max_spare_servers', e.target.value)}
                                                    />
                                                    <InputError message={phpSettingsForm.errors.pm_max_spare_servers} />
                                                </div>
                                            </div>
                                        )}

                                        <div className="grid gap-4 sm:grid-cols-2">
                                            <div className="grid gap-2">
                                                <Label htmlFor="pm_max_requests">pm.max_requests</Label>
                                                <Input
                                                    id="pm_max_requests"
                                                    type="number"
                                                    min={0}
                                                    className="max-w-xs"
                                                    value={phpSettingsForm.data.pm_max_requests}
                                                    onChange={(e) => phpSettingsForm.setData('pm_max_requests', e.target.value)}
                                                />
                                                <InputError message={phpSettingsForm.errors.pm_max_requests} />
                                                <p className="text-muted-foreground text-xs">0 = unlimited. Restarts a worker after N requests (guards against memory leaks).</p>
                                            </div>

                                            {phpSettingsForm.data.pm === 'ondemand' && (
                                                <div className="grid gap-2">
                                                    <Label htmlFor="pm_process_idle_timeout">pm.process_idle_timeout</Label>
                                                    <Input
                                                        id="pm_process_idle_timeout"
                                                        className="max-w-xs"
                                                        value={phpSettingsForm.data.pm_process_idle_timeout}
                                                        onChange={(e) => phpSettingsForm.setData('pm_process_idle_timeout', e.target.value)}
                                                    />
                                                    <InputError message={phpSettingsForm.errors.pm_process_idle_timeout} />
                                                    <p className="text-muted-foreground text-xs">e.g. <code>10s</code>. Idle workers are killed after this duration.</p>
                                                </div>
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardHeader>
                                        <CardTitle>PHP Limits</CardTitle>
                                        <CardDescription>Per-pool ini values applied via <code className="text-xs">php_admin_value</code> (not overridable by the app).</CardDescription>
                                    </CardHeader>
                                    <CardContent className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="memory_limit">memory_limit</Label>
                                            <Input
                                                id="memory_limit"
                                                className="max-w-xs font-mono"
                                                value={phpSettingsForm.data.memory_limit}
                                                onChange={(e) => phpSettingsForm.setData('memory_limit', e.target.value)}
                                            />
                                            <InputError message={phpSettingsForm.errors.memory_limit} />
                                            <p className="text-muted-foreground text-xs">e.g. <code>128M</code>, <code>512M</code>, or <code>-1</code> for no limit.</p>
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="max_execution_time">max_execution_time</Label>
                                            <Input
                                                id="max_execution_time"
                                                type="number"
                                                min={0}
                                                className="max-w-xs"
                                                value={phpSettingsForm.data.max_execution_time}
                                                onChange={(e) => phpSettingsForm.setData('max_execution_time', e.target.value)}
                                            />
                                            <InputError message={phpSettingsForm.errors.max_execution_time} />
                                            <p className="text-muted-foreground text-xs">Seconds. 0 = no limit.</p>
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="max_input_time">max_input_time</Label>
                                            <Input
                                                id="max_input_time"
                                                type="number"
                                                min={0}
                                                className="max-w-xs"
                                                value={phpSettingsForm.data.max_input_time}
                                                onChange={(e) => phpSettingsForm.setData('max_input_time', e.target.value)}
                                            />
                                            <InputError message={phpSettingsForm.errors.max_input_time} />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="upload_max_filesize">upload_max_filesize</Label>
                                            <Input
                                                id="upload_max_filesize"
                                                className="max-w-xs font-mono"
                                                value={phpSettingsForm.data.upload_max_filesize}
                                                onChange={(e) => phpSettingsForm.setData('upload_max_filesize', e.target.value)}
                                            />
                                            <InputError message={phpSettingsForm.errors.upload_max_filesize} />
                                            <p className="text-muted-foreground text-xs">e.g. <code>2M</code>, <code>64M</code>.</p>
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="post_max_size">post_max_size</Label>
                                            <Input
                                                id="post_max_size"
                                                className="max-w-xs font-mono"
                                                value={phpSettingsForm.data.post_max_size}
                                                onChange={(e) => phpSettingsForm.setData('post_max_size', e.target.value)}
                                            />
                                            <InputError message={phpSettingsForm.errors.post_max_size} />
                                            <p className="text-muted-foreground text-xs">Should be ≥ <code>upload_max_filesize</code>.</p>
                                        </div>
                                    </CardContent>
                                    <CardFooter>
                                        <Button onClick={submitPhpSettings} disabled={phpSettingsForm.processing}>
                                            Save &amp; reload PHP-FPM
                                        </Button>
                                    </CardFooter>
                                </Card>
                            </div>
                        )}

                        {section === 'settings' && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Environment (.env)</CardTitle>
                                    <CardDescription>Written to {application.root_path}/.env on the server.</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <Textarea
                                        value={envForm.data.env_content}
                                        onChange={(e) => envForm.setData('env_content', e.target.value)}
                                        rows={10}
                                        className="font-mono text-sm"
                                        spellCheck={false}
                                    />
                                </CardContent>
                                <CardFooter>
                                    <Button onClick={submitEnv} disabled={envForm.processing}>
                                        Save .env
                                    </Button>
                                </CardFooter>
                            </Card>
                        )}

                        {section === 'settings' && (
                            <Card className="border-destructive/40">
                                <CardHeader>
                                    <CardTitle className="text-destructive">Danger zone</CardTitle>
                                    <CardDescription>
                                        Permanently delete this application. The nginx vhost, PHP-FPM pool, and web directory ({application.root_path}
                                        ) are removed from the server, along with its workers, cron jobs, and deployment history. This cannot be
                                        undone.
                                    </CardDescription>
                                </CardHeader>
                                <CardFooter>
                                    <Dialog open={deleteOpen} onOpenChange={(open) => (open ? setDeleteOpen(true) : closeDeleteModal())}>
                                        <DialogTrigger asChild>
                                            <Button variant="destructive">
                                                <Trash2Icon className="h-4 w-4" />
                                                Delete application
                                            </Button>
                                        </DialogTrigger>
                                        <DialogContent>
                                            <DialogTitle>Delete {application.name}?</DialogTitle>
                                            <DialogDescription>
                                                This permanently removes the application and its server-side resources. To confirm, type{' '}
                                                <span className="text-foreground font-mono font-semibold">DELETE</span> below.
                                            </DialogDescription>
                                            <form className="space-y-4" onSubmit={submitDelete}>
                                                <div className="grid gap-2">
                                                    <Label htmlFor="confirmation" className="sr-only">
                                                        Type DELETE to confirm
                                                    </Label>
                                                    <Input
                                                        id="confirmation"
                                                        value={deleteForm.data.confirmation}
                                                        onChange={(e) => deleteForm.setData('confirmation', e.target.value)}
                                                        placeholder="DELETE"
                                                        autoComplete="off"
                                                        autoFocus
                                                    />
                                                    <InputError message={deleteForm.errors.confirmation} />
                                                </div>
                                                <DialogFooter>
                                                    <DialogClose asChild>
                                                        <Button type="button" variant="secondary" onClick={closeDeleteModal}>
                                                            Cancel
                                                        </Button>
                                                    </DialogClose>
                                                    <Button
                                                        type="submit"
                                                        variant="destructive"
                                                        disabled={deleteForm.processing || deleteForm.data.confirmation !== 'DELETE'}
                                                    >
                                                        Delete application
                                                    </Button>
                                                </DialogFooter>
                                            </form>
                                        </DialogContent>
                                    </Dialog>
                                </CardFooter>
                            </Card>
                        )}

                        {section === 'deploy' && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Deploy</CardTitle>
                                    <CardDescription>Configure the repository and script used to deploy this application.</CardDescription>
                                </CardHeader>
                                <CardContent className="grid gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="repository">Repository</Label>
                                        {isGitHub ? (
                                            <div className="relative">
                                                <Input
                                                    id="repository"
                                                    value={deployForm.data.repository}
                                                    onChange={(e) => handleRepoInput(e.target.value)}
                                                    onFocus={handleRepoFocus}
                                                    onBlur={() => setTimeout(() => setRepoOpen(false), 150)}
                                                    placeholder="Search repositories…"
                                                    autoComplete="off"
                                                />
                                                {repoOpen && (repoLoading || repoOptions.length > 0) && (
                                                    <div className="bg-popover absolute z-10 mt-1 w-full rounded-md border shadow-md">
                                                        {repoLoading && <div className="text-muted-foreground px-3 py-2 text-sm">Searching…</div>}
                                                        {repoOptions.map((repo) => (
                                                            <button
                                                                key={repo.full_name}
                                                                type="button"
                                                                className="hover:bg-accent flex w-full items-center gap-2 px-3 py-2 text-left text-sm"
                                                                onMouseDown={() => selectRepo(repo)}
                                                            >
                                                                <span className="flex-1">{repo.full_name}</span>
                                                                {repo.private && (
                                                                    <Badge variant="secondary" className="text-xs">
                                                                        private
                                                                    </Badge>
                                                                )}
                                                            </button>
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                        ) : (
                                            <Input
                                                id="repository"
                                                value={deployForm.data.repository}
                                                onChange={(e) => deployForm.setData('repository', e.target.value)}
                                                placeholder="owner/repo"
                                            />
                                        )}
                                        <InputError message={deployForm.errors.repository ?? pageErrors.repository} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="branch">Branch</Label>
                                        <Input
                                            id="branch"
                                            value={deployForm.data.branch}
                                            onChange={(e) => deployForm.setData('branch', e.target.value)}
                                            className="max-w-40"
                                        />
                                        <InputError message={deployForm.errors.branch} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="git_credential_id">Git credential</Label>
                                        <Select
                                            value={deployForm.data.git_credential_id}
                                            onValueChange={(value) => deployForm.setData('git_credential_id', value)}
                                        >
                                            <SelectTrigger id="git_credential_id" className="max-w-72">
                                                <SelectValue placeholder="None (public repository)" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value={NO_CREDENTIAL}>None (public repository)</SelectItem>
                                                {gitCredentials.map((credential) => (
                                                    <SelectItem key={credential.id} value={String(credential.id)}>
                                                        {PROVIDER_LABELS[credential.provider.type] ?? credential.provider.type}
                                                        {credential.account_username ? ` — ${credential.account_username}` : ''}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        <InputError message={deployForm.errors.git_credential_id} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="deploy_mode">Deploy mode</Label>
                                        <Select
                                            value={deployForm.data.deploy_mode}
                                            onValueChange={(value) => deployForm.setData('deploy_mode', value)}
                                        >
                                            <SelectTrigger id="deploy_mode" className="max-w-60">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="inplace">In-place</SelectItem>
                                                <SelectItem value="zero_downtime" disabled>
                                                    Zero-downtime (coming soon)
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        <InputError message={deployForm.errors.deploy_mode} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="deploy_script">Deploy script</Label>
                                        <Textarea
                                            id="deploy_script"
                                            value={deployForm.data.deploy_script}
                                            onChange={(e) => deployForm.setData('deploy_script', e.target.value)}
                                            rows={12}
                                            className="font-mono text-sm"
                                            spellCheck={false}
                                        />
                                        <InputError message={deployForm.errors.deploy_script} />
                                    </div>
                                </CardContent>
                                <CardFooter className="gap-2">
                                    <Button onClick={submitDeploySettings} disabled={deployForm.processing}>
                                        Save deploy settings
                                    </Button>
                                    <Button
                                        variant="secondary"
                                        onClick={submitDeployNow}
                                        disabled={deployNowForm.processing || !application.repository}
                                    >
                                        Deploy now
                                    </Button>
                                </CardFooter>
                            </Card>
                        )}

                        {section === 'deploy' && application.repository && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Auto-deploy webhook</CardTitle>
                                    <CardDescription>
                                        Triggers deployment on push to <code className="font-mono">{application.branch}</code>. Configure in{' '}
                                        {showGitHubWebhook && !showGitLabWebhook
                                            ? 'GitHub'
                                            : showGitLabWebhook && !showGitHubWebhook
                                              ? 'GitLab'
                                              : 'GitHub or GitLab'}
                                        .
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid gap-6">
                                    {/* GitHub section */}
                                    {showGitHubWebhook && (
                                        <div className="grid gap-4">
                                            <p className="text-sm font-medium">GitHub</p>
                                            <p className="text-muted-foreground text-xs">
                                                Add in GitHub → Settings → Webhooks. Set Content type to <code>application/json</code>.
                                            </p>
                                            <div className="grid gap-2">
                                                <Label>Payload URL</Label>
                                                <div className="flex gap-2">
                                                    <Input readOnly value={application.webhook_url} className="font-mono text-xs" />
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => navigator.clipboard.writeText(application.webhook_url)}
                                                    >
                                                        Copy
                                                    </Button>
                                                </div>
                                            </div>
                                            <div className="grid gap-2">
                                                <Label>Secret</Label>
                                                <div className="flex gap-2">
                                                    <Input
                                                        readOnly
                                                        value={application.webhook_secret ?? ''}
                                                        className="font-mono text-xs"
                                                        type="password"
                                                    />
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => navigator.clipboard.writeText(application.webhook_secret ?? '')}
                                                    >
                                                        Copy
                                                    </Button>
                                                </div>
                                            </div>
                                        </div>
                                    )}

                                    {showGitHubWebhook && showGitLabWebhook && <Separator />}

                                    {/* GitLab section */}
                                    {showGitLabWebhook && (
                                        <div className="grid gap-4">
                                            <p className="text-sm font-medium">GitLab</p>
                                            <p className="text-muted-foreground text-xs">
                                                Add in GitLab → Settings → Webhooks. Set Content type to <code>application/json</code>. Secret token =
                                                the value below.
                                            </p>
                                            <div className="grid gap-2">
                                                <Label>URL</Label>
                                                <div className="flex gap-2">
                                                    <Input readOnly value={application.webhook_url_gitlab} className="font-mono text-xs" />
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => navigator.clipboard.writeText(application.webhook_url_gitlab)}
                                                    >
                                                        Copy
                                                    </Button>
                                                </div>
                                            </div>
                                            <div className="grid gap-2">
                                                <Label>Secret token</Label>
                                                <div className="flex gap-2">
                                                    <Input
                                                        readOnly
                                                        value={application.webhook_secret ?? ''}
                                                        className="font-mono text-xs"
                                                        type="password"
                                                    />
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        size="sm"
                                                        onClick={() => navigator.clipboard.writeText(application.webhook_secret ?? '')}
                                                    >
                                                        Copy
                                                    </Button>
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {section === 'activity' && liveDeployments.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Deployment history</CardTitle>
                                    <CardDescription>Live status of deployments triggered for this application.</CardDescription>
                                </CardHeader>
                                <CardContent className="grid gap-2">
                                    {liveDeployments.map((deployment) => (
                                        <Collapsible key={deployment.id}>
                                            <div className="flex items-center justify-between rounded-md border px-3 py-2">
                                                <div className="flex items-center gap-2">
                                                    <span className="text-sm font-medium">{deployment.branch ?? '—'}</span>
                                                    <span className="text-muted-foreground text-xs">
                                                        {deployment.mode} · {deployment.triggered_by}
                                                    </span>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <Badge variant={statusVariant(deployment.status)}>{deployment.status}</Badge>
                                                    <Link href={route('deployments.log', deployment.id)} prefetch className="inline-flex">
                                                        <Button variant="ghost" size="icon" className="h-7 w-7" title="View full log">
                                                            <TerminalIcon className="h-4 w-4" />
                                                        </Button>
                                                    </Link>
                                                    {deployment.log && (
                                                        <CollapsibleTrigger asChild>
                                                            <Button variant="ghost" size="icon" className="h-7 w-7">
                                                                <ChevronDownIcon />
                                                            </Button>
                                                        </CollapsibleTrigger>
                                                    )}
                                                </div>
                                            </div>
                                            {deployment.log && (
                                                <CollapsibleContent>
                                                    <pre className="bg-muted mt-1 max-h-64 overflow-auto rounded-md p-3 text-xs whitespace-pre-wrap">
                                                        {deployment.log}
                                                    </pre>
                                                </CollapsibleContent>
                                            )}
                                        </Collapsible>
                                    ))}
                                </CardContent>
                            </Card>
                        )}

                        {section === 'activity' && liveJobs.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Provisioning progress</CardTitle>
                                    <CardDescription>Live status of jobs dispatched for this application.</CardDescription>
                                </CardHeader>
                                <CardContent className="grid gap-2">
                                    {liveJobs.map((job) => (
                                        <Collapsible key={job.uuid}>
                                            <div className="flex items-center justify-between rounded-md border px-3 py-2">
                                                <div className="flex items-center gap-2">
                                                    <span className="text-sm font-medium">{job.label ?? job.type}</span>
                                                    {job.exit_code !== null && (
                                                        <span className="text-muted-foreground text-xs">exit {job.exit_code}</span>
                                                    )}
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <Badge variant={statusVariant(job.status)}>{job.status}</Badge>
                                                    {job.output && (
                                                        <CollapsibleTrigger asChild>
                                                            <Button variant="ghost" size="icon" className="h-7 w-7">
                                                                <ChevronDownIcon />
                                                            </Button>
                                                        </CollapsibleTrigger>
                                                    )}
                                                </div>
                                            </div>
                                            {job.output && (
                                                <CollapsibleContent>
                                                    <pre className="bg-muted mt-1 max-h-64 overflow-auto rounded-md p-3 text-xs whitespace-pre-wrap">
                                                        {job.output}
                                                    </pre>
                                                </CollapsibleContent>
                                            )}
                                        </Collapsible>
                                    ))}
                                </CardContent>
                            </Card>
                        )}

                        {section === 'activity' && liveDeployments.length === 0 && liveJobs.length === 0 && (
                            <p className="text-muted-foreground text-sm">No activity recorded for this application yet.</p>
                        )}
                    </div>
                </div>
            </div>
        </ServerLayout>
    );
}
