<?php

namespace App\Console\Commands;

use App\Services\StockLocationProvisioner;
use Illuminate\Console\Command;

/**
 * Backfill the mandatory default stock location for existing stores.
 *
 * Idempotent: stores that already have a default location are skipped, so this
 * can be run repeatedly without creating duplicates and without touching any
 * order or movement history.
 */
class ProvisionStockLocations extends Command
{
    protected $signature = 'stock:provision-locations';

    protected $description = 'Create a default stock location for every store that does not have one yet (idempotent).';

    public function handle(StockLocationProvisioner $provisioner): int
    {
        $count = $provisioner->provisionMissingLocations();

        $this->info("Default stock locations provisioned: {$count}.");

        return self::SUCCESS;
    }
}
