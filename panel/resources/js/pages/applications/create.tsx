import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';

function filterPhpVersions(versions: string[], os: string | null | undefined): string[] {
    if (!os) return versions;
    const m = os.match(/Ubuntu\s+(\d+)/i);
    if (!m) return versions;
    return parseInt(m[1], 10) >= 24 ? versions.filter((v) => v !== '7.4') : versions;
}

const ENGINE_LABELS: Record<string, string> = {
    mariadb: 'MariaDB',
    postgres: 'PostgreSQL',
    mongodb: 'MongoDB',
};

type AppType = { value: string; label: string; description: string };
type GitCredential = {
    id: string;
    account_username: string;
    provider: { type: string; name: string };
};

const NO_CREDENTIAL = '__none__';

export default function ApplicationsCreate({
    server,
    phpVersions,
    appTypes,
    installedEngines,
    gitCredentials,
}: {
    server: { id: string; name: string; os?: string | null };
    phpVersions: string[];
    appTypes: AppType[];
    installedEngines: string[];
    gitCredentials: GitCredential[];
}) {
    const available = filterPhpVersions(phpVersions, server.os);
    const hasEngines = installedEngines.length > 0;

    const { data, setData, post, processing, errors } = useForm({
        app_type: 'custom',
        name: '',
        domain: '',
        php_version: available[available.length - 1] ?? '',
        stack_mode: 'production',
        git_credential_id: '',
        repository: '',
        branch: 'main',
        create_database: false,
        db_engine: '',
        db_name: '',
        db_username: '',
    });

    const isWordpress = data.app_type === 'wordpress';
    const isStatic = data.app_type === 'static';

    // WordPress requires a database — force it on.
    useEffect(() => {
        if (isWordpress && !data.create_database) {
            setData('create_database', true);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isWordpress]);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: 'New application', href: `/servers/${server.id}/applications/create` },
    ];

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('applications.store', server.id));
    };

    const dbEnabled = data.create_database && hasEngines;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New application" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <h1 className="text-xl font-semibold">Deploy new web app on {server.name}</h1>

                <Card className="max-w-2xl">
                    <CardContent>
                        <form onSubmit={submit} className="space-y-6">
                            {/* App type cards */}
                            <div className="grid gap-2">
                                <Label>Application type</Label>
                                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                    {appTypes.map((type) => {
                                        const selected = data.app_type === type.value;
                                        return (
                                            <button
                                                key={type.value}
                                                type="button"
                                                onClick={() => setData('app_type', type.value)}
                                                aria-pressed={selected}
                                                className={cn(
                                                    'flex flex-col items-start gap-1 rounded-lg border p-3 text-left transition-colors',
                                                    'hover:border-primary/60 focus-visible:ring-ring focus-visible:ring-2 focus-visible:outline-hidden',
                                                    selected ? 'border-primary ring-primary bg-primary/5 ring-2' : 'border-input',
                                                )}
                                            >
                                                <span className="text-sm font-medium">{type.label}</span>
                                                <span className="text-muted-foreground text-xs">{type.description}</span>
                                            </button>
                                        );
                                    })}
                                </div>
                                <InputError message={errors.app_type} />
                            </div>

                            {/* Name */}
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

                            {/* Domain */}
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

                            {/* PHP version — hidden for static sites */}
                            {!isStatic && (
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
                            )}

                            {/* Stack mode */}
                            <div className="grid gap-2">
                                <Label>Stack mode</Label>
                                <div className="border-input inline-flex w-fit rounded-lg border p-1">
                                    {(['production', 'development'] as const).map((mode) => (
                                        <Button
                                            key={mode}
                                            type="button"
                                            size="sm"
                                            variant={data.stack_mode === mode ? 'default' : 'ghost'}
                                            onClick={() => setData('stack_mode', mode)}
                                            className="capitalize"
                                        >
                                            {mode}
                                        </Button>
                                    ))}
                                </div>
                                <p className="text-muted-foreground text-xs">
                                    Development enables <code>display_errors</code> for easier debugging.
                                </p>
                                <InputError message={errors.stack_mode} />
                            </div>

                            {/* Git repository */}
                            <div className="border-input space-y-4 rounded-lg border p-4">
                                <h2 className="text-sm font-semibold">Git repository (optional)</h2>

                                <div className="grid gap-2">
                                    <Label htmlFor="git_credential_id">Credential</Label>
                                    <Select
                                        value={data.git_credential_id === '' ? NO_CREDENTIAL : data.git_credential_id}
                                        onValueChange={(value) => setData('git_credential_id', value === NO_CREDENTIAL ? '' : value)}
                                    >
                                        <SelectTrigger id="git_credential_id">
                                            <SelectValue placeholder="None / public repo" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value={NO_CREDENTIAL}>None / public repo</SelectItem>
                                            {gitCredentials.map((cred) => (
                                                <SelectItem key={cred.id} value={cred.id}>
                                                    {cred.provider.name}: {cred.account_username}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.git_credential_id} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="repository">Repository</Label>
                                    <Input
                                        id="repository"
                                        value={data.repository}
                                        onChange={(e) => setData('repository', e.target.value)}
                                        placeholder="owner/repo"
                                    />
                                    <InputError message={errors.repository} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="branch">Branch</Label>
                                    <Input id="branch" value={data.branch} onChange={(e) => setData('branch', e.target.value)} placeholder="main" />
                                    <InputError message={errors.branch} />
                                </div>
                            </div>

                            {/* Database */}
                            <div className="border-input space-y-4 rounded-lg border p-4">
                                <h2 className="text-sm font-semibold">Database</h2>

                                {!hasEngines && !isWordpress && (
                                    <p className="text-muted-foreground text-xs">No database engine installed on this server.</p>
                                )}

                                {!hasEngines && isWordpress && (
                                    <p className="text-destructive text-xs font-medium">
                                        WordPress requires a database, but no database engine is installed on this server. Install one before
                                        deploying a WordPress site.
                                    </p>
                                )}

                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="create_database"
                                        checked={data.create_database}
                                        disabled={isWordpress || !hasEngines}
                                        onCheckedChange={(checked) => setData('create_database', checked === true)}
                                    />
                                    <Label htmlFor="create_database" className="font-normal">
                                        Create a database for this app
                                    </Label>
                                </div>
                                {isWordpress && hasEngines && <p className="text-muted-foreground text-xs">WordPress requires a database.</p>}
                                <InputError message={errors.create_database} />

                                {dbEnabled && (
                                    <div className="space-y-4">
                                        <div className="grid gap-2">
                                            <Label htmlFor="db_engine">Engine</Label>
                                            <Select value={data.db_engine} onValueChange={(value) => setData('db_engine', value)}>
                                                <SelectTrigger id="db_engine">
                                                    <SelectValue placeholder="Select an engine" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {installedEngines.map((engine) => (
                                                        <SelectItem key={engine} value={engine}>
                                                            {ENGINE_LABELS[engine] ?? engine}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                            <InputError message={errors.db_engine} />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="db_name">Database name</Label>
                                            <Input
                                                id="db_name"
                                                value={data.db_name}
                                                onChange={(e) => setData('db_name', e.target.value)}
                                                placeholder="e.g. myapp"
                                            />
                                            <InputError message={errors.db_name} />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="db_username">Database username</Label>
                                            <Input
                                                id="db_username"
                                                value={data.db_username}
                                                onChange={(e) => setData('db_username', e.target.value)}
                                                placeholder="e.g. myapp_user"
                                            />
                                            <InputError message={errors.db_username} />
                                        </div>
                                    </div>
                                )}
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
