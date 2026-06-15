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

    public function __construct(public AgentJob $job)
    {
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('server.'.$this->job->server_id)];
    }

    public function broadcastAs(): string
    {
        return 'agent-job.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'uuid' => $this->job->uuid,
            'type' => $this->job->type,
            'status' => $this->job->status,
            'exit_code' => $this->job->exit_code,
            'output' => $this->job->output,
        ];
    }
}
