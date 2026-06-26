import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type DnsRecordSummary } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ChevronLeftIcon, GlobeIcon, PlusIcon, Trash2Icon } from 'lucide-react';

interface Props {
    application: { id: string; name: string; domain: string | null };
    server: { id: string; name: string; public_ip: string | null };
    dnsRecords: DnsRecordSummary[];
    hasCloudflareToken: boolean;
}

function AddDnsRecordDialog({ appId }: { appId: string }) {
    const form = useForm<{ type: string; name: string; content: string; proxied: boolean }>({
        type: 'A',
        name: '',
        content: '',
        proxied: false,
    });

    const submit = () => {
        form.post(route('dns.store', appId), { preserveScroll: true });
    };

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button size="sm">
                    <PlusIcon className="mr-1.5 h-4 w-4" />
                    Add record
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Add DNS record</DialogTitle>
                    <DialogDescription>Create a record via Cloudflare API.</DialogDescription>
                </DialogHeader>
                <div className="grid gap-4 py-2">
                    <div className="grid gap-2">
                        <Label>Type</Label>
                        <Select value={form.data.type} onValueChange={(v) => form.setData('type', v)}>
                            <SelectTrigger><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="A">A</SelectItem>
                                <SelectItem value="AAAA">AAAA</SelectItem>
                                <SelectItem value="CNAME">CNAME</SelectItem>
                                <SelectItem value="TXT">TXT</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="grid gap-2">
                        <Label>Name</Label>
                        <Input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)}
                            placeholder="app.example.com" className="font-mono" />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label>Content</Label>
                        <Input value={form.data.content} onChange={(e) => form.setData('content', e.target.value)}
                            placeholder="1.2.3.4" className="font-mono" />
                        <InputError message={form.errors.content} />
                    </div>
                </div>
                <DialogFooter>
                    <Button onClick={submit} disabled={form.processing || !form.data.name || !form.data.content}>
                        {form.processing ? 'Creating…' : 'Create'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default function DnsRecords({ application, server, dnsRecords, hasCloudflareToken }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: application.name, href: `/apps/${application.id}` },
        { title: 'DNS', href: `/apps/${application.id}/dns` },
    ];

    const destroy = (recordId: string, name: string) => {
        if (confirm(`Delete DNS record ${name}?`)) {
            router.delete(route('dns.destroy', [application.id, recordId]), { preserveScroll: true });
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="DNS Records" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="flex items-center gap-2 text-xl font-semibold">
                            <GlobeIcon className="h-5 w-5" />
                            DNS Records
                        </h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            {application.domain ?? 'No domain'} → {server.public_ip ?? 'no IP'}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Link href={route('applications.show', application.id)}>
                            <Button variant="ghost" size="sm">
                                <ChevronLeftIcon className="mr-1 h-4 w-4" /> Back to app
                            </Button>
                        </Link>
                        {hasCloudflareToken && <AddDnsRecordDialog appId={application.id} />}
                    </div>
                </div>

                {!hasCloudflareToken && (
                    <Card>
                        <CardContent className="py-6 text-center">
                            <p className="text-muted-foreground text-sm">
                                Connect a Cloudflare account in{' '}
                                <Link href="/settings/cloudflare" className="text-foreground underline">Settings → Cloudflare</Link>
                                {' '}to manage DNS records.
                            </p>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>Records</CardTitle>
                        <CardDescription>{dnsRecords.length} record(s) managed by velink.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="text-muted-foreground border-b text-left text-xs uppercase">
                                    <th className="pb-2 pr-4 font-medium">Type</th>
                                    <th className="pb-2 pr-4 font-medium">Name</th>
                                    <th className="pb-2 pr-4 font-medium">Content</th>
                                    <th className="pb-2 pr-4 font-medium">Proxied</th>
                                    <th className="pb-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {dnsRecords.map((record) => (
                                    <tr key={record.id} className="border-b last:border-0">
                                        <td className="py-2.5 pr-4">
                                            <Badge variant="outline">{record.type}</Badge>
                                        </td>
                                        <td className="py-2.5 pr-4 font-mono">{record.name}</td>
                                        <td className="py-2.5 pr-4 font-mono">{record.content}</td>
                                        <td className="py-2.5 pr-4">{record.proxied ? '✓' : '—'}</td>
                                        <td className="py-2.5 text-right">
                                            <Button variant="ghost" size="sm" onClick={() => destroy(record.id, record.name)}>
                                                <Trash2Icon className="h-4 w-4" />
                                            </Button>
                                        </td>
                                    </tr>
                                ))}
                                {dnsRecords.length === 0 && (
                                    <tr>
                                        <td colSpan={5} className="text-muted-foreground py-6 text-center">
                                            No DNS records yet.
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
