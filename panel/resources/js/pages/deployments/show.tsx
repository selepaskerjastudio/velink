import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import echo from '@/echo';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeftIcon, ChevronRightIcon, ClockIcon, DownloadIcon, GitBranchIcon, ServerIcon, TerminalIcon } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

interface DeploymentData {
    id: string;
    status: string;
    branch: string;
    mode: string;
    triggered_by: string;
    commit_hash: string | null;
    commit_message: string | null;
    log: string;
    log_html: string;
    started_at: string | null;
    finished_at: string | null;
    application_name: string;
    application_uuid: string;
    server_name: string;
    server_uuid: string;
}

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'success' | 'outline' {
    switch (status) {
        case 'success':
            return 'success';
        case 'failed':
            return 'destructive';
        case 'running':
            return 'default';
        case 'pending':
            return 'outline';
        default:
            return 'secondary';
    }
}

function formatTime(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(undefined, {
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}

function formatDuration(start: string | null, end: string | null): string {
    if (!start) return '—';
    const ms = (end ? new Date(end).getTime() : Date.now()) - new Date(start).getTime();
    const sec = Math.max(0, Math.floor(ms / 1000));
    if (sec < 60) return `${sec}s`;
    const min = Math.floor(sec / 60);
    return `${min}m ${sec % 60}s`;
}

// Tailwind-friendly ANSI 16-colour palette mapped to CSS classes.
const ANSI_CSS = `
.ansi-fg-black { color: #555; }
.ansi-fg-red { color: #ef4444; }
.ansi-fg-green { color: #22c55e; }
.ansi-fg-yellow { color: #eab308; }
.ansi-fg-blue { color: #3b82f6; }
.ansi-fg-magenta { color: #a855f7; }
.ansi-fg-cyan { color: #06b6d4; }
.ansi-fg-white { color: #e5e5e5; }
.ansi-fg-bold { font-weight: 700; }
.ansi-fg-dim { opacity: 0.5; }
`;

export default function DeploymentLog({
    deployment,
    previousId,
    nextId,
}: {
    deployment: DeploymentData;
    previousId: string | null;
    nextId: string | null;
}) {
    const [colorized, setColorized] = useState(true);
    const [autoScroll, setAutoScroll] = useState(true);
    // Live log/status copied from Reverb so a running deployment streams
    // without a manual refresh — the page is otherwise static.
    const [liveLogHtml, setLiveLogHtml] = useState(deployment.log_html);
    const [liveLogPlain, setLiveLogPlain] = useState(deployment.log);
    const [liveStatus, setLiveStatus] = useState(deployment.status);
    const logRef = useRef<HTMLPreElement>(null);

    const isRunning = liveStatus === 'running' || liveStatus === 'pending';
    const logContent = useMemo(
        () => (colorized ? liveLogHtml : liveLogPlain),
        [colorized, liveLogHtml, liveLogPlain],
    );
    const logLines = useMemo(() => logContent.split('\n').length, [logContent]);

    // Subscribe to the server's agent-job channel and refresh this deployment
    // when its backing job streams new output or settles. We reload the page
    // (only this route's props) so the server-side ANSI parsing stays the
    // source of truth rather than duplicating it in JS.
    useEffect(() => {
        const channel = echo.private(`server.${deployment.server_uuid}`);
        let timer: ReturnType<typeof setTimeout> | null = null;

        const handleEvent = () => {
            // Reload picks up the freshly persisted log + status; debounce so a
            // burst of output chunks coalesces into a single reload.
            if (timer) clearTimeout(timer);
            timer = setTimeout(() => {
                router.reload({ only: ['deployment', 'previousId', 'nextId'] });
            }, 500);
        };

        channel.listen('.agent-job.updated', handleEvent);

        return () => {
            if (timer) clearTimeout(timer);
            echo.leave(`server.${deployment.server_uuid}`);
        };
    }, [deployment.server_uuid]);

    // Keep local live state in sync whenever Inertia reloads the props.
    useEffect(() => {
        setLiveLogHtml(deployment.log_html);
        setLiveLogPlain(deployment.log);
        setLiveStatus(deployment.status);
    }, [deployment.log_html, deployment.log, deployment.status]);

    // Auto-scroll to the bottom on new content while running.
    useEffect(() => {
        if (autoScroll && isRunning && logRef.current) {
            logRef.current.scrollTop = logRef.current.scrollHeight;
        }
    }, [logContent, autoScroll, isRunning]);

    const handleScroll = () => {
        if (!logRef.current) return;
        const { scrollTop, scrollHeight, clientHeight } = logRef.current;
        setAutoScroll(scrollHeight - scrollTop - clientHeight < 50);
    };

    const handleDownload = () => {
        const blob = new Blob([liveLogPlain], { type: 'text/plain' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `deploy-${deployment.id}-${deployment.branch}-${new Date().toISOString().slice(0, 10)}.log`;
        a.click();
        URL.revokeObjectURL(url);
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: deployment.server_name, href: `/servers/${deployment.server_uuid}` },
        { title: deployment.application_name, href: `/apps/${deployment.application_uuid}` },
        { title: 'Deploy log', href: `/apps/${deployment.application_uuid}/deployments/${deployment.id}/log` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Deploy log — ${deployment.application_name} (${deployment.branch})`} />
            <style dangerouslySetInnerHTML={{ __html: ANSI_CSS }} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                {/* Header */}
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="flex items-center gap-2">
                            <TerminalIcon className="h-5 w-5" />
                            <h1 className="text-lg font-semibold tracking-tight">
                                {deployment.application_name}
                                <span className="text-muted-foreground font-normal"> — deploy log</span>
                            </h1>
                        </div>
                        <div className="text-muted-foreground mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                            <span className="flex items-center gap-1">
                                <GitBranchIcon className="h-3 w-3" /> {deployment.branch}
                            </span>
                            <span>·</span>
                            <span>{deployment.mode}</span>
                            <span>·</span>
                            <span>{deployment.triggered_by}</span>
                            {deployment.commit_hash && (
                                <>
                                    <span>·</span>
                                    <code className="font-mono">{deployment.commit_hash}</code>
                                </>
                            )}
                        </div>
                    </div>
                    <div className="flex items-center gap-2">
                        <Badge variant={statusVariant(liveStatus)}>{liveStatus}</Badge>
                        <span className="text-muted-foreground text-xs">
                            {formatDuration(deployment.started_at, deployment.finished_at)}
                        </span>
                    </div>
                </div>

                {/* Metadata bar */}
                <Card>
                    <CardContent className="grid grid-cols-2 gap-4 py-3 sm:grid-cols-4">
                        <div className="text-xs">
                            <span className="text-muted-foreground">Started</span>
                            <p className="mt-0.5 flex items-center gap-1 font-medium">
                                <ClockIcon className="h-3 w-3" />
                                {formatTime(deployment.started_at)}
                            </p>
                        </div>
                        <div className="text-xs">
                            <span className="text-muted-foreground">Finished</span>
                            <p className="mt-0.5 flex items-center gap-1 font-medium">
                                <ClockIcon className="h-3 w-3" />
                                {formatTime(deployment.finished_at)}
                            </p>
                        </div>
                        <div className="text-xs">
                            <span className="text-muted-foreground">Server</span>
                            <p className="mt-0.5 flex items-center gap-1 font-medium">
                                <ServerIcon className="h-3 w-3" />
                                <Link href={`/servers/${deployment.server_uuid}`} className="hover:underline">
                                    {deployment.server_name}
                                </Link>
                            </p>
                        </div>
                        <div className="text-xs">
                            <span className="text-muted-foreground">Lines</span>
                            <p className="mt-0.5 font-medium">{logLines.toLocaleString()}</p>
                        </div>
                    </CardContent>
                </Card>

                {/* Log viewer */}
                <Card className="flex flex-1 flex-col overflow-hidden">
                    <CardHeader className="flex flex-row items-center justify-between py-2">
                        <CardTitle className="flex items-center gap-2 text-sm">
                            <TerminalIcon className="h-4 w-4" />
                            Output log
                        </CardTitle>
                        <div className="flex items-center gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setColorized(!colorized)}
                                className="h-7 text-xs"
                            >
                                {colorized ? 'Colors' : 'Plain'}
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={handleDownload}
                                className="h-7 text-xs"
                                disabled={!liveLogPlain}
                            >
                                <DownloadIcon className="mr-1 h-3 w-3" /> Download
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent className="flex-1 overflow-hidden p-0">
                        {liveLogPlain ? (
                            <pre
                                ref={logRef}
                                onScroll={handleScroll}
                                className="h-full min-h-[400px] overflow-auto bg-zinc-950 p-3 font-mono text-xs leading-5 text-zinc-200"
                                dangerouslySetInnerHTML={{ __html: logContent + '\n' }}
                            />
                        ) : (
                            <div className="flex h-full min-h-[200px] flex-col items-center justify-center py-12 text-center">
                                <TerminalIcon className="mb-3 h-8 w-8 text-muted-foreground" />
                                <p className="text-sm font-medium">No output yet</p>
                                <p className="text-muted-foreground mt-1 text-xs">
                                    {isRunning
                                        ? 'Waiting for the agent to start producing output…'
                                        : 'This deployment has no log output.'}
                                </p>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Navigation */}
                <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        {previousId ? (
                            <Link href={route('deployments.log', [deployment.application_uuid, previousId])}>
                                <Button variant="outline" size="sm" className="h-7 text-xs">
                                    <ChevronLeftIcon className="mr-1 h-3 w-3" /> Previous
                                </Button>
                            </Link>
                        ) : (
                            <Button variant="outline" size="sm" className="h-7 text-xs" disabled>
                                <ChevronLeftIcon className="mr-1 h-3 w-3" /> Previous
                            </Button>
                        )}
                        {nextId ? (
                            <Link href={route('deployments.log', [deployment.application_uuid, nextId])}>
                                <Button variant="outline" size="sm" className="h-7 text-xs">
                                    Next <ChevronRightIcon className="ml-1 h-3 w-3" />
                                </Button>
                            </Link>
                        ) : (
                            <Button variant="outline" size="sm" className="h-7 text-xs" disabled>
                                Next <ChevronRightIcon className="ml-1 h-3 w-3" />
                            </Button>
                        )}
                    </div>
                    <Link href={`/apps/${deployment.application_uuid}`}>
                        <Button variant="ghost" size="sm" className="h-7 text-xs">
                            Back to application
                        </Button>
                    </Link>
                </div>
            </div>
        </AppLayout>
    );
}
