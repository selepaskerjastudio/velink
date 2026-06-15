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
                        <CardTitle>Connect with OAuth</CardTitle>
                        <CardDescription>
                            Authorize Velink to access your repositories. The token is managed automatically.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        <a href={route('git-credentials.oauth.redirect', 'github')}>
                            <Button type="button" variant="outline" className="w-full justify-start gap-2">
                                <svg viewBox="0 0 24 24" className="h-4 w-4 fill-current" aria-hidden="true">
                                    <path d="M12 0C5.37 0 0 5.37 0 12c0 5.31 3.435 9.795 8.205 11.385.6.105.825-.255.825-.57 0-.285-.015-1.23-.015-2.235-3.015.555-3.795-.735-4.035-1.41-.135-.345-.72-1.41-1.23-1.695-.42-.225-1.02-.78-.015-.795.945-.015 1.62.87 1.845 1.23 1.08 1.815 2.805 1.305 3.495.99.105-.78.42-1.305.765-1.605-2.67-.3-5.46-1.335-5.46-5.925 0-1.305.465-2.385 1.23-3.225-.12-.3-.54-1.53.12-3.18 0 0 1.005-.315 3.3 1.23.96-.27 1.98-.405 3-.405s2.04.135 3 .405c2.295-1.56 3.3-1.23 3.3-1.23.66 1.65.24 2.88.12 3.18.765.84 1.23 1.905 1.23 3.225 0 4.605-2.805 5.625-5.475 5.925.435.375.81 1.095.81 2.22 0 1.605-.015 2.895-.015 3.3 0 .315.225.69.825.57A12.02 12.02 0 0 0 24 12c0-6.63-5.37-12-12-12z" />
                                </svg>
                                Connect GitHub
                            </Button>
                        </a>
                        <a href={route('git-credentials.oauth.redirect', 'gitlab')}>
                            <Button type="button" variant="outline" className="w-full justify-start gap-2">
                                <svg viewBox="0 0 24 24" className="h-4 w-4 fill-current" aria-hidden="true">
                                    <path d="M22.65 14.39L12 22.13 1.35 14.39a.84.84 0 0 1-.3-.94l1.22-3.78 2.44-7.51A.42.42 0 0 1 4.82 2a.43.43 0 0 1 .58 0 .42.42 0 0 1 .11.18l2.44 7.49h8.1l2.44-7.51A.42.42 0 0 1 18.6 2a.43.43 0 0 1 .58 0 .42.42 0 0 1 .11.18l2.44 7.51L23 13.45a.84.84 0 0 1-.35.94z" />
                                </svg>
                                Connect GitLab
                            </Button>
                        </a>
                        <p className="text-muted-foreground text-xs">
                            Requires GitHub/GitLab OAuth app credentials to be configured in{' '}
                            <code>.env</code> (GITHUB_CLIENT_ID, GITHUB_CLIENT_SECRET).
                        </p>
                    </CardContent>
                </Card>

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
