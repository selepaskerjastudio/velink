import { Button } from '@/components/ui/button';
import echo from '@/echo';
import ServerLayout from '@/layouts/server-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CheckIcon, ClipboardIcon, MapPinIcon, SettingsIcon } from 'lucide-react';
import { useEffect, useState } from 'react';


interface ConnectServer {
    id: string;
    name: string;
    public_ip: string | null;
    status: string;
}

function CopyButton({ text }: { text: string }) {
    const [copied, setCopied] = useState(false);

    const handleCopy = () => {
        navigator.clipboard.writeText(text).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });
    };

    return (
        <button onClick={handleCopy} className="flex shrink-0 items-center gap-1.5 text-sm text-blue-600 hover:text-blue-800">
            {copied ? <CheckIcon className="h-4 w-4" /> : <ClipboardIcon className="h-4 w-4" />}
            {copied ? 'Copied!' : 'Copy'}
        </button>
    );
}

function StepNumber({ n }: { n: number }) {
    return (
        <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-muted-foreground/40 text-xs font-medium text-muted-foreground">
            {n}
        </div>
    );
}

function InstallCommandCode({ command }: { command: string }) {
    const curlMatch = command.match(/^(curl\s+-fsSL\s+)(\S+)(\s+\|.*)$/);
    if (curlMatch) {
        return (
            <code className="break-all font-mono text-sm">
                <span className="text-orange-500">{curlMatch[1]}</span>
                <span className="text-blue-600">{curlMatch[2]}</span>
                <span className="text-foreground/80">{curlMatch[3]}</span>
            </code>
        );
    }
    return <code className="break-all font-mono text-sm">{command}</code>;
}

export default function ServerConnect({ server }: { server: ConnectServer }) {
    const { flash } = usePage<SharedData>().props;

    const tokenForm = useForm({});
    const deleteForm = useForm({});

    useEffect(() => {
        const channel = echo.private(`server.${server.id}`);

        channel.listen('.server.presence', (event: { status: string }) => {
            if (event.status === 'online') {
                router.visit(`/servers/${server.id}`);
            }
        });

        return () => {
            echo.leave(`server.${server.id}`);
        };
    }, [server.id]);

    const sshCommand = `ssh root@${server.public_ip ?? 'your-server-ip'}`;
    const installCommand = flash.installCommand ?? null;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: 'Connect Server', href: `/servers/${server.id}/connect` },
    ];

    return (
        <ServerLayout breadcrumbs={breadcrumbs} server={{ id: server.id, name: server.name, public_ip: server.public_ip ?? null, status: server.status }}>
            <Head title={`Connect Server — ${server.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                {/* Page header */}
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">{server.name}</h1>
                    {server.public_ip && (
                        <div className="mt-1 flex items-center gap-1.5 text-sm text-muted-foreground">
                            <MapPinIcon className="h-3.5 w-3.5" />
                            <span>{server.public_ip}</span>
                        </div>
                    )}
                </div>

                {/* Connection card */}
                <div className="rounded-lg border bg-card text-card-foreground shadow-sm">
                    <div className="border-b px-6 py-4">
                        <p className="font-medium">Manual Installation</p>
                    </div>

                    <div className="p-6">
                        <div className="space-y-6">
                            {/* Firewall warning */}
                            <div className="flex items-start gap-3 rounded-md border border-orange-200 bg-orange-50 px-4 py-3">
                                <SettingsIcon className="mt-0.5 h-4 w-4 shrink-0 text-orange-500" />
                                <p className="text-sm text-orange-800">
                                    You need to configure your firewall at your VPS dashboard manually. Please enable inbound traffic for{' '}
                                    <strong>80/tcp, 443/tcp and 34210/tcp.</strong>
                                </p>
                            </div>

                            {/* Step 1 — SSH */}
                            <div>
                                <div className="flex items-start justify-between gap-4">
                                    <div className="flex items-start gap-3">
                                        <StepNumber n={1} />
                                        <p className="text-sm">Log into your server as root via SSH/Putty. And enter your root password</p>
                                    </div>
                                    <CopyButton text={sshCommand} />
                                </div>
                                <div className="ml-9 mt-2 rounded-md border bg-muted px-4 py-3">
                                    <code className="font-mono text-sm">{sshCommand}</code>
                                </div>
                            </div>

                            {/* Step 2 — Install script */}
                            <div>
                                <div className="flex items-start justify-between gap-4">
                                    <div className="flex items-start gap-3">
                                        <StepNumber n={2} />
                                        <p className="text-sm">Copy script below and paste in on your terminal.</p>
                                    </div>
                                    {installCommand && <CopyButton text={installCommand} />}
                                </div>
                                <div className="ml-9 mt-2 rounded-md border bg-muted px-4 py-3">
                                    {installCommand ? (
                                        <InstallCommandCode command={installCommand} />
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            The install token has expired.{' '}
                                            <button
                                                className="text-blue-600 hover:underline"
                                                disabled={tokenForm.processing}
                                                onClick={() => tokenForm.post(route('servers.regenerate-token', server.id))}
                                            >
                                                Regenerate install command
                                            </button>{' '}
                                            to get a new one.
                                        </p>
                                    )}
                                </div>
                            </div>

                            {/* Step 3 — Run */}
                            <div className="flex items-start gap-3">
                                <StepNumber n={3} />
                                <div>
                                    <p className="text-sm">Run the script to start. And sit back enjoy your coffee</p>
                                    <p className="mt-0.5 text-xs text-muted-foreground">Installation typically take around 15 minutes.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Danger Zone */}
                <div className="rounded-lg border border-red-200">
                    <div className="border-b border-red-200 px-6 py-4">
                        <h2 className="text-sm font-semibold">Danger Zone</h2>
                    </div>
                    <div className="flex items-center justify-between gap-4 px-6 py-4">
                        <div>
                            <p className="text-sm font-medium">Permanently Delete This Server And All Of Its Content</p>
                            <p className="mt-0.5 text-xs text-muted-foreground">All data stored in the server will be deleted and cannot be recovered.</p>
                        </div>
                        <Button
                            variant="destructive"
                            size="sm"
                            className="shrink-0"
                            disabled={deleteForm.processing}
                            onClick={() => deleteForm.delete(route('servers.destroy', server.id))}
                        >
                            Delete Server
                        </Button>
                    </div>
                </div>
            </div>
        </ServerLayout>
    );
}
