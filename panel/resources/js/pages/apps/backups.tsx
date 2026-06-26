import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BackupSettings, type BackupSummary, type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ChevronLeftIcon, DatabaseIcon, HardDriveDownloadIcon, RotateCcwIcon, Trash2Icon } from 'lucide-react';
import { FormEventHandler } from 'react';

interface Props {
    application: { id: string; name: string };
    server: { id: string; name: string };
    backups: BackupSummary[];
    settings: BackupSettings;
}

function formatBytes(bytes: number | null): string {
    if (!bytes) return '—';
    if (bytes < 1024) return `${bytes} B`;
    const units = ['KB', 'MB', 'GB', 'TB'];
    let value = bytes / 1024;
    let unit = 0;
    while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit++; }

    return `${value.toFixed(1)} ${units[unit]}`;
}

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(undefined, { month: 'short', day: '2-digit', hour: '2-digit', minute: '2-digit' });
}

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'success' | 'outline' {
    switch (status) {
        case 'succeeded': return 'success';
        case 'failed': return 'destructive';
        case 'running': return 'default';
        case 'pending': return 'outline';
        default: return 'secondary';
    }
}

export default function Backups({ application, server, backups, settings }: Props) {
    const settingsForm = useForm<BackupSettings>({
        schedule: settings.schedule,
        retention_count: settings.retention_count,
        include_database: settings.include_database,
        include_files: settings.include_files,
        storage_local: settings.storage_local,
        storage_s3: settings.storage_s3,
    });

    const saveSettings: FormEventHandler = (e) => {
        e.preventDefault();
        settingsForm.post(route('backups.settings', application.id), { preserveScroll: true });
    };

    const triggerBackup = () => {
        router.post(route('backups.store', application.id), {}, { preserveScroll: true });
    };

    const destroy = (backupId: string) => {
        if (confirm('Delete this backup? This cannot be undone.')) {
            router.delete(route('backups.destroy', [application.id, backupId]), { preserveScroll: true });
        }
    };

    const restore = (backupId: string) => {
        if (confirm('Restore from this backup? This will OVERWRITE all current files and database data.')) {
            router.post(route('backups.restore', [application.id, backupId]), {}, { preserveScroll: true });
        }
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: application.name, href: `/apps/${application.id}` },
        { title: 'Backups', href: `/apps/${application.id}/backups` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Backups" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">Backups — {application.name}</h1>
                    </div>
                    <div className="flex items-center gap-2">
                        <Link href={route('applications.show', application.id)}>
                            <Button variant="ghost" size="sm">
                                <ChevronLeftIcon className="mr-1 h-4 w-4" /> Back to app
                            </Button>
                        </Link>
                        <Button onClick={triggerBackup} size="sm">
                            <HardDriveDownloadIcon className="mr-1.5 h-4 w-4" />
                            Backup now
                        </Button>
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    {/* Settings */}
                    <Card className="lg:col-span-1">
                        <CardHeader>
                            <CardTitle>Backup settings</CardTitle>
                            <CardDescription>Configure what to back up and when.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={saveSettings} className="space-y-4">
                                <div className="grid gap-2">
                                    <Label>Schedule</Label>
                                    <Select value={settingsForm.data.schedule} onValueChange={(v) => settingsForm.setData('schedule', v)}>
                                        <SelectTrigger><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="off">Off (manual only)</SelectItem>
                                            <SelectItem value="daily">Daily (2:00 AM)</SelectItem>
                                            <SelectItem value="weekly">Weekly (Sun 2:00 AM)</SelectItem>
                                            <SelectItem value="monthly">Monthly (1st, 2:00 AM)</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="grid gap-2">
                                    <Label>Keep last N backups</Label>
                                    <Input type="number" min={1} max={100} value={settingsForm.data.retention_count}
                                        onChange={(e) => settingsForm.setData('retention_count', Number(e.target.value))} />
                                </div>
                                <div className="space-y-2">
                                    <Label>What to include</Label>
                                    <div className="flex items-center gap-2">
                                        <Checkbox id="bk-db" checked={settingsForm.data.include_database}
                                            onCheckedChange={(v) => settingsForm.setData('include_database', v === true)} />
                                        <Label htmlFor="bk-db" className="text-sm font-normal">Database</Label>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Checkbox id="bk-files" checked={settingsForm.data.include_files}
                                            onCheckedChange={(v) => settingsForm.setData('include_files', v === true)} />
                                        <Label htmlFor="bk-files" className="text-sm font-normal">Application files</Label>
                                    </div>
                                </div>
                                <div className="space-y-2">
                                    <Label>Storage destination</Label>
                                    <div className="flex items-center gap-2">
                                        <Checkbox id="bk-local" checked={settingsForm.data.storage_local}
                                            onCheckedChange={(v) => settingsForm.setData('storage_local', v === true)} />
                                        <Label htmlFor="bk-local" className="text-sm font-normal">Local server</Label>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Checkbox id="bk-s3" checked={settingsForm.data.storage_s3}
                                            onCheckedChange={(v) => settingsForm.setData('storage_s3', v === true)} />
                                        <Label htmlFor="bk-s3" className="text-sm font-normal">S3 (requires config)</Label>
                                    </div>
                                </div>
                                <Button type="submit" disabled={settingsForm.processing} size="sm">
                                    {settingsForm.processing ? 'Saving…' : 'Save settings'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>

                    {/* Backup list */}
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Backup history</CardTitle>
                            <CardDescription>{backups.length} backup(s).</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-muted-foreground border-b text-left text-xs uppercase">
                                        <th className="pb-2 pr-4 font-medium">Date</th>
                                        <th className="pb-2 pr-4 font-medium">Type</th>
                                        <th className="pb-2 pr-4 font-medium">Size</th>
                                        <th className="pb-2 pr-4 font-medium">Storage</th>
                                        <th className="pb-2 pr-4 font-medium">Status</th>
                                        <th className="pb-2" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {backups.map((backup) => (
                                        <tr key={backup.id} className="border-b last:border-0">
                                            <td className="py-2.5 pr-4">{formatDate(backup.created_at ?? null)}</td>
                                            <td className="py-2.5 pr-4">
                                                <Badge variant="outline" className="text-xs">{backup.type}</Badge>
                                            </td>
                                            <td className="py-2.5 pr-4">{formatBytes(backup.size_bytes)}</td>
                                            <td className="py-2.5 pr-4">{backup.storage}</td>
                                            <td className="py-2.5 pr-4">
                                                <Badge variant={statusVariant(backup.status)}>{backup.status}</Badge>
                                            </td>
                                            <td className="py-2.5 text-right">
                                                {backup.status === 'succeeded' && (
                                                    <Button variant="ghost" size="sm" onClick={() => restore(backup.id)}>
                                                        <RotateCcwIcon className="mr-1 h-3.5 w-3.5" />
                                                        Restore
                                                    </Button>
                                                )}
                                                <Button variant="ghost" size="sm" onClick={() => destroy(backup.id)}>
                                                    <Trash2Icon className="h-3.5 w-3.5" />
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                    {backups.length === 0 && (
                                        <tr>
                                            <td colSpan={6} className="text-muted-foreground py-6 text-center">
                                                <DatabaseIcon className="mx-auto mb-2 h-6 w-6" />
                                                No backups yet. Click "Backup now" to create one.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
