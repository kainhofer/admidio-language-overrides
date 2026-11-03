<?php
namespace AdmidioPlugin\LanguageOverrides\Service;

/**
 * A refusal of the services, which know nothing about the translation system: the module turns it
 * into an Admidio exception with the same text id.
 */
final class OverrideException extends \RuntimeException
{
    /**
     * @param array<int,string> $params
     */
    public function __construct(private readonly string $textId, private readonly array $params = array())
    {
        parent::__construct($textId);
    }

    public function getTextId(): string
    {
        return $this->textId;
    }

    /**
     * @return array<int,string>
     */
    public function getParams(): array
    {
        return $this->params;
    }
}
