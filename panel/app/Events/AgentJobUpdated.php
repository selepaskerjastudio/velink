<?php

namespace App\Events;

use App\Models\AgentJob;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AgentJobUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(public AgentJob $job) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('server.'.$this->job->server->uuid)];
    }

    public function broadcastAs(): string
    {
        return 'agent-job.updated';
    }

    /**
     * Keep the broadcast payload under Reverb's max message size (10 KB).
     * Long-running jobs (deploy/provision) can emit hundreds of KB of output;
     * send only the tail here — the full log is read from the DB by the
     * deployment/activity log viewers.
     */
    private const MAX_OUTPUT_CHARS = 8000;

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'uuid' => $this->job->uuid,
            'type' => $this->job->type,
            'label' => $this->job->label,
            'status' => $this->job->status,
            'exit_code' => $this->job->exit_code,
            'output' => $this->job->output !== null
                ? mb_substr($this->job->output, -self::MAX_OUTPUT_CHARS)
                : null,
        ];
    }
}
