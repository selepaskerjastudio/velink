import InputError from '@/components/input-error';
import HeadingSmall from '@/components/heading-small';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Two-factor authentication',
        href: '/settings/two-factor',
    },
];

interface TwoFactorProps {
    twoFactorEnabled: boolean;
    qrCodeSvg: string | null;
    secretKey: string | null;
    recoveryCodes: string[] | null;
}

export default function TwoFactor({ twoFactorEnabled, qrCodeSvg, secretKey, recoveryCodes }: TwoFactorProps) {
    const enableForm = useForm({});
    const confirmForm = useForm({ code: '' });
    const disableForm = useForm({});
    const recoveryForm = useForm({});

    const enable: FormEventHandler = (e) => {
        e.preventDefault();
        enableForm.post(route('two-factor.enable'));
    };

    const confirm: FormEventHandler = (e) => {
        e.preventDefault();
        confirmForm.post(route('two-factor.confirm'), {
            onSuccess: () => confirmForm.reset(),
        });
    };

    const disable: FormEventHandler = (e) => {
        e.preventDefault();
        disableForm.delete(route('two-factor.destroy'));
    };

    const regenerateRecoveryCodes: FormEventHandler = (e) => {
        e.preventDefault();
        recoveryForm.post(route('two-factor.recovery-codes'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Two-factor authentication" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Two-factor authentication"
                        description="Add an extra layer of security to your account using a TOTP authenticator app"
                    />

                    {twoFactorEnabled ? (
                        <div className="space-y-6">
                            <Alert>
                                <AlertTitle>Two-factor authentication is enabled</AlertTitle>
                                <AlertDescription>
                                    Your account is protected with an authenticator app.
                                </AlertDescription>
                            </Alert>

                            {recoveryCodes && (
                                <div className="space-y-2">
                                    <Label>Recovery codes</Label>
                                    <p className="text-muted-foreground text-sm">
                                        Store these codes in a secure password manager. Each code can be used once to
                                        sign in if you lose access to your authenticator app.
                                    </p>
                                    <div className="bg-muted grid gap-1 rounded-md p-4 font-mono text-sm">
                                        {recoveryCodes.map((code) => (
                                            <div key={code}>{code}</div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            <div className="flex items-center gap-4">
                                <form onSubmit={regenerateRecoveryCodes}>
                                    <Button type="submit" variant="outline" disabled={recoveryForm.processing}>
                                        Regenerate recovery codes
                                    </Button>
                                </form>

                                <form onSubmit={disable}>
                                    <Button type="submit" variant="destructive" disabled={disableForm.processing}>
                                        Disable two-factor authentication
                                    </Button>
                                </form>
                            </div>
                        </div>
                    ) : qrCodeSvg ? (
                        <div className="space-y-6">
                            <div className="space-y-2">
                                <p className="text-muted-foreground text-sm">
                                    Scan the QR code below with your authenticator app, then enter the generated code
                                    to confirm.
                                </p>
                                <div
                                    className="bg-white inline-block rounded-md p-4"
                                    dangerouslySetInnerHTML={{ __html: qrCodeSvg }}
                                />
                                {secretKey && (
                                    <p className="text-muted-foreground text-sm">
                                        Or enter this code manually: <span className="font-mono">{secretKey}</span>
                                    </p>
                                )}
                            </div>

                            <form onSubmit={confirm} className="space-y-4">
                                <div className="grid max-w-xs gap-2">
                                    <Label htmlFor="code">Authentication code</Label>
                                    <Input
                                        id="code"
                                        type="text"
                                        inputMode="numeric"
                                        autoFocus
                                        value={confirmForm.data.code}
                                        onChange={(e) => confirmForm.setData('code', e.target.value)}
                                        placeholder="123456"
                                    />
                                    <InputError message={confirmForm.errors.code} />
                                </div>

                                <Button type="submit" disabled={confirmForm.processing}>
                                    Confirm and enable
                                </Button>
                            </form>
                        </div>
                    ) : (
                        <form onSubmit={enable}>
                            <Button type="submit" disabled={enableForm.processing}>
                                Enable two-factor authentication
                            </Button>
                        </form>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
