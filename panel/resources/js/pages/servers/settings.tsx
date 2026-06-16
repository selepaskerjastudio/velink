import { Button } from '@/components/ui/button';
import { Card, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
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
    server: { id: string; name: string; hostname: string | null; public_ip: string | null; status: string };
}) {
    const deleteForm = useForm({});
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleteConfirm, setDeleteConfirm] = useState('');

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
