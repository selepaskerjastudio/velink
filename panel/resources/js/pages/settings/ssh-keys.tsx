import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type SshKey } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { CopyIcon, KeyRoundIcon, Trash2Icon } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'SSH keys', href: '/settings/ssh-keys' }];

const KEY_TYPE_LABELS: Record<string, string> = {
    'ssh-ed25519': 'Ed25519',
    'ssh-rsa': 'RSA',
    'ecdsa-sha2-nistp256': 'ECDSA P-256',
    'ecdsa-sha2-nistp384': 'ECDSA P-384',
    'ecdsa-sha2-nistp521': 'ECDSA P-521',
};

function copyToClipboard(text: string) {
    navigator.clipboard?.writeText(text);
}

export default function SshKeys({ sshKeys, adminUser }: { sshKeys: SshKey[]; adminUser: string }) {
    const { data, setData, post, processing, errors, reset } = useForm<{
        name: string;
        public_key: string;
    }>({
        name: '',
        public_key: '',
    });

    const [copied, setCopied] = useState<string | null>(null);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('ssh-keys.store'), {
            onSuccess: () => reset('name', 'public_key'),
        });
    };

    const destroy = (key: SshKey) => {
        const serversNote = key.servers.length > 0 ? ` It will be removed from ${key.servers.length} server(s).` : '';
        if (confirm(`Remove the key "${key.name}"?${serversNote}`)) {
            router.delete(route('ssh-keys.destroy', key.id));
        }
    };

    const handleCopy = (fingerprint: string) => {
        copyToClipboard(fingerprint);
        setCopied(fingerprint);
        setTimeout(() => setCopied(null), 1500);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="SSH keys" />
            <SettingsLayout>
                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>SSH keys</CardTitle>
                            <CardDescription>
                                Manage the public keys you use to log in to managed servers. Deployed keys grant SSH
                                access to the <code className="text-xs">{adminUser}</code> user on each server.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submit} className="space-y-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="name">Label</Label>
                                    <Input
                                        id="name"
                                        value={data.name}
                                        onChange={(e) => setData('name', e.target.value)}
                                        placeholder="MacBook Pro"
                                        autoComplete="off"
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="public_key">Public key</Label>
                                    <Textarea
                                        id="public_key"
                                        value={data.public_key}
                                        onChange={(e) => setData('public_key', e.target.value)}
                                        placeholder="ssh-ed25519 AAAAC3NzaC1lZDI1NTE5…"
                                        className="font-mono text-xs"
                                        rows={4}
                                        spellCheck={false}
                                    />
                                    <InputError message={errors.public_key} />
                                    <p className="text-muted-foreground text-xs">
                                        Paste your full public key including the <code>ssh-ed25519</code>/<code>ssh-rsa</code> prefix.
                                    </p>
                                </div>
                                <Button type="submit" disabled={processing || data.public_key.trim() === ''}>
                                    {processing ? 'Adding…' : 'Add key'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Your keys</CardTitle>
                            <CardDescription>
                                {sshKeys.length} key{sshKeys.length === 1 ? '' : 's'} registered.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3">
                            {sshKeys.length === 0 && (
                                <p className="text-muted-foreground text-sm">No SSH keys yet. Add one above to get started.</p>
                            )}
                            {sshKeys.map((key) => (
                                <div key={key.id} className="flex flex-col gap-2 rounded-md border p-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="grid gap-1">
                                        <div className="flex items-center gap-2">
                                            <KeyRoundIcon className="text-muted-foreground h-4 w-4" />
                                            <span className="text-sm font-medium">{key.name}</span>
                                            <Badge variant="outline">{KEY_TYPE_LABELS[key.type] ?? key.type}</Badge>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => handleCopy(key.fingerprint)}
                                            className="text-muted-foreground hover:text-foreground inline-flex w-fit items-center gap-1 font-mono text-xs"
                                            title="Copy fingerprint"
                                        >
                                            {key.fingerprint}
                                            <CopyIcon className="h-3 w-3" />
                                            {copied === key.fingerprint && <span className="text-green-600">copied!</span>}
                                        </button>
                                        {key.servers.length > 0 && (
                                            <p className="text-muted-foreground text-xs">
                                                Deployed to: {key.servers.map((s) => s.name).join(', ')}
                                            </p>
                                        )}
                                    </div>
                                    <Button variant="ghost" size="sm" onClick={() => destroy(key)}>
                                        <Trash2Icon className="mr-1.5 h-4 w-4" />
                                        Remove
                                    </Button>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
