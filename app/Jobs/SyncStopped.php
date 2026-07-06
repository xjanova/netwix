<?php

namespace App\Jobs;

/**
 * Thrown from [SyncCatalogJob]'s progress callback when the admin presses "หยุดกลางคัน" (the stop flag
 * is set in cache), to abort the in-progress catalogue sync at the next page boundary. Caught by the
 * job, which marks the run "stopped".
 */
class SyncStopped extends \RuntimeException {}
