import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type ServerOption, type SharedData, type UserInvitationSummary, type UserRole, type UserSummary } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CheckIcon, ClipboardIcon, MailIcon, ServerIcon, Trash2Icon, UsersIcon } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Users', href: '/settings/users' }];

function CopyButton({ text }: { text: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <button
            type="button"
            onClick={() => navigator.clipboard.writeText(text).then(() => (setCopied(true), setTimeout(() => setCopied(false), 2000)))}
            className="flex shrink-0 items-center gap-1.5 text-sm text-blue-600 hover:text-blue-800"
        >
            {copied ? <CheckIcon className="h-4 w-4" /> : <ClipboardIcon className="h-4 w-4" />}
            {copied ? 'Copied!' : 'Copy link'}
        </button>
    );
}

function RoleBadge({ role }: { role: UserRole }) {
    return <Badge variant={role === 'admin' ? 'default' : 'outline'}>{role === 'admin' ? 'Admin' : 'Member'}</Badge>;
}

function ManageServersDialog({ user, servers }: { user: UserSummary; servers: ServerOption[] }) {
    const [open, setOpen] = useState(false);
    const [selected, setSelected] = useState<string[]>(user.server_ids);
    const [processing, setProcessing] = useState(false);

    const toggle = (id: string) => {
        setSelected((prev) => (prev.includes(id) ? prev.filter((s) => s !== id) : [...prev, id]));
    };

    const save = () => {
        setProcessing(true);
        router.put(
            route('users.servers.sync', user.id),
            { server_uuids: selected },
            {
                preserveScroll: true,
                onSuccess: () => setOpen(false),
                onFinish: () => setProcessing(false),
            },
        );
    };

    if (user.role === 'admin') {
        return <span className="text-muted-foreground text-xs">All servers (admin)</span>;
    }

    return (
        <Dialog open={open} onOpenChange={(o) => (setOpen(o), setSelected(user.server_ids))}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    <ServerIcon className="h-3.5 w-3.5" />
                    {user.server_ids.length} server{user.server_ids.length === 1 ? '' : 's'}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Servers for {user.name}</DialogTitle>
                </DialogHeader>
                <div className="grid max-h-80 gap-2 overflow-y-auto py-2">
                    {servers.length === 0 && <p className="text-muted-foreground text-sm">No servers exist yet.</p>}
                    {servers.map((server) => (
                        <label key={server.id} className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                            <Checkbox checked={selected.includes(server.id)} onCheckedChange={() => toggle(server.id)} />
                            {server.name}
                        </label>
                    ))}
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={() => setOpen(false)}>
                        Cancel
                    </Button>
                    <Button onClick={save} disabled={processing}>
                        {processing ? 'Saving…' : 'Save'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function UsersSettings({
    users,
    servers,
    invitations,
}: {
    users: UserSummary[];
    servers: ServerOption[];
    invitations: UserInvitationSummary[];
}) {
    const { auth, flash } = usePage<SharedData>().props;

    const [inviteServers, setInviteServers] = useState<string[]>([]);
    const { data, setData, post, processing, errors, reset } = useForm<{
        email: string;
        role: UserRole;
        server_uuids: string[];
    }>({
        email: '',
        role: 'member',
        server_uuids: [],
    });

    const toggleInviteServer = (id: string) => {
        const next = inviteServers.includes(id) ? inviteServers.filter((s) => s !== id) : [...inviteServers, id];
        setInviteServers(next);
        setData('server_uuids', next);
    };

    const submitInvite: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('invitations.store'), {
            preserveScroll: true,
            onSuccess: () => {
                reset('email');
                setInviteServers([]);
            },
        });
    };

    const updateRole = (user: UserSummary, role: UserRole) => {
        router.patch(route('users.update', user.id), { role, is_active: user.is_active }, { preserveScroll: true });
    };

    const toggleActive = (user: UserSummary) => {
        router.patch(route('users.update', user.id), { role: user.role, is_active: !user.is_active }, { preserveScroll: true });
    };

    const destroyUser = (user: UserSummary) => {
        if (confirm(`Remove ${user.name} (${user.email})? This cannot be undone.`)) {
            router.delete(route('users.destroy', user.id));
        }
    };

    const revokeInvitation = (invitation: UserInvitationSummary) => {
        if (confirm(`Revoke the invitation for ${invitation.email}?`)) {
            router.delete(route('invitations.destroy', invitation.id));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Users" />
            <SettingsLayout>
                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <MailIcon className="h-5 w-5" />
                                Invite a user
                            </CardTitle>
                            <CardDescription>
                                Members only see and manage the servers assigned to them. Admins see and manage everything, including terminal,
                                firewall, and system users.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {flash.inviteUrl && (
                                <div className="bg-muted mb-4 rounded-md border px-4 py-3">
                                    <p className="text-sm font-medium">Invitation created</p>
                                    <p className="text-muted-foreground mt-0.5 text-xs">
                                        This link is shown once. Share it with the invited person — it works even if email delivery isn't configured.
                                    </p>
                                    <div className="bg-background mt-2 flex items-center justify-between gap-3 rounded border px-3 py-2">
                                        <code className="truncate font-mono text-xs">{flash.inviteUrl}</code>
                                        <CopyButton text={flash.inviteUrl} />
                                    </div>
                                </div>
                            )}
                            <form onSubmit={submitInvite} className="space-y-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="invite-email">Email</Label>
                                    <Input
                                        id="invite-email"
                                        type="email"
                                        value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        placeholder="teammate@example.com"
                                    />
                                    <InputError message={errors.email} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="invite-role">Role</Label>
                                    <Select value={data.role} onValueChange={(v) => setData('role', v as UserRole)}>
                                        <SelectTrigger id="invite-role">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="member">Member</SelectItem>
                                            <SelectItem value="admin">Admin</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                {data.role === 'member' && (
                                    <div className="grid gap-2">
                                        <Label>Assign servers (optional — can be changed later)</Label>
                                        <div className="grid max-h-48 gap-2 overflow-y-auto">
                                            {servers.length === 0 && <p className="text-muted-foreground text-sm">No servers exist yet.</p>}
                                            {servers.map((server) => (
                                                <label key={server.id} className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                                                    <Checkbox
                                                        checked={inviteServers.includes(server.id)}
                                                        onCheckedChange={() => toggleInviteServer(server.id)}
                                                    />
                                                    {server.name}
                                                </label>
                                            ))}
                                        </div>
                                    </div>
                                )}
                                <Button type="submit" disabled={processing || !data.email.trim()}>
                                    {processing ? 'Inviting…' : 'Send invitation'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>

                    {invitations.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Pending invitations</CardTitle>
                                <CardDescription>{invitations.length} invitation(s) awaiting acceptance.</CardDescription>
                            </CardHeader>
                            <CardContent className="grid gap-2">
                                {invitations.map((invitation) => (
                                    <div key={invitation.id} className="flex items-center justify-between rounded-md border px-3 py-2">
                                        <div className="flex items-center gap-2">
                                            <span className="text-sm font-medium">{invitation.email}</span>
                                            <RoleBadge role={invitation.role} />
                                            {invitation.invited_by && (
                                                <span className="text-muted-foreground text-xs">invited by {invitation.invited_by}</span>
                                            )}
                                        </div>
                                        <Button variant="ghost" size="sm" onClick={() => revokeInvitation(invitation)}>
                                            <Trash2Icon className="h-4 w-4" />
                                        </Button>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    )}

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <UsersIcon className="h-5 w-5" />
                                Users
                            </CardTitle>
                            <CardDescription>{users.length} user(s).</CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-2">
                            {users.map((user) => {
                                const isSelf = user.email === auth.user.email;

                                return (
                                    <div key={user.id} className="flex flex-wrap items-center justify-between gap-3 rounded-md border px-3 py-2">
                                        <div className="min-w-0">
                                            <div className="flex items-center gap-2">
                                                <span className="truncate text-sm font-medium">{user.name}</span>
                                                {!user.is_active && (
                                                    <Badge variant="destructive" className="text-xs">
                                                        Deactivated
                                                    </Badge>
                                                )}
                                                {isSelf && (
                                                    <Badge variant="outline" className="text-xs">
                                                        You
                                                    </Badge>
                                                )}
                                            </div>
                                            <span className="text-muted-foreground truncate text-xs">{user.email}</span>
                                        </div>

                                        <div className="flex items-center gap-2">
                                            <Select value={user.role} onValueChange={(v) => updateRole(user, v as UserRole)} disabled={isSelf}>
                                                <SelectTrigger className="h-8 w-28">
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="member">Member</SelectItem>
                                                    <SelectItem value="admin">Admin</SelectItem>
                                                </SelectContent>
                                            </Select>

                                            <ManageServersDialog user={user} servers={servers} />

                                            <Button
                                                variant={user.is_active ? 'outline' : 'default'}
                                                size="sm"
                                                onClick={() => toggleActive(user)}
                                                disabled={isSelf}
                                            >
                                                {user.is_active ? 'Deactivate' : 'Activate'}
                                            </Button>

                                            <Button variant="ghost" size="sm" onClick={() => destroyUser(user)} disabled={isSelf}>
                                                <Trash2Icon className="h-4 w-4" />
                                            </Button>
                                        </div>
                                    </div>
                                );
                            })}
                        </CardContent>
                    </Card>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
