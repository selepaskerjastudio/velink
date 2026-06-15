import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

export default function TwoFactorChallenge() {
    const [useRecoveryCode, setUseRecoveryCode] = useState(false);

    const { data, setData, post, processing, errors, reset } = useForm({
        code: '',
        recovery_code: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('two-factor.login'), {
            onFinish: () => reset('code', 'recovery_code'),
        });
    };

    const toggleRecoveryCode = () => {
        setUseRecoveryCode((prev) => !prev);
        reset('code', 'recovery_code');
    };

    return (
        <AuthLayout
            title="Two-factor authentication"
            description={
                useRecoveryCode
                    ? 'Enter one of your emergency recovery codes'
                    : 'Enter the authentication code from your app'
            }
        >
            <Head title="Two-factor authentication" />

            <form className="flex flex-col gap-6" onSubmit={submit}>
                <div className="grid gap-6">
                    {useRecoveryCode ? (
                        <div className="grid gap-2">
                            <Label htmlFor="recovery_code">Recovery code</Label>
                            <Input
                                id="recovery_code"
                                type="text"
                                autoFocus
                                autoComplete="one-time-code"
                                value={data.recovery_code}
                                onChange={(e) => setData('recovery_code', e.target.value)}
                                placeholder="xxxxxxxxxx-xxxxxxxxxx"
                            />
                            <InputError message={errors.recovery_code} />
                        </div>
                    ) : (
                        <div className="grid gap-2">
                            <Label htmlFor="code">Authentication code</Label>
                            <Input
                                id="code"
                                type="text"
                                inputMode="numeric"
                                autoFocus
                                autoComplete="one-time-code"
                                value={data.code}
                                onChange={(e) => setData('code', e.target.value)}
                                placeholder="123456"
                            />
                            <InputError message={errors.code} />
                        </div>
                    )}

                    <Button type="submit" className="w-full" disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" />}
                        Continue
                    </Button>
                </div>

                <div className="text-muted-foreground text-center text-sm">
                    <button
                        type="button"
                        onClick={toggleRecoveryCode}
                        className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                    >
                        {useRecoveryCode ? 'Use an authentication code instead' : 'Use a recovery code instead'}
                    </button>
                </div>
            </form>
        </AuthLayout>
    );
}
