import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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

const DB_OPTIONS = [
    { value: 'mariadb', label: 'MariaDB' },
    { value: 'postgresql', label: 'PostgreSQL' },
    { value: 'mongodb', label: 'MongoDB' },
] as const;

export default function ServersCreate() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        public_ip: '',
        db_components: [] as string[],
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('servers.store'));
    };

    const toggleDb = (value: string, checked: boolean) => {
        setData(
            'db_components',
            checked ? [...data.db_components, value] : data.db_components.filter((v) => v !== value),
        );
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
                                <Label htmlFor="name">Server Name</Label>
                                <Input
                                    id="name"
                                    autoFocus
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder="e.g. web-01, Singapore Production"
                                />
                                <InputError message={errors.name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="public_ip">IP Address</Label>
                                <Input
                                    id="public_ip"
                                    value={data.public_ip}
                                    onChange={(e) => setData('public_ip', e.target.value)}
                                    placeholder="xxx.xxx.xxx.xxx"
                                />
                                <InputError message={errors.public_ip} />
                                <p className="text-sm text-muted-foreground">
                                    Optional — hostname and OS will be detected automatically when the agent connects.
                                </p>
                            </div>

                            <div className="grid gap-3">
                                <Label>Database</Label>
                                <div className="flex flex-wrap gap-3">
                                    {DB_OPTIONS.map(({ value, label }) => (
                                        <label
                                            key={value}
                                            className="flex cursor-pointer items-center gap-2 rounded-md border px-4 py-2.5 text-sm font-medium transition-colors has-[[data-state=checked]]:border-primary has-[[data-state=checked]]:bg-primary/5"
                                        >
                                            <Checkbox
                                                value={value}
                                                checked={data.db_components.includes(value)}
                                                onCheckedChange={(checked) => toggleDb(value, Boolean(checked))}
                                            />
                                            {label}
                                        </label>
                                    ))}
                                </div>
                                <InputError message={errors.db_components} />
                                <p className="text-sm text-muted-foreground">
                                    Selected databases will be installed automatically when the agent connects.
                                </p>
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
