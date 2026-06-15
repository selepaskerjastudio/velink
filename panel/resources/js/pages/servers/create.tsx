import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Servers',
        href: '/servers',
    },
    {
        title: 'Add Server',
        href: '/servers/create',
    },
];

export default function ServersCreate() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        hostname: '',
        public_ip: '',
        private_ip: '',
        os: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('servers.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Add Server" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <h1 className="text-xl font-semibold">Add Server</h1>

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
                                    placeholder="e.g. web-01"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="hostname">Hostname</Label>
                                <Input
                                    id="hostname"
                                    value={data.hostname}
                                    onChange={(e) => setData('hostname', e.target.value)}
                                    placeholder="e.g. web-01.internal"
                                />
                                <InputError message={errors.hostname} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="public_ip">Public IP</Label>
                                <Input
                                    id="public_ip"
                                    value={data.public_ip}
                                    onChange={(e) => setData('public_ip', e.target.value)}
                                    placeholder="e.g. 203.0.113.10"
                                />
                                <InputError message={errors.public_ip} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="private_ip">Private IP</Label>
                                <Input
                                    id="private_ip"
                                    value={data.private_ip}
                                    onChange={(e) => setData('private_ip', e.target.value)}
                                    placeholder="e.g. 10.0.0.10"
                                />
                                <InputError message={errors.private_ip} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="os">Operating System</Label>
                                <Input
                                    id="os"
                                    value={data.os}
                                    onChange={(e) => setData('os', e.target.value)}
                                    placeholder="e.g. Ubuntu 24.04"
                                />
                                <InputError message={errors.os} />
                            </div>

                            <Button type="submit" disabled={processing}>
                                Add Server
                            </Button>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
