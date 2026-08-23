<?php

namespace justinholtweb\blaster\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use justinholtweb\blaster\elements\Bar;
use justinholtweb\blaster\Plugin;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The control panel screens.
 *
 * A hand-written edit screen rather than Craft's generic element editor: a bar has no field
 * layout to lay out, and what it does have — five tabs of switches with a live preview — is not
 * something the generic editor knows how to draw.
 */
class BarsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(Plugin::PERMISSION_VIEW);

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('blaster/bars/_index', [
            'title' => Craft::t('blaster', 'Bars'),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
        ]);
    }

    public function actionEdit(?int $barId = null, ?string $siteHandle = null, ?Bar $bar = null): Response
    {
        $site = $siteHandle
            ? Craft::$app->getSites()->getSiteByHandle($siteHandle)
            : Craft::$app->getSites()->getCurrentSite();

        if (!$site) {
            throw new NotFoundHttpException('Site not found');
        }

        // $bar arrives already populated when a failed save re-renders the form, so the author's
        // unsaved work survives the round trip rather than being reloaded from the database.
        if ($bar === null) {
            $bar = $barId
                ? Plugin::getInstance()->bars->getBarById($barId, $site->id)
                : $this->newBar($site->id);
        }

        if ($bar === null) {
            throw new NotFoundHttpException('Bar not found');
        }

        return $this->renderTemplate('blaster/bars/_edit', [
            'bar' => $bar,
            'site' => $site,
            'isNew' => !$bar->id,
            'title' => $bar->id ? $bar->getUiLabel() : Craft::t('blaster', 'Create a bar'),
            'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE),
            'canDelete' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_DELETE),
            'sectionOptions' => $this->sectionOptions(),
            'userGroupOptions' => $this->userGroupOptions(),
            'siteOptions' => $this->siteOptions(),
            'stats' => $bar->id ? Plugin::getInstance()->stats->totalsForBar($bar->id) : null,
            'series' => $bar->id ? Plugin::getInstance()->stats->series($bar->id, 30) : [],
        ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $request = Craft::$app->getRequest();
        $barId = $request->getBodyParam('barId');
        $siteId = (int)($request->getBodyParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id);

        $bar = $barId
            ? Plugin::getInstance()->bars->getBarById((int)$barId, $siteId)
            : $this->newBar($siteId);

        if ($bar === null) {
            throw new NotFoundHttpException('Bar not found');
        }

        $this->populate($bar);

        if (!Plugin::getInstance()->bars->saveBar($bar)) {
            Craft::$app->getSession()->setError(Craft::t('blaster', 'Couldn’t save bar.'));

            // Hand the populated element back to the edit action so the form redraws as typed.
            Craft::$app->getUrlManager()->setRouteParams(['bar' => $bar]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('blaster', 'Bar saved.'));

        return $this->redirectToPostedUrl($bar);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_DELETE);

        $bar = Plugin::getInstance()->bars->getBarById((int)Craft::$app->getRequest()->getRequiredBodyParam('barId'));

        if ($bar === null) {
            throw new NotFoundHttpException('Bar not found');
        }

        if (!Plugin::getInstance()->bars->deleteBar($bar)) {
            return $this->asFailure(Craft::t('blaster', 'Couldn’t delete bar.'));
        }

        return $this->asSuccess(
            Craft::t('blaster', 'Bar deleted.'),
            redirect: UrlHelper::cpUrl('blaster/bars'),
        );
    }

    public function actionDuplicate(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $bar = Plugin::getInstance()->bars->getBarById((int)Craft::$app->getRequest()->getRequiredBodyParam('barId'));

        if ($bar === null) {
            throw new NotFoundHttpException('Bar not found');
        }

        $copy = Plugin::getInstance()->bars->duplicateBar($bar);

        if ($copy === null) {
            return $this->asFailure(Craft::t('blaster', 'Couldn’t duplicate bar.'));
        }

        return $this->asSuccess(
            Craft::t('blaster', 'Bar duplicated.'),
            redirect: $copy->getCpEditUrl(),
        );
    }

    /**
     * Renders the bar as posted, without saving it.
     *
     * The preview goes through {@see \justinholtweb\blaster\services\Renderer} — the same code
     * that serves the front end — rather than being rebuilt in JavaScript from the form fields.
     * A preview drawn by different code from the thing it previews is a preview of nothing, and
     * the colour that looked right in the control panel is exactly the one that will not match.
     */
    public function actionPreview(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission(Plugin::PERMISSION_MANAGE);

        $siteId = (int)(Craft::$app->getRequest()->getBodyParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id);
        $bar = $this->newBar($siteId);
        $bar->id = (int)(Craft::$app->getRequest()->getBodyParam('barId') ?: 0) ?: -1;

        $this->populate($bar);

        return $this->asJson([
            'html' => Plugin::getInstance()->renderer->previewHtml($bar),
        ]);
    }

    private function newBar(int $siteId): Bar
    {
        $bar = new Bar();
        $bar->siteId = $siteId;
        $bar->enabled = true;

        return $bar;
    }

    /**
     * Copies the posted form onto the element.
     *
     * The message is purified here rather than on render: cleaning once on the way in means the
     * front end serves stored bytes, and a bar rendered on every page of a busy site does not pay
     * for HTML Purifier on every request.
     */
    private function populate(Bar $bar): void
    {
        $request = Craft::$app->getRequest();

        $bar->title = (string)$request->getBodyParam('title', $bar->title);
        $bar->handle = (string)$request->getBodyParam('handle', $bar->handle);
        $bar->enabled = (bool)$request->getBodyParam('enabled', $bar->enabled);
        $bar->position = (string)$request->getBodyParam('position', $bar->position);
        $bar->priority = (int)$request->getBodyParam('priority', $bar->priority);

        $bar->setDisplay((array)$request->getBodyParam('display', []));
        $bar->setTargeting((array)$request->getBodyParam('targeting', []));
        $bar->setSchedule((array)$request->getBodyParam('schedule', []));
        $bar->setTheme((array)$request->getBodyParam('theme', []));

        $content = (array)$request->getBodyParam('content', []);
        $content['message'] = Plugin::getInstance()->purifyMessage((string)($content['message'] ?? ''));

        $bar->setContent($content);

        if ($bar->handle === '' && $bar->title !== '') {
            $bar->handle = Plugin::getInstance()->bars->uniqueHandle($bar->title, $bar->id);
        }
    }

    /** @return array<int, array{label: string, value: string}> */
    private function sectionOptions(): array
    {
        return array_map(
            fn($section) => ['label' => $section->name, 'value' => $section->uid],
            Craft::$app->getEntries()->getAllSections(),
        );
    }

    /** @return array<int, array{label: string, value: string}> */
    private function userGroupOptions(): array
    {
        return array_map(
            fn($group) => ['label' => $group->name, 'value' => $group->uid],
            Craft::$app->getUserGroups()->getAllGroups(),
        );
    }

    /** @return array<int, array{label: string, value: string}> */
    private function siteOptions(): array
    {
        return array_map(
            fn($site) => ['label' => $site->name, 'value' => $site->uid],
            Craft::$app->getSites()->getAllSites(),
        );
    }
}
