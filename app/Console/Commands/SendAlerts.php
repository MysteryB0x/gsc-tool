<?php

namespace App\Console\Commands;

use App\Alerts\DigestSender;
use Illuminate\Console\Command;

class SendAlerts extends Command
{
    protected $signature = 'alerts:send {period=daily : daily or weekly}';

    protected $description = 'Send the daily or weekly change digests (only to people with something big to see)';

    public function handle(DigestSender $sender): int
    {
        $period = $this->argument('period');
        if (! in_array($period, ['daily', 'weekly'], true)) {
            $this->error('Period must be daily or weekly.');

            return self::INVALID;
        }

        $this->info('Sent '.$sender->send($period)." {$period} digests.");

        return self::SUCCESS;
    }
}
