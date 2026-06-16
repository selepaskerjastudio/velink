import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ServerLayout from '@/layouts/server-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';

export default function ServerSettings({
    server,
}: {
    server: {
        id: string;
        name: string;
        hostname: string | null;
        public_ip: string | null;
        private_ip: string | null;
        os: string | null;
        status: string;
    };
}) {
    const nameForm = useForm({ name: server.name });
    const restartForm = useForm({});
    const deleteForm = useForm({});

    const [restartOpen, setRestartOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleteConfirm, setDeleteConfirm] = useState('');

    const serverOffline = server.status === 'offline' || server.status === 'pending';

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: 'Settings', href: `/servers/${server.id}/settings` },
    ];

    return (
        <ServerLayout breadcrumbs={breadcrumbs} server={server}>
            <Head title={`${server.name} — Settings`} />
            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <h1 className="text-2xl font-bold">Server Settings</h1>

                {/* Server Details */}
                <Card>
                    <CardHeader>
                        <CardTitle>Server Details</CardTitle>
                        <CardDescription>Update the display name for this server.</CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="server-name">Server Name</Label>
                            <Input
                                id="server-name"
                                value={nameForm.data.name}
                                onChange={(e) => nameForm.setData('name', e.target.value)}
                                placeholder="My Server"
                            />
                            {nameForm.errors.name && (
                                <p className="text-destructive text-sm">{nameForm.errors.name}</p>
                            )}
                        </div>
                        <div className="text-muted-foreground grid gap-1 text-sm">
                            {server.hostname && (
                                <div className="flex gap-2">
                                    <span className="w-24 font-medium">Hostname</span>
                                    <span>{server.hostname}</span>
                                </div>
                            )}
                            {server.public_ip && (
                                <div className="flex gap-2">
                                    <span className="w-24 font-medium">Public IP</span>
                                    <span>{server.public_ip}</span>
                                </div>
                            )}
                            {server.private_ip && (
                                <div className="flex gap-2">
                                    <span className="w-24 font-medium">Private IP</span>
                                    <span>{server.private_ip}</span>
                                </div>
                            )}
                            {server.os && (
                                <div className="flex gap-2">
                                    <span className="w-24 font-medium">OS</span>
                                    <span>{server.os}</span>
                                </div>
                            )}
                        </div>
                    </CardContent>
                    <CardFooter>
                        <Button
                            size="sm"
                            disabled={nameForm.processing || !nameForm.data.name.trim()}
                            onClick={() =>
                                nameForm.patch(route('servers.update', server.id))
                            }
                        >
                            Save
                        </Button>
                    </CardFooter>
                </Card>

                {/* Server Controls */}
                <Card>
                    <CardHeader>
                        <CardTitle>Server Controls</CardTitle>
                        <CardDescription>Perform administrative actions on this server.</CardDescription>
                    </CardHeader>
                    <CardFooter className="flex items-center gap-4">
                        <Dialog
                            open={restartOpen}
                            onOpenChange={setRestartOpen}
                        >
                            <DialogTrigger asChild>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={serverOffline || restartForm.processing}
                                >
                                    Restart Server
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Restart server</DialogTitle>
                                    <DialogDescription>
                                        This will reboot <strong>{server.name}</strong>. All active connections will be
                                        disconnected briefly while the server restarts.
                                    </DialogDescription>
                                </DialogHeader>
                                <DialogFooter>
                                    <Button variant="outline" onClick={() => setRestartOpen(false)}>
                                        Cancel
                                    </Button>
                                    <Button
                                        variant="destructive"
                                        disabled={restartForm.processing}
                                        onClick={() =>
                                            restartForm.post(route('servers.restart', server.id), {
                                                onSuccess: () => setRestartOpen(false),
                                            })
                                        }
                                    >
                                        Restart
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                        {serverOffline && (
                            <p className="text-muted-foreground text-sm">
                                Server must be online to send a restart command.
                            </p>
                        )}
                    </CardFooter>
                </Card>

                {/* Danger Zone */}
                <Card className="border-destructive/40">
                    <CardHeader>
                        <CardTitle>Danger zone</CardTitle>
                        <CardDescription>Permanently delete this server and all associated data.</CardDescription>
                    </CardHeader>
                    <CardFooter>
                        <Dialog
                            open={deleteOpen}
                            onOpenChange={(open) => {
                                setDeleteOpen(open);
                                setDeleteConfirm('');
                            }}
                        >
                            <DialogTrigger asChild>
                                <Button variant="destructive" size="sm">
                                    Delete server
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Delete server</DialogTitle>
                                    <DialogDescription>
                                        This will permanently delete <strong>{server.name}</strong> and all its applications,
                                        databases, services, and jobs. This action cannot be undone.
                                    </DialogDescription>
                                </DialogHeader>
                                <div className="grid gap-2 py-2">
                                    <Label htmlFor="delete-confirm">
                                        Type <strong>DELETE</strong> to confirm
                                    </Label>
                                    <Input
                                        id="delete-confirm"
                                        value={deleteConfirm}
                                        onChange={(e) => setDeleteConfirm(e.target.value)}
                                        placeholder="DELETE"
                                    />
                                </div>
                                <DialogFooter>
                                    <Button variant="outline" onClick={() => setDeleteOpen(false)}>
                                        Cancel
                                    </Button>
                                    <Button
                                        variant="destructive"
                                        disabled={deleteConfirm !== 'DELETE' || deleteForm.processing}
                                        onClick={() =>
                                            deleteForm.delete(route('servers.destroy', server.id), {
                                                onSuccess: () => setDeleteOpen(false),
                                            })
                                        }
                                    >
                                        Delete server
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </CardFooter>
                </Card>
            </div>
        </ServerLayout>
    );
}
