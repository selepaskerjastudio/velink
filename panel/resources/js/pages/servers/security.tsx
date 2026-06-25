import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import ServerLayout from '@/layouts/server-layout';
import { type AgentJob, type BreadcrumbItem, type FirewallRule } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { BanIcon, PlusIcon, ShieldIcon, ShieldOffIcon, Trash2Icon, TriangleAlertIcon } from 'lucide-react';
import { FormEvent, useState } from 'react';

interface Props {
    server: { id: string; name: string; public_ip: string | null; status: string };
    firewallRules: FirewallRule[];
    ufwInstalled: boolean;
    fail2banInstalled: boolean;
    jobs: AgentJob[];
}

function AddRuleDialog({ serverId }: { serverId: string }) {
    const [open, setOpen] = useState(false);
    const form = useForm<{ protocol: string; port: string; action: string; source: string }>({
        protocol: 'tcp',
        port: '',
        action: 'allow',
        source: '',
    });

    const submit = () => {
        form.transform((data) => ({
            protocol: data.protocol,
            port: Number(data.port),
            action: data.action,
            source: data.source || undefined,
        }));
        form.post(route('security.firewall.store', serverId), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('port', 'source');
                setOpen(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">
                    <PlusIcon className="mr-1.5 h-4 w-4" />
                    Add rule
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Add firewall rule</DialogTitle>
                    <DialogDescription>Allow or deny traffic to a specific port.</DialogDescription>
                </DialogHeader>
                <div className="grid gap-4 py-2">
                    <div className="grid grid-cols-2 gap-3">
                        <div className="grid gap-2">
                            <Label htmlFor="fw-protocol">Protocol</Label>
                            <Select value={form.data.protocol} onValueChange={(v) => form.setData('protocol', v)}>
                                <SelectTrigger id="fw-protocol"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="tcp">TCP</SelectItem>
                                    <SelectItem value="udp">UDP</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="fw-action">Action</Label>
                            <Select value={form.data.action} onValueChange={(v) => form.setData('action', v)}>
                                <SelectTrigger id="fw-action"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="allow">Allow</SelectItem>
                                    <SelectItem value="deny">Deny</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="fw-port">Port</Label>
                        <Input id="fw-port" type="number" min={1} max={65535} value={form.data.port}
                            onChange={(e) => form.setData('port', e.target.value)} placeholder="443" />
                        {form.errors.port && <p className="text-sm text-destructive">{form.errors.port}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="fw-source">Source IP (optional)</Label>
                        <Input id="fw-source" value={form.data.source}
                            onChange={(e) => form.setData('source', e.target.value)}
                            placeholder="0.0.0.0/0 (leave empty = anywhere)" />
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={() => setOpen(false)} disabled={form.processing}>Cancel</Button>
                    <Button onClick={submit} disabled={form.processing || !form.data.port}>
                        {form.processing ? 'Adding…' : 'Add rule'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function FirewallPanel({ server, firewallRules }: { server: Props['server']; firewallRules: FirewallRule[] }) {
    const deleteForm = useForm({});

    const destroy = (rule: FirewallRule) => {
        if (rule.is_protected) return;
        if (confirm(`Delete rule: ${rule.action} ${rule.port}/${rule.protocol}?`)) {
            deleteForm.delete(route('security.firewall.destroy', [server.id, rule.id]), { preserveScroll: true });
        }
    };

    return (
        <Card>
            <CardHeader className="flex-row items-center justify-between space-y-0">
                <div>
                    <CardTitle>Firewall rules</CardTitle>
                    <CardDescription>UFW rules rebuilt from the panel on every change.</CardDescription>
                </div>
                <AddRuleDialog serverId={server.id} />
            </CardHeader>
            <CardContent>
                <table className="w-full text-sm">
                    <thead>
                        <tr className="text-muted-foreground border-b text-left text-xs uppercase">
                            <th className="pb-2 pr-4 font-medium">Port</th>
                            <th className="pb-2 pr-4 font-medium">Protocol</th>
                            <th className="pb-2 pr-4 font-medium">Action</th>
                            <th className="pb-2 pr-4 font-medium">Source</th>
                            <th className="pb-2" />
                        </tr>
                    </thead>
                    <tbody>
                        {firewallRules.map((rule) => (
                            <tr key={rule.id} className="border-b last:border-0">
                                <td className="py-2.5 pr-4 font-mono">{rule.port}</td>
                                <td className="py-2.5 pr-4 uppercase">{rule.protocol}</td>
                                <td className="py-2.5 pr-4">
                                    <Badge variant={rule.action === 'allow' ? 'default' : 'destructive'}>{rule.action}</Badge>
                                </td>
                                <td className="text-muted-foreground py-2.5 pr-4 font-mono text-xs">{rule.source ?? 'anywhere'}</td>
                                <td className="py-2.5 text-right">
                                    {rule.is_protected ? (
                                        <Badge variant="outline" className="text-xs">protected</Badge>
                                    ) : (
                                        <Button variant="ghost" size="sm" onClick={() => destroy(rule)}>
                                            <Trash2Icon className="mr-1 h-3.5 w-3.5" />
                                        </Button>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {firewallRules.length === 0 && (
                            <tr>
                                <td colSpan={5} className="text-muted-foreground py-6 text-center">No firewall rules.</td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </CardContent>
        </Card>
    );
}

function Fail2BanPanel({ server, fail2banInstalled }: { server: Props['server']; fail2banInstalled: boolean }) {
    const banForm = useForm<{ ip: string }>({ ip: '' });

    const submitBan = (e: FormEvent) => {
        e.preventDefault();
        banForm.post(route('security.fail2ban.ban', server.id), {
            preserveScroll: true,
            onSuccess: () => { banForm.reset('ip'); },
        });
    };

    if (!fail2banInstalled) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>Fail2Ban</CardTitle>
                    <CardDescription>Brute-force protection for SSH and other services.</CardDescription>
                </CardHeader>
                <CardContent>
                    <Alert>
                        <TriangleAlertIcon className="h-4 w-4" />
                        <AlertTitle>Fail2Ban is not installed</AlertTitle>
                        <AlertDescription className="mt-2">
                            Install it to enable automatic IP banning for repeated failed SSH logins.
                        </AlertDescription>
                    </Alert>
                    <form onSubmit={(e) => { e.preventDefault(); router.post(route('security.fail2ban.install', server.id)); }} className="mt-4">
                        <Button type="submit">Install Fail2Ban</Button>
                    </form>
                </CardContent>
            </Card>
        );
    }

    return (
        <div className="grid gap-4">
            <Card>
                <CardHeader>
                    <CardTitle>Ban an IP</CardTitle>
                    <CardDescription>Manually block an IP address via Fail2Ban.</CardDescription>
                </CardHeader>
                <CardContent>
                    <form onSubmit={submitBan} className="flex items-end gap-2">
                        <div className="grid flex-1 gap-1.5">
                            <Label htmlFor="ban-ip">IP address</Label>
                            <Input id="ban-ip" value={banForm.data.ip}
                                onChange={(e) => banForm.setData('ip', e.target.value)}
                                placeholder="203.0.113.5" />
                            {banForm.errors.ip && <p className="text-sm text-destructive">{banForm.errors.ip}</p>}
                        </div>
                        <Button type="submit" disabled={banForm.processing || !banForm.data.ip}>
                            <BanIcon className="mr-1.5 h-4 w-4" />
                            Ban
                        </Button>
                    </form>
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle>Banned IPs</CardTitle>
                    <CardDescription>Check the job log for the current banned IP list.</CardDescription>
                </CardHeader>
                <CardContent>
                    <Button variant="outline" size="sm" onClick={() => router.reload({ only: ['jobs'] })}>
                        <ShieldIcon className="mr-1.5 h-4 w-4" />
                        Refresh status
                    </Button>
                    <p className="text-muted-foreground mt-3 text-xs">
                        The latest <code>fail2ban-client status sshd</code> output appears in the job log below.
                    </p>
                </CardContent>
            </Card>
        </div>
    );
}

export default function Security({ server, firewallRules, ufwInstalled, fail2banInstalled }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: 'Security', href: `/servers/${server.id}/security` },
    ];

    return (
        <ServerLayout breadcrumbs={breadcrumbs} server={server}>
            <Head title="Security" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <h1 className="text-xl font-semibold">Security</h1>

                {!ufwInstalled && (
                    <Alert variant="destructive">
                        <TriangleAlertIcon className="h-4 w-4" />
                        <AlertTitle>UFW is not installed</AlertTitle>
                        <AlertDescription>
                            Firewall rules shown here won't take effect until UFW is provisioned.
                            Go to <a href={`/servers/${server.id}/services`} className="underline">Services</a> to install it.
                        </AlertDescription>
                    </Alert>
                )}

                <Tabs defaultValue="firewall">
                    <TabsList>
                        <TabsTrigger value="firewall">
                            <ShieldIcon className="mr-1.5 h-4 w-4" />
                            Firewall
                        </TabsTrigger>
                        <TabsTrigger value="fail2ban">
                            <ShieldOffIcon className="mr-1.5 h-4 w-4" />
                            Fail2Ban
                        </TabsTrigger>
                    </TabsList>
                    <TabsContent value="firewall" className="mt-4">
                        <FirewallPanel server={server} firewallRules={firewallRules} />
                    </TabsContent>
                    <TabsContent value="fail2ban" className="mt-4">
                        <Fail2BanPanel server={server} fail2banInstalled={fail2banInstalled} />
                    </TabsContent>
                </Tabs>
            </div>
        </ServerLayout>
    );
}
