<?php

namespace justinholtweb\blaster\models;

use Craft;

/**
 * What a bar says on one site.
 *
 * Split from the bar's configuration because the wording is the part that gets translated, while
 * the colours, targeting and schedule are not — a Spanish visitor should get a Spanish message
 * from the same bar, not a second bar someone has to remember to keep in step.
 */
class BarContent extends ConfigModel
{
    public ?int $siteId = null;

    /** Purified HTML. Editors are trusted with links and emphasis, not with scripts. */
    public string $message = '';

    public bool $buttonEnabled = false;

    public string $buttonLabel = '';

    public string $buttonUrl = '';

    public bool $buttonNewWindow = false;


    protected function defineRules(): array
    {
        return [
            [['message'], 'string'],
            [['buttonEnabled', 'buttonNewWindow'], 'boolean'],
            [['buttonLabel', 'buttonUrl'], 'string', 'max' => 500],
            [['buttonLabel', 'buttonUrl'], 'validateButton', 'skipOnEmpty' => false],
            [['message'], 'validateSomethingToSay', 'skipOnEmpty' => false],
        ];
    }

    public function validateButton(): void
    {
        if (!$this->buttonEnabled) {
            return;
        }

        if (trim($this->buttonLabel) === '') {
            $this->addError('buttonLabel', Craft::t('blaster', 'Give the button something to say.'));
        }

        if (trim($this->buttonUrl) === '') {
            $this->addError('buttonUrl', Craft::t('blaster', 'Give the button somewhere to go.'));
        }
    }

    /**
     * A bar with neither a message nor a button is an empty coloured stripe. Catching it here
     * saves an author the round trip of publishing one and wondering why nothing shows.
     */
    public function validateSomethingToSay(): void
    {
        $hasMessage = trim(strip_tags($this->message)) !== '' || str_contains($this->message, '<img');

        if (!$hasMessage && !$this->buttonEnabled) {
            $this->addError('message', Craft::t('blaster', 'Write a message, or turn on the button.'));
        }
    }

    public function isEmpty(): bool
    {
        return trim(strip_tags($this->message)) === '' && !$this->buttonEnabled;
    }

    public function toArray(array $fields = [], array $expand = [], $recursive = true): array
    {
        return [
            'siteId' => $this->siteId,
            'message' => $this->message,
            'buttonEnabled' => $this->buttonEnabled,
            'buttonLabel' => $this->buttonLabel,
            'buttonUrl' => $this->buttonUrl,
            'buttonNewWindow' => $this->buttonNewWindow,
        ];
    }
}
