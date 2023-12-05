<?php
/**
 * This file is part of PHPWord - A pure PHP library for reading and writing
 * word processing documents.
 *
 * PHPWord is free software distributed under the terms of the GNU Lesser
 * General Public License version 3 as published by the Free Software Foundation.
 *
 * For the full copyright and license information, please read the LICENSE
 * file that was distributed with this source code. For the full list of
 * contributors, visit https://github.com/PHPOffice/PHPWord/contributors.
 *
 * @see         https://github.com/PHPOffice/PHPWord
 *
 * @license     http://www.gnu.org/licenses/lgpl.txt LGPL version 3
 */

namespace PhpOffice\PhpWordTests;

use PhpOffice\PhpWord\Shared\ZipArchive;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * This class is used to expose publicly methods that are otherwise private or protected.
 * This makes testing those methods easier.
 *
 * @author troosan
 */
class TestableTemplateProcessor extends TemplateProcessor
{
    public function __construct($mainPart = null, $settingsPart = null)
    {
        $this->tempDocumentMainPart = $mainPart;
        $this->tempDocumentSettingsPart = $settingsPart;
        $this->zipClass = new ZipArchive();
    }

    public function fixBrokenMacros($documentPart): string
    {
        return parent::fixBrokenMacros($documentPart);
    }

    public function splitTextIntoTexts($text): string
    {
        return parent::splitTextIntoTexts($text);
    }

    public function textNeedsSplitting(string $text): bool
    {
        return parent::textNeedsSplitting($text);
    }

    public function getVariablesForPart($documentPartXML): array
    {
        $documentPartXML = parent::fixBrokenMacros($documentPartXML);

        return parent::getVariablesForPart($documentPartXML);
    }

    public function findXmlBlockStart($offset, $blockType): int
    {
        return parent::findXmlBlockStart($offset, $blockType);
    }

    public function findContainingXmlBlockForMacro($macro, $blockType = 'w:p'): bool|array
    {
        return parent::findContainingXmlBlockForMacro($macro, $blockType);
    }

    public function getSlice($startPosition, $endPosition = 0): string
    {
        return parent::getSlice($startPosition, $endPosition);
    }

    /**
     * @return ?string
     */
    public function getMainPart(): ?string
    {
        return $this->tempDocumentMainPart;
    }

    /**
     * @return ?string
     */
    public function getSettingsPart(): ?string
    {
        return $this->tempDocumentSettingsPart;
    }
}
