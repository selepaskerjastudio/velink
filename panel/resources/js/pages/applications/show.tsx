import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import echo from '@/echo';
import AppLayout from '@/layouts/app-layout';
import {
    type AgentJob,
    type AgentJobStatus,
    type Application,
    type BreadcrumbItem,
    type Deployment,
    type DeploymentStatus,
    type GitCredential,
} from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ChevronDownIcon, TriangleAlertIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const PROVIDER_LABELS: Record<string, string> = {
    github: 'GitHub',
    gitlab: 'GitLab',
};

const NO_CREDENTIAL = 'none';

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
    jobs,
    deployments,
    gitCredentials,
    defaultDeployScript,
}: {
    application: Application;
    server: { id: string; name: string; status: string };
    phpVersions: string[];
    jobs: AgentJob[];
    deployments: Deployment[];
    gitCredentials: GitCredential[];
    defaultDeployScript: string;
}) {
    const { errors: pageErrors } = usePage().props as { errors: Record<string, string> };

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
        { title: application.name, href: `/applications/${application.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={application.name} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">{application.name}</h1>
                    <div className="flex items-center gap-2">
                        <Button asChild variant="outline" size="sm">
                            <Link href={route('workers.index', application.id)}>Workers</Link>
                        </Button>
                        <Badge variant={statusVariant(application.status)}>{application.status}</Badge>
                    </div>
                </div>

                {server.status !== 'online' && (
                    <Alert variant="destructive">
                        <TriangleAlertIcon className="h-4 w-4" />
                        <AlertTitle>Server offline</AlertTitle>
                        <AlertDescription>
                            The agent on this server is not connected. Actions that require the agent (deploy, PHP switch, .env write, SSL) will be queued but won't run until the server reconnects.
                        </AlertDescription>
                    </Alert>
                )}

                <Card className="max-w-xl">
                    <CardHeader>
                        <CardTitle>Application details</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-2 text-sm">
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Domain</span>
                            <span>{application.domain ?? '—'}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Root path</span>
                            <span className="font-mono">{application.root_path}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Linux user</span>
                            <span className="font-mono">{application.linux_user}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">PHP version</span>
                            <span>{application.php_version}</span>
                        </div>
                    </CardContent>
                </Card>

                <Card className="max-w-xl">
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
                                {phpVersions.map((version) => (
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

                <Card className="max-w-xl">
                    <CardHeader>
                        <CardTitle>SSL / HTTPS</CardTitle>
                        <CardDescription>
                            Issue a free Let's Encrypt certificate via certbot. Requires certbot to be installed on the server and the domain
                            to point to this server.
                        </CardDescription>
                    </CardHeader>
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
                </Card>

                <Card className="max-w-xl">
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

                <Card className="max-w-xl">
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
                                            {repoLoading && (
                                                <div className="text-muted-foreground px-3 py-2 text-sm">Searching…</div>
                                            )}
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
                            <Select value={deployForm.data.deploy_mode} onValueChange={(value) => deployForm.setData('deploy_mode', value)}>
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

                {application.repository && (
                    <Card className="max-w-xl">
                        <CardHeader>
                            <CardTitle>Auto-deploy webhook</CardTitle>
                            <CardDescription>
                                Triggers deployment on push to <code className="font-mono">{application.branch}</code>. Configure in GitHub or
                                GitLab.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-6">
                            {/* GitHub section */}
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

                            <Separator />

                            {/* GitLab section */}
                            <div className="grid gap-4">
                                <p className="text-sm font-medium">GitLab</p>
                                <p className="text-muted-foreground text-xs">
                                    Add in GitLab → Settings → Webhooks. Set Content type to <code>application/json</code>. Secret token = the
                                    value below.
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
                        </CardContent>
                    </Card>
                )}

                {liveDeployments.length > 0 && (
                    <Card className="max-w-xl">
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

                {liveJobs.length > 0 && (
                    <Card className="max-w-xl">
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
            </div>
        </AppLayout>
    );
}
