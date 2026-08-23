<?php

namespace justinholtweb\blaster\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\blaster\Plugin;
use yii\console\ExitCode;

/**
 * `craft blaster/bars/...`
 */
class BarsController extends Controller
{
    /** Site handle to read bars for. Defaults to the primary site. */
    public ?string $site = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['site']);
    }

    /**
     * Lists every bar with its status, position and lifetime counts.
     *
     * The quickest answer to "why is nothing showing on the site" — a bar that is pending or
     * expired looks identical to a live one in every other way.
     */
    public function actionList(): int
    {
        $site = $this->site
            ? Craft::$app->getSites()->getSiteByHandle($this->site)
            : Craft::$app->getSites()->getPrimarySite();

        if (!$site) {
            $this->stderr("No site with the handle “{$this->site}”.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $bars = Plugin::getInstance()->bars->getAllBars($site->id);

        if (!$bars) {
            $this->stdout("No bars yet.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->stdout(sprintf("%-24s %-10s %-9s %-8s %8s %8s\n", 'HANDLE', 'STATUS', 'POSITION', 'PRIORITY', 'VIEWS', 'CLICKS'));

        foreach ($bars as $bar) {
            $totals = Plugin::getInstance()->stats->totalsForBar($bar->id, $site->id);

            $this->stdout(sprintf(
                "%-24s %-10s %-9s %8d %8d %8d\n",
                mb_strimwidth($bar->handle, 0, 24, '…'),
                (string)$bar->getStatus(),
                $bar->position,
                $bar->priority,
                $totals['views'],
                $totals['clicks'],
            ));
        }

        return ExitCode::OK;
    }
}
