import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import ServerLayout from '@/layouts/server-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { KeyRoundIcon, PlusIcon, Trash2Icon } from 'lucide-react';
import { useState } from 'react';

interface ServerSshKey {
    id: string;
    name: string;
    fingerprint: string;
    type: string;
}

interface Props {
    server: { id: string; name: string; public_ip: string | null; status: string };
    deployed: ServerSshKey[];
    available: ServerSshKey[];
    adminUser: string;
}

const KEY_TYPE_LABELS: Record<string, string> = {
    'ssh-ed25519': 'Ed25519',
    'ssh-rsa': 'RSA',
    'ecdsa-sha2-nistp256': 'ECDSA P-256',
};

export default function ServerSshKeys({ server, deployed, available, adminUser }: Props) {
    const [selectedKey, setSelectedKey] = useState<string>(available[0]?.id ?? '');
    const [deploying, setDeploying] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: 'SSH Keys', href: `/servers/${server.id}/ssh-keys` },
    ];

    const deploy = () => {
        if (!selectedKey) return;
        setDeploying(true);
        router.post(
            route('server.ssh-keys.deploy', [server.id, selectedKey]),
            {},
            {
                preserveScroll: true,
                onFinish: () => setDeploying(false),
            },
        );
    };

    const revoke = (key: ServerSshKey) => {
        if (confirm(`Revoke "${key.name}" from this server? You will lose SSH access with that key.`)) {
            router.delete(route('server.ssh-keys.revoke', [server.id, key.id]), { preserveScroll: true });
        }
    };

    const sshCommand = server.public_ip ? `ssh ${adminUser}@${server.public_ip}` : `ssh ${adminUser}@<server-ip>`;

    return (
        <ServerLayout breadcrumbs={breadcrumbs} server={server}>
            <Head title="SSH Keys — server" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>SSH access</CardTitle>
                            <CardDescription>
                                Keys deployed here are written to the <code className="text-xs">{adminUser}</code> user's
                                <code className="text-xs"> authorized_keys</code>.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3 text-sm">
                            <div className="flex items-center justify-between gap-4">
                                <span className="text-muted-foreground">Username</span>
                                <code className="font-mono">{adminUser}</code>
                            </div>
                            <div className="flex items-center justify-between gap-4">
                                <span className="text-muted-foreground">Connect</span>
                                <code className="font-mono text-xs">{sshCommand}</code>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Deploy a key</CardTitle>
                            <CardDescription>
                                Pick one of your registered keys to deploy to this server.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {available.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    No new keys to deploy. Add more in{' '}
                                    <a className="text-foreground underline" href="/settings/ssh-keys">
                                        Settings → SSH Keys
                                    </a>
                                    .
                                </p>
                            ) : (
                                <div className="flex items-end gap-2">
                                    <div className="grid flex-1 gap-1.5">
                                        <Select value={selectedKey} onValueChange={setSelectedKey}>
                                            <SelectTrigger>
                                                <SelectValue placeholder="Select a key" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {available.map((key) => (
                                                    <SelectItem key={key.id} value={key.id}>
                                                        {key.name} ({KEY_TYPE_LABELS[key.type] ?? key.type})
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <Button onClick={deploy} disabled={deploying || !selectedKey}>
                                        <PlusIcon className="mr-1.5 h-4 w-4" />
                                        {deploying ? 'Deploying…' : 'Deploy'}
                                    </Button>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Deployed keys</CardTitle>
                        <CardDescription>{deployed.length} key(s) currently on this server.</CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-2">
                        {deployed.length === 0 && (
                            <p className="text-muted-foreground text-sm">No keys deployed yet.</p>
                        )}
                        {deployed.map((key) => (
                            <div
                                key={key.id}
                                className="flex flex-col gap-2 rounded-md border p-3 sm:flex-row sm:items-center sm:justify-between"
                            >
                                <div className="flex items-center gap-2">
                                    <KeyRoundIcon className="text-muted-foreground h-4 w-4" />
                                    <span className="text-sm font-medium">{key.name}</span>
                                    <Badge variant="outline">{KEY_TYPE_LABELS[key.type] ?? key.type}</Badge>
                                </div>
                                <div className="flex items-center gap-3">
                                    <code className="text-muted-foreground font-mono text-xs">{key.fingerprint}</code>
                                    <Button variant="ghost" size="sm" onClick={() => revoke(key)}>
                                        <Trash2Icon className="mr-1.5 h-4 w-4" />
                                        Revoke
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </ServerLayout>
    );
}
