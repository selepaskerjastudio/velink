import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type NotificationChannelSummary } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { BellIcon, Trash2Icon } from 'lucide-react';
import { FormEventHandler, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Notifications', href: '/settings/notifications' }];

const TYPE_LABELS: Record<string, string> = {
    email: 'Email',
    slack: 'Slack',
    discord: 'Discord',
    telegram: 'Telegram',
};

export default function NotificationSettings({ channels }: { channels: NotificationChannelSummary[] }) {
    const [type, setType] = useState('email');
    const { data, setData, post, processing, errors, reset } = useForm<{
        type: string;
        label: string;
        config: Record<string, string>;
    }>({
        type: 'email',
        label: '',
        config: {},
    });

    // Separate state for the config input fields (webhook, bot token, etc).
    const [webhookUrl, setWebhookUrl] = useState('');
    const [botToken, setBotToken] = useState('');
    const [chatId, setChatId] = useState('');

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        const config: Record<string, string> = {};
        if (type === 'slack' || type === 'discord') {
            config.webhook_url = webhookUrl;
        } else if (type === 'telegram') {
            config.bot_token = botToken;
            config.chat_id = chatId;
        }

        setData('type', type);
        setData('config', config);
        post(route('notifications.store'), {
            preserveScroll: true,
            onSuccess: () => { reset('label'); setWebhookUrl(''); setBotToken(''); setChatId(''); },
        });
    };

    const toggle = (channel: NotificationChannelSummary) => {
        router.patch(route('notifications.toggle', channel.id), {}, { preserveScroll: true });
    };

    const destroy = (channel: NotificationChannelSummary) => {
        if (confirm(`Remove "${channel.label}"?`)) {
            router.delete(route('notifications.destroy', channel.id));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notifications" />
            <SettingsLayout>
                <div className="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <BellIcon className="h-5 w-5" />
                                Add notification channel
                            </CardTitle>
                            <CardDescription>
                                Receive alerts when server metrics (CPU, disk, memory) cross thresholds. All channels receive alerts from all servers.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submit} className="space-y-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="nc-type">Channel type</Label>
                                    <Select value={type} onValueChange={setType}>
                                        <SelectTrigger id="nc-type"><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="email">Email</SelectItem>
                                            <SelectItem value="slack">Slack</SelectItem>
                                            <SelectItem value="discord">Discord</SelectItem>
                                            <SelectItem value="telegram">Telegram</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="nc-label">Label</Label>
                                    <Input id="nc-label" value={data.label}
                                        onChange={(e) => setData('label', e.target.value)}
                                        placeholder="Dev Team Slack" />
                                    <InputError message={errors.label} />
                                </div>
                                {(type === 'slack' || type === 'discord') && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="nc-webhook">Webhook URL</Label>
                                        <Input id="nc-webhook" type="password" value={webhookUrl}
                                            onChange={(e) => setWebhookUrl(e.target.value)}
                                            placeholder={type === 'slack' ? 'https://hooks.slack.com/services/...' : 'https://discord.com/api/webhooks/...'} />
                                    </div>
                                )}
                                {type === 'telegram' && (
                                    <>
                                        <div className="grid gap-2">
                                            <Label htmlFor="nc-bot">Bot token</Label>
                                            <Input id="nc-bot" type="password" value={botToken}
                                                onChange={(e) => setBotToken(e.target.value)}
                                                placeholder="123456:ABC-DEF..." />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="nc-chat">Chat ID</Label>
                                            <Input id="nc-chat" value={chatId}
                                                onChange={(e) => setChatId(e.target.value)}
                                                placeholder="-1001234567890" />
                                        </div>
                                    </>
                                )}
                                {type === 'email' && (
                                    <p className="text-muted-foreground text-xs">
                                        Emails are sent to your account email address. Configure SMTP in the server's <code>.env</code> to enable delivery.
                                    </p>
                                )}
                                <Button type="submit" disabled={processing || !data.label.trim()}>
                                    {processing ? 'Adding…' : 'Add channel'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>

                    {channels.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Channels</CardTitle>
                                <CardDescription>{channels.length} channel(s) configured.</CardDescription>
                            </CardHeader>
                            <CardContent className="grid gap-2">
                                {channels.map((channel) => (
                                    <div key={channel.id} className="flex items-center justify-between rounded-md border px-3 py-2">
                                        <div className="flex items-center gap-2">
                                            <BellIcon className="text-muted-foreground h-4 w-4" />
                                            <span className="text-sm font-medium">{channel.label}</span>
                                            <Badge variant="outline" className="text-xs">{TYPE_LABELS[channel.type] ?? channel.type}</Badge>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Button variant={channel.enabled ? 'default' : 'outline'} size="sm" onClick={() => toggle(channel)}>
                                                {channel.enabled ? 'on' : 'off'}
                                            </Button>
                                            <Button variant="ghost" size="sm" onClick={() => destroy(channel)}>
                                                <Trash2Icon className="h-4 w-4" />
                                            </Button>
                                        </div>
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
