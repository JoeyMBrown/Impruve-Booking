<?php

use Illuminate\Support\Facades\Schedule;

// Run in development with: ./vendor/bin/sail artisan schedule:work
Schedule::command('chat:prune')->hourly();
