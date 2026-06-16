import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

function filterPhpVersions(versions: string[], os: string | null | undefined): string[] {
    if (!os) return versions;
    const m = os.match(/Ubuntu\s+(\d+)/i);
    if (!m) return versions;
    return parseInt(m[1], 10) >= 24 ? versions.filter((v) => v !== '7.4') : versions;
}

export default function ApplicationsCreate({ server, phpVersions }: { server: { id: string; name: string; os?: string | null }; phpVersions: string[] }) {
    const available = filterPhpVersions(phpVersions, server.os);

    const { data, setData, post, processing, errors } = useForm({
        name: '',
        domain: '',
        php_version: available[available.length - 1] ?? '',
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: 'New application', href: `/servers/${server.id}/applications/create` },
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('applications.store', server.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New application" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <h1 className="text-xl font-semibold">New application on {server.name}</h1>

                <Card className="max-w-xl">
                    <CardContent>
                        <form onSubmit={submit} className="space-y-4">
                            <div className="grid gap-2">
                                <Label htmlFor="name">Name</Label>
                                <Input
                                    id="name"
                                    autoFocus
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder="e.g. My Laravel App"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="domain">Domain</Label>
                                <Input
                                    id="domain"
                                    value={data.domain}
                                    onChange={(e) => setData('domain', e.target.value)}
                                    placeholder="e.g. app.example.com"
                                />
                                <InputError message={errors.domain} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="php_version">PHP version</Label>
                                <Select value={data.php_version} onValueChange={(value) => setData('php_version', value)}>
                                    <SelectTrigger id="php_version">
                                        <SelectValue placeholder="Select a PHP version" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {available.map((version) => (
                                            <SelectItem key={version} value={version}>
                                                PHP {version}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.php_version} />
                            </div>

                            <Button type="submit" disabled={processing}>
                                Create application
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
