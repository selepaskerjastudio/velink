<?php

namespace App\Http\Controllers;

use App\Models\NotificationChannel;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class NotificationChannelController extends Controller
{
    /**
     * List the user's notification channels.
     */
    public function index(Request $request): Response
    {
        $channels = $request->user()->notificationChannels()
            ->latest('id')
            ->get(['id', 'uuid', 'type', 'label', 'enabled', 'created_at'])
            ->map(fn (NotificationChannel $channel) => [
                'id' => $channel->uuid,
                'type' => $channel->type,
                'label' => $channel->label,
                'enabled' => $channel->enabled,
                'created_at' => $channel->created_at,
            ]);

        return Inertia::render('settings/notifications', [
            'channels' => $channels,
        ]);
    }

    /**
     * Store a new notification channel.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(NotificationChannel::TYPES)],
            'label' => ['required', 'string', 'max:255'],
            'config' => ['required', 'array'],
        ]);

        $request->user()->notificationChannels()->create([
            'type' => $validated['type'],
            'label' => $validated['label'],
            'config' => $validated['config'],
            'enabled' => true,
        ]);

        AuditLogger::log(
            action: 'notification.channel_added',
            description: "Notification channel added: {$validated['type']} ({$validated['label']})",
            userId: $request->user()->id,
            properties: ['type' => $validated['type']],
        );

        return redirect()->route('notifications.index');
    }

    /**
     * Toggle a channel on/off.
     */
    public function toggle(Request $request, NotificationChannel $notificationChannel): RedirectResponse
    {
        abort_if($notificationChannel->user_id !== $request->user()->id, 403);

        $notificationChannel->forceFill(['enabled' => ! $notificationChannel->enabled])->save();

        return redirect()->route('notifications.index');
    }

    /**
     * Delete a channel.
     */
    public function destroy(Request $request, NotificationChannel $notificationChannel): RedirectResponse
    {
        abort_if($notificationChannel->user_id !== $request->user()->id, 403);

        $notificationChannel->delete();

        AuditLogger::log(
            action: 'notification.channel_deleted',
            description: "Notification channel removed: {$notificationChannel->label}",
            userId: $request->user()->id,
        );

        return redirect()->route('notifications.index');
    }
}
