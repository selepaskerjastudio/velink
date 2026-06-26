import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type CloudflareTokenSummary } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { CloudIcon, Trash2Icon } from 'lucide-react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Cloudflare', href: '/settings/cloudflare' }];

export default function CloudflareSettings({ tokens }: { tokens: CloudflareTokenSummary[] }) {
    const { data, setData, post, processing, errors, reset } = useForm<{
        email: string;
        api_token: string;
    }>({
        email: '',
        api_token: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('cloudflare.store'), { onSuccess: () => reset('email', 'api_token') });
    };

    const destroy = (id: string) => {
        if (confirm('Remove this Cloudflare token?')) {
            router.delete(route('cloudflare.destroy', id));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cloudflare" />
            <SettingsLayout>
                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <CloudIcon className="h-5 w-5" />
                                Connect Cloudflare
                            </CardTitle>
                            <CardDescription>
                                Connect your Cloudflare account to enable automatic DNS record creation and DNS-01 SSL challenges (faster, no propagation wait, wildcard support).
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submit} className="space-y-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="cf-email">Account email (optional)</Label>
                                    <Input id="cf-email" type="email" value={data.email}
                                        onChange={(e) => setData('email', e.target.value)}
                                        placeholder="admin@example.com" />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="cf-token">API token</Label>
                                    <Input id="cf-token" type="password" value={data.api_token}
                                        onChange={(e) => setData('api_token', e.target.value)}
                                        placeholder="Cloudflare API token" autoComplete="off" />
                                    <InputError message={errors.api_token} />
                                    <p className="text-muted-foreground text-xs">
                                        Create a token at{' '}
                                        <a href="https://dash.cloudflare.com/profile/api-tokens" target="_blank" rel="noopener" className="underline">
                                            Cloudflare Dashboard → API Tokens
                                        </a>{' '}
                                        with "Zone → DNS → Edit" and "Zone → Zone → Read" permissions. The token is verified before saving.
                                    </p>
                                </div>
                                <Button type="submit" disabled={processing || data.api_token.trim() === ''}>
                                    {processing ? 'Verifying…' : 'Verify & save'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>

                    {tokens.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Connected accounts</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-2">
                                {tokens.map((token) => (
                                    <div key={token.id} className="flex items-center justify-between rounded-md border px-3 py-2">
                                        <div className="flex items-center gap-2">
                                            <CloudIcon className="text-muted-foreground h-4 w-4" />
                                            <span className="text-sm font-medium">{token.email ?? '—'}</span>
                                            {token.verified && <Badge variant="outline" className="text-xs">verified</Badge>}
                                        </div>
                                        <Button variant="ghost" size="sm" onClick={() => destroy(token.id)}>
                                            <Trash2Icon className="h-4 w-4" />
                                        </Button>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
