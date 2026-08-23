<?php

namespace justinholtweb\blaster\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\blaster\Plugin;
use yii\console\ExitCode;

/**
 * `craft blaster/stats/...`
 */
class StatsController extends Controller
{
    /** Override the retention setting for one run. */
    public ?int $days = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'prune' ? ['days'] : []);
    }

    /**
     * Deletes daily counters older than the retention setting.
     *
     * Worth putting on a schedule: the table gains one row per bar, per site, per active day, so
     * it grows slowly and forever unless something removes the old end.
     */
    public function actionPrune(): int
    {
        $deleted = Plugin::getInstance()->stats->prune($this->days);
        $orphans = Plugin::getInstance()->stats->collectGarbage();

        $this->stdout("Deleted $deleted expired rows", Console::FG_GREEN);
        $this->stdout($orphans ? " and $orphans orphaned rows.\n" : ".\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}
