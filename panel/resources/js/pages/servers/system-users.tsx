import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import ServerLayout from '@/layouts/server-layout';
import { type BreadcrumbItem, type SystemUserSummary } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { KeyRoundIcon, PlusIcon, Trash2Icon, UserCogIcon } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

interface Props {
    server: { id: string; name: string; public_ip: string | null; status: string };
    systemUsers: SystemUserSummary[];
    allowedShells: string[];
}

const SHELL_LABELS: Record<string, string> = {
    '/bin/bash': 'Bash',
    '/bin/sh': 'Sh',
    '/usr/sbin/nologin': 'No login',
};

function AddSystemUserDialog({ serverId, allowedShells }: { serverId: string; allowedShells: string[] }) {
    const [open, setOpen] = useState(false);
    const form = useForm<{ username: string; shell: string; is_sudo: boolean }>({
        username: '',
        shell: allowedShells[0] ?? '/bin/bash',
        is_sudo: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        form.post(route('system-users.store', serverId), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">
                    <PlusIcon className="mr-1.5 h-4 w-4" />
                    Add system user
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>New system user</DialogTitle>
                    <DialogDescription>
                        Creates an OS login account on this server. Grant sudo if the user should be able to run admin commands.
                    </DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="grid gap-4 py-2">
                    <div className="grid gap-2">
                        <Label htmlFor="su-username">Username</Label>
                        <Input
                            id="su-username"
                            autoFocus
                            value={form.data.username}
                            onChange={(e) => form.setData('username', e.target.value)}
                            placeholder="deployer"
                            className="font-mono"
                        />
                        <InputError message={form.errors.username} />
                        <p className="text-muted-foreground text-xs">Lowercase letters, digits, underscores or hyphens. Must start with a letter.</p>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="su-shell">Shell</Label>
                        <Select value={form.data.shell} onValueChange={(v) => form.setData('shell', v)}>
                            <SelectTrigger id="su-shell">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {allowedShells.map((sh) => (
                                    <SelectItem key={sh} value={sh}>
                                        {SHELL_LABELS[sh] ?? sh}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={form.errors.shell} />
                    </div>
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id="su-sudo"
                            checked={form.data.is_sudo}
                            onCheckedChange={(v) => form.setData('is_sudo', v === true)}
                        />
                        <Label htmlFor="su-sudo">Grant sudo (admin access)</Label>
                    </div>
                </form>
                <DialogFooter>
                    <Button variant="outline" onClick={() => setOpen(false)} disabled={form.processing}>Cancel</Button>
                    <Button onClick={submit} disabled={form.processing || form.data.username.trim() === ''}>
                        {form.processing ? 'Creating…' : 'Create user'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function SystemUsers({ server, systemUsers, allowedShells }: Props) {
    const sudoForm = useForm<{ is_sudo: boolean }>({ is_sudo: false });
    const shellForm = useForm<{ shell: string }>({ shell: '/bin/bash' });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: 'System users', href: `/servers/${server.id}/system-users` },
    ];

    const toggleSudo = (user: SystemUserSummary) => {
        sudoForm.setData('is_sudo', !user.is_sudo);
        sudoForm.patch(route('system-users.sudo', user.id), { preserveScroll: true });
    };

    const changeShell = (user: SystemUserSummary, shell: string) => {
        shellForm.setData('shell', shell);
        shellForm.patch(route('system-users.shell', user.id), { preserveScroll: true });
    };

    const destroy = (user: SystemUserSummary) => {
        if (confirm(`Delete the system user "${user.username}" and its home directory? This cannot be undone.`)) {
            router.delete(route('system-users.destroy', user.id), { preserveScroll: true });
        }
    };

    return (
        <ServerLayout breadcrumbs={breadcrumbs} server={server}>
            <Head title="System users" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <Card>
                    <CardHeader className="flex-row items-center justify-between space-y-0">
                        <div>
                            <CardTitle>System users</CardTitle>
                            <CardDescription>
                                OS login accounts on this server. SSH keys are deployed to these users' authorized_keys.
                            </CardDescription>
                        </div>
                        <AddSystemUserDialog serverId={server.id} allowedShells={allowedShells} />
                    </CardHeader>
                    <CardContent>
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b text-left text-xs uppercase">
                                    <th className="pb-2 pr-4 font-medium">Username</th>
                                    <th className="pb-2 pr-4 font-medium">Shell</th>
                                    <th className="pb-2 pr-4 font-medium">Sudo</th>
                                    <th className="pb-2 pr-4 font-medium">SSH keys</th>
                                    <th className="pb-2 font-medium" />
                                </tr>
                            </thead>
                            <tbody>
                                {systemUsers.map((user) => (
                                    <tr key={user.id} className="border-b last:border-0">
                                        <td className="py-3 pr-4">
                                            <div className="flex items-center gap-2">
                                                <UserCogIcon className="text-muted-foreground h-4 w-4" />
                                                <span className="font-mono">{user.username}</span>
                                                {user.is_system_reserved && (
                                                    <Badge variant="secondary" className="text-xs">reserved</Badge>
                                                )}
                                            </div>
                                        </td>
                                        <td className="py-3 pr-4">
                                            {user.is_system_reserved ? (
                                                <span className="text-muted-foreground text-xs">{SHELL_LABELS[user.shell] ?? user.shell}</span>
                                            ) : (
                                                <Select
                                                    value={user.shell}
                                                    onValueChange={(v) => changeShell(user, v)}
                                                    disabled={shellForm.processing}
                                                >
                                                    <SelectTrigger className="h-8 w-32">
                                                        <SelectValue />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {allowedShells.map((sh) => (
                                                            <SelectItem key={sh} value={sh}>{SHELL_LABELS[sh] ?? sh}</SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            )}
                                        </td>
                                        <td className="py-3 pr-4">
                                            {user.is_system_reserved ? (
                                                <Badge variant={user.is_sudo ? 'default' : 'outline'}>
                                                    {user.is_sudo ? 'sudo' : 'no'}
                                                </Badge>
                                            ) : (
                                                <Button
                                                    variant={user.is_sudo ? 'default' : 'outline'}
                                                    size="sm"
                                                    disabled={sudoForm.processing}
                                                    onClick={() => toggleSudo(user)}
                                                >
                                                    {user.is_sudo ? 'sudo' : 'no sudo'}
                                                </Button>
                                            )}
                                        </td>
                                        <td className="py-3 pr-4">
                                            <span className="text-muted-foreground inline-flex items-center gap-1">
                                                <KeyRoundIcon className="h-3.5 w-3.5" />
                                                {user.ssh_keys_count}
                                            </span>
                                        </td>
                                        <td className="py-3 text-right">
                                            {!user.is_system_reserved && (
                                                <Button variant="ghost" size="sm" onClick={() => destroy(user)}>
                                                    <Trash2Icon className="mr-1.5 h-4 w-4" />
                                                    Delete
                                                </Button>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                                {systemUsers.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="text-muted-foreground py-6 text-center text-sm">
                                            No system users yet. Add one to grant SSH access.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </div>
        </ServerLayout>
    );
}
