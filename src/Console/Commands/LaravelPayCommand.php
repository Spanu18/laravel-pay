<?php

declare(strict_types=1);

namespace LaravelPay\LaravelPay\Console\Commands;

use Illuminate\Console\Command;

class LaravelPayCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'laravel-pay:placeholder';

    /**
     * The command description.
     */
    protected $description = 'Placeholder Artisan command shipped by the package laravel-pay.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('LaravelPay placeholder command executed.');

        return self::SUCCESS;
    }
}
