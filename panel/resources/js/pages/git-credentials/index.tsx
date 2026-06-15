import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type GitCredential } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Git credentials', href: '/git-credentials' }];

const PROVIDER_LABELS: Record<string, string> = {
    github: 'GitHub',
    gitlab: 'GitLab',
};

export default function GitCredentialsIndex({ credentials }: { credentials: GitCredential[] }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        provider_type: 'github',
        account_username: '',
        access_token: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('git-credentials.store'), { onSuccess: () => reset('account_username', 'access_token') });
    };

    const destroy = (id: string) => {
        if (confirm('Remove this credential? Applications using it will need a new one.')) {
            router.delete(route('git-credentials.destroy', id));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Git credentials" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <h1 className="text-xl font-semibold">Git credentials</h1>

                <Card className="max-w-xl">
                    <CardHeader>
                        <CardTitle>Add a personal access token</CardTitle>
                        <CardDescription>
                            Used by the agent to clone/pull private repositories during deploys. Tokens are encrypted at rest.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-4">
                            <div className="grid gap-2">
                                <Label htmlFor="provider_type">Provider</Label>
                                <Select value={data.provider_type} onValueChange={(value) => setData('provider_type', value)}>
                                    <SelectTrigger id="provider_type" className="max-w-40">
                                        <SelectValue placeholder="Select a provider" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="github">GitHub</SelectItem>
                                        <SelectItem value="gitlab">GitLab</SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.provider_type} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="account_username">Label / account username</Label>
                                <Input
                                    id="account_username"
                                    value={data.account_username}
                                    onChange={(e) => setData('account_username', e.target.value)}
                                    placeholder="e.g. my-github-account"
                                />
                                <InputError message={errors.account_username} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="access_token">Personal access token</Label>
                                <Input
                                    id="access_token"
                                    type="password"
                                    autoComplete="off"
                                    value={data.access_token}
                                    onChange={(e) => setData('access_token', e.target.value)}
                                    placeholder="ghp_... or glpat-..."
                                />
                                <InputError message={errors.access_token} />
                            </div>

                            <Button type="submit" disabled={processing}>
                                Add credential
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                {credentials.length > 0 && (
                    <Card className="max-w-xl">
                        <CardHeader>
                            <CardTitle>Saved credentials</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-2">
                            {credentials.map((credential) => (
                                <div key={credential.id} className="flex items-center justify-between rounded-md border px-3 py-2">
                                    <div className="flex flex-col">
                                        <span className="text-sm font-medium">{credential.account_username ?? '—'}</span>
                                        <span className="text-muted-foreground text-xs">{credential.created_at}</span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Badge variant="outline">{PROVIDER_LABELS[credential.provider.type] ?? credential.provider.type}</Badge>
                                        <Button variant="ghost" size="sm" onClick={() => destroy(credential.id)}>
                                            Remove
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
