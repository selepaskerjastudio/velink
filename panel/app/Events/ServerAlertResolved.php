<?php

namespace App\Events;

use App\Models\ServerAlert;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ServerAlertResolved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ServerAlert $alert
    ) {}
}
