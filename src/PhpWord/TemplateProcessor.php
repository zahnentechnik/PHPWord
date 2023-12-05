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

namespace PhpOffice\PhpWord;

use DOMDocument;
use PhpOffice\PhpWord\Escaper\RegExp;
use PhpOffice\PhpWord\Escaper\Xml;
use PhpOffice\PhpWord\Exception\CopyFileException;
use PhpOffice\PhpWord\Exception\CreateTemporaryFileException;
use PhpOffice\PhpWord\Exception\Exception;
use PhpOffice\PhpWord\Shared\Text;
use PhpOffice\PhpWord\Shared\XMLWriter;
use PhpOffice\PhpWord\Shared\ZipArchive;
use PhpOffice\PhpWord\Writer\Word2007\Element\AbstractElement;
use PhpOffice\PhpWord\Writer\Word2007\Part\Chart;
use Throwable;
use XSLTProcessor;

class TemplateProcessor
{
    const MAXIMUM_REPLACEMENTS_DEFAULT = -1;

    /**
     * ZipArchive object.
     *
     * @var mixed
     */
    protected mixed $zipClass;

    /**
     * @var ?string Temporary document filename (with path)
     */
    protected ?string $tempDocumentFilename;

    /**
     * Content of main document part (in XML format) of the temporary document.
     *
     * @var ?string
     */
    protected ?string $tempDocumentMainPart;

    /**
     * Content of settings part (in XML format) of the temporary document.
     *
     * @var ?string
     */
    protected ?string $tempDocumentSettingsPart;

    /**
     * Content of headers (in XML format) of the temporary document.
     *
     * @var string[]
     */
    protected array $tempDocumentHeaders = [];

    /**
     * Content of footers (in XML format) of the temporary document.
     *
     * @var string[]
     */
    protected array $tempDocumentFooters = [];

    /**
     * Document relations (in XML format) of the temporary document.
     *
     * @var string[]
     */
    protected array $tempDocumentRelations = [];

    /**
     * Document content types (in XML format) of the temporary document.
     *
     * @var string
     */
    protected string $tempDocumentContentTypes = '';

    /**
     * new inserted images list.
     *
     * @var string[]
     */
    protected array $tempDocumentNewImages = [];

    /**
     * Opening characters for a macro in a string.
     *
     * @var string
     */
    protected static string $macroOpeningChars = '${';

    /**
     * Closing characters for macros.
     *
     * @var string
     */
    protected static string $macroClosingChars = '}';

    /**
     * Constructs a new instance of the class.
     *
     * @param string $documentTemplate The path to the document template.
     * @throws CreateTemporaryFileException
     * @throws CopyFileException
     */
    public function __construct(string $documentTemplate)
    {
        // Temporary document filename initialization
        $this->tempDocumentFilename = tempnam(Settings::getTempDir(), 'PhpWord');
        if (false === $this->tempDocumentFilename) {
            throw new CreateTemporaryFileException(); // @codeCoverageIgnore
        }

        // Template file cloning
        if (false === copy($documentTemplate, $this->tempDocumentFilename)) {
            throw new CopyFileException($documentTemplate, $this->tempDocumentFilename); // @codeCoverageIgnore
        }

        // Temporary document content extraction
        $this->zipClass = new ZipArchive();
        $this->zipClass->open($this->tempDocumentFilename);
        $index = 1;
        while (false !== $this->zipClass->locateName($this->getHeaderName($index))) {
            $this->tempDocumentHeaders[$index] = $this->readPartWithRels($this->getHeaderName($index));
            ++$index;
        }
        $index = 1;
        while (false !== $this->zipClass->locateName($this->getFooterName($index))) {
            $this->tempDocumentFooters[$index] = $this->readPartWithRels($this->getFooterName($index));
            ++$index;
        }

        $this->tempDocumentMainPart = $this->readPartWithRels($this->getMainPartName());
        $this->tempDocumentSettingsPart = $this->readPartWithRels($this->getSettingsPartName());
        $this->tempDocumentContentTypes = $this->zipClass->getFromName($this->getDocumentContentTypesName());
    }

    /**
     * Destructor method for cleaning up resources.
     */
    public function __destruct()
    {
        // ZipClass
        if ($this->zipClass) {
            try {
                $this->zipClass->close();
            } catch (Throwable $e) {
                // Nothing to do here.
            }
        }
        // Temporary file
        if ($this->tempDocumentFilename && file_exists($this->tempDocumentFilename)) {
            unlink($this->tempDocumentFilename);
        }
    }

    /**
     * Restores the state of the object after it has been unserialized.
     *
     * @throws Exception
     */
    public function __wakeup(): void
    {
        $this->tempDocumentFilename = '';
        $this->zipClass = null;

        throw new Exception('unserialize not permitted for this class');
    }

    /**
     * Expose zip class.
     *
     * To replace an image: $templateProcessor->zip()->AddFromString("word/media/image1.jpg", file_get_contents($file));<br>
     * To read a file: $templateProcessor->zip()->getFromName("word/media/image1.jpg");
     *
     * @return ZipArchive
     */
    public function zip(): ZipArchive
    {
        return $this->zipClass;
    }

    /**
     * @param string $fileName
     *
     * @return string
     */
    protected function readPartWithRels(string $fileName): string
    {
        $relsFileName = $this->getRelationsName($fileName);
        $partRelations = $this->zipClass->getFromName($relsFileName);
        if ($partRelations !== false) {
            $this->tempDocumentRelations[$fileName] = $partRelations;
        }

        return $this->fixBrokenMacros($this->zipClass->getFromName($fileName));
    }

    /**
     * @param string $xml
     * @param XSLTProcessor $xsltProcessor
     *
     * @return string
     * @throws Exception
     */
    protected function transformSingleXml(string $xml, XSLTProcessor $xsltProcessor): string
    {
        $domDocument = new DOMDocument();
        if (false === $domDocument->loadXML($xml)) {
            throw new Exception('Could not load the given XML document.');
        }

        $transformedXml = $xsltProcessor->transformToXml($domDocument);
        if (false === $transformedXml) {
            throw new Exception('Could not transform the given XML document.');
        }
        return $transformedXml;
    }

    /**
     * @param mixed $xml
     * @param XSLTProcessor $xsltProcessor
     *
     * @return mixed
     * @throws Exception
     */
    protected function transformXml(mixed $xml, XSLTProcessor $xsltProcessor): mixed
    {
        if (is_array($xml)) {
            foreach ($xml as &$item) {
                $item = $this->transformSingleXml($item, $xsltProcessor);
            }
            unset($item);
        } else {
            $xml = $this->transformSingleXml($xml, $xsltProcessor);
        }

        return $xml;
    }

    /**
     * Applies XSL style sheet to template's parts.
     *
     * Note: since the method doesn't make any guess on logic of the provided XSL style sheet,
     * make sure that output is correctly escaped. Otherwise, you may get broken document.
     *
     * @param DOMDocument $xslDomDocument
     * @param array $xslOptions
     * @param string $xslOptionsUri
     * @throws Exception
     */
    public function applyXslStyleSheet(DOMDocument $xslDomDocument, array $xslOptions = [], string $xslOptionsUri = ''): void
    {
        $xsltProcessor = new XSLTProcessor();

        $xsltProcessor->importStylesheet($xslDomDocument);
        if (false === $xsltProcessor->setParameter($xslOptionsUri, $xslOptions)) {
            throw new Exception('Could not set values for the given XSL style sheet parameters.');
        }

        $this->tempDocumentHeaders = $this->transformXml($this->tempDocumentHeaders, $xsltProcessor);
        $this->tempDocumentMainPart = $this->transformXml($this->tempDocumentMainPart, $xsltProcessor);
        $this->tempDocumentFooters = $this->transformXml($this->tempDocumentFooters, $xsltProcessor);
    }

    /**
     * @param string $macro
     *
     * @return string
     */
    protected static function ensureMacroCompleted(string $macro): string
    {
        if (substr($macro, 0, 2) !== self::$macroOpeningChars && substr($macro, -1) !== self::$macroClosingChars) {
            $macro = self::$macroOpeningChars . $macro . self::$macroClosingChars;
        }

        return $macro;
    }

    /**
     * @param ?string $subject
     *
     * @return string
     */
    protected static function ensureUtf8Encoded(?string $subject): string
    {
        return $subject ? Text::toUTF8($subject) : '';
    }

    /**
     * @param string $search
     * @param Element\AbstractElement $complexType
     */
    public function setComplexValue(string $search, Element\AbstractElement $complexType): void
    {
        $elementName = substr(get_class($complexType), strrpos(get_class($complexType), '\\') + 1);
        $objectClass = 'PhpOffice\\PhpWord\\Writer\\Word2007\\Element\\' . $elementName;

        $xmlWriter = new XMLWriter();
        /** @var AbstractElement $elementWriter */
        $elementWriter = new $objectClass($xmlWriter, $complexType, true);
        $elementWriter->write();

        $where = $this->findContainingXmlBlockForMacro($search, 'w:r');

        if ($where === false) {
            return;
        }

        $block = $this->getSlice($where['start'], $where['end']);
        $textParts = $this->splitTextIntoTexts($block);
        $this->replaceXmlBlock($search, $textParts, 'w:r');

        $search = static::ensureMacroCompleted($search);
        $this->replaceXmlBlock($search, $xmlWriter->getData(), 'w:r');
    }

    /**
     * @param string $search
     * @param Element\AbstractElement $complexType
     */
    public function setComplexBlock(string $search, Element\AbstractElement $complexType): void
    {
        $elementName = substr(get_class($complexType), strrpos(get_class($complexType), '\\') + 1);
        $objectClass = 'PhpOffice\\PhpWord\\Writer\\Word2007\\Element\\' . $elementName;

        $xmlWriter = new XMLWriter();
        /** @var AbstractElement $elementWriter */
        $elementWriter = new $objectClass($xmlWriter, $complexType, false);
        $elementWriter->write();

        $this->replaceXmlBlock($search, $xmlWriter->getData(), 'w:p');
    }

    /**
     * @param mixed $search
     * @param mixed $replace
     * @param int $limit
     */
    public function setValue(mixed $search, mixed $replace, int $limit = self::MAXIMUM_REPLACEMENTS_DEFAULT): void
    {
        if (is_array($search)) {
            foreach ($search as &$item) {
                $item = static::ensureMacroCompleted($item);
            }
            unset($item);
        } else {
            $search = static::ensureMacroCompleted($search);
        }

        if (is_array($replace)) {
            foreach ($replace as &$item) {
                $item = static::ensureUtf8Encoded($item);
            }
            unset($item);
        } else {
            $replace = static::ensureUtf8Encoded($replace);
        }

        if (Settings::isOutputEscapingEnabled()) {
            $xmlEscaper = new Xml();
            $replace = $xmlEscaper->escape($replace);
        }

        $this->tempDocumentHeaders = $this->setValueForPart($search, $replace, $this->tempDocumentHeaders, $limit);
        $this->tempDocumentMainPart = $this->setValueForPart($search, $replace, $this->tempDocumentMainPart, $limit);
        $this->tempDocumentFooters = $this->setValueForPart($search, $replace, $this->tempDocumentFooters, $limit);
    }

    /**
     * Set values from a one-dimensional array of "variable => value"-pairs.
     */
    public function setValues(array $values): void
    {
        foreach ($values as $macro => $replace) {
            $this->setValue($macro, $replace);
        }
    }

    /**
     * Set the state of a checkbox in a template document.
     *
     * @param string $search The string to search for in the document.
     * @param bool $checked The new state of the checkbox.
     */
    public function setCheckbox(string $search, bool $checked): void
    {
        $search = static::ensureMacroCompleted($search);
        $blockType = 'w:sdt';

        $where = $this->findContainingXmlBlockForMacro($search, $blockType);
        if (!is_array($where)) {
            return;
        }

        $block = $this->getSlice($where['start'], $where['end']);

        $val = $checked ? '1' : '0';
        $block = preg_replace('/(<w14:checked w14:val=)".*?"(\/>)/', '$1"' . $val . '"$2', $block);

        $text = $checked ? '☒' : '☐';
        $block = preg_replace('/(<w:t>).*?(<\/w:t>)/', '$1' . $text . '$2', $block);

        $this->replaceXmlBlock($search, $block, $blockType);
    }

    /**
     * @param string $search
     */
    public function setChart(string $search, Element\Chart $chart): void
    {
        $elementName = substr(get_class($chart), strrpos(get_class($chart), '\\') + 1);
        $objectClass = 'PhpOffice\\PhpWord\\Writer\\Word2007\\Element\\' . $elementName;

        // Get the next relation id
        $rId = $this->getNextRelationsIndex($this->getMainPartName());
        $chart->setRelationId($rId);

        // Define the chart filename
        $filename = "charts/chart{$rId}.xml";

        // Get the part writer
        $writerPart = new Chart();
        $writerPart->setElement($chart);

        // ContentTypes.xml
        $this->zipClass->addFromString("word/{$filename}", $writerPart->write());

        // add chart to content type
        $xmlRelationsType = "<Override PartName=\"/word/{$filename}\" ContentType=\"application/vnd.openxmlformats-officedocument.drawingml.chart+xml\"/>";
        $this->tempDocumentContentTypes = str_replace('</Types>', $xmlRelationsType, $this->tempDocumentContentTypes) . '</Types>';

        // Add the chart to relations
        $xmlChartRelation = "<Relationship Id=\"rId{$rId}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart\" Target=\"charts/chart{$rId}.xml\"/>";
        $this->tempDocumentRelations[$this->getMainPartName()] = str_replace('</Relationships>', $xmlChartRelation, $this->tempDocumentRelations[$this->getMainPartName()]) . '</Relationships>';

        // Write the chart
        $xmlWriter = new XMLWriter();
        $elementWriter = new $objectClass($xmlWriter, $chart, true);
        $elementWriter->write();

        // Place it in the template
        $this->replaceXmlBlock($search, '<w:p>' . $xmlWriter->getData() . '</w:p>', 'w:p');
    }

    /**
     * This method extracts image arguments from a given variable name with arguments.
     * It parses the variable name and extracts the width, height, and other possible arguments.
     *
     * @param string $varNameWithArgs The variable name with arguments. It should follow the format "varName:arg1:arg2:arg3".
     * @return array An array containing the extracted image arguments. It can include the following keys:
     *   - width: The width of the image (can be in pixels or percentage).
     *   - height: The height of the image (can be in pixels or percentage).
     *   - ratio: The ratio of the image (optional).
     *   - Any other custom argument specified in the variable name.
     *
     * @see https://msdn.microsoft.com/en-us/library/documentformat.openxml.vml.shape%28v=office.14%29.aspx?f=255&MSPPError=-2147217396
     */
    private function getImageArgs(string $varNameWithArgs): array
    {
        $varElements = explode(':', $varNameWithArgs);
        array_shift($varElements); // first element is name of variable => remove it

        $varInlineArgs = [];
        // size format documentation: https://msdn.microsoft.com/en-us/library/documentformat.openxml.vml.shape%28v=office.14%29.aspx?f=255&MSPPError=-2147217396
        foreach ($varElements as $argIdx => $varArg) {
            if (strpos($varArg, '=')) { // arg=value
                [$argName, $argValue] = explode('=', $varArg, 2);
                $argName = strtolower($argName);
                if ($argName == 'size') {
                    [$varInlineArgs['width'], $varInlineArgs['height']] = explode('x', $argValue, 2);
                } else {
                    $varInlineArgs[strtolower($argName)] = $argValue;
                }
            } elseif (preg_match('/^([0-9]*[a-z%]{0,2}|auto)x([0-9]*[a-z%]{0,2}|auto)$/i', $varArg)) { // 60x40
                [$varInlineArgs['width'], $varInlineArgs['height']] = explode('x', $varArg, 2);
            } else { // :60:40:f
                switch ($argIdx) {
                    case 0:
                        $varInlineArgs['width'] = $varArg;

                        break;
                    case 1:
                        $varInlineArgs['height'] = $varArg;

                        break;
                    case 2:
                        $varInlineArgs['ratio'] = $varArg;

                        break;
                }
            }
        }

        return $varInlineArgs;
    }

    /**
     * This method chooses the appropriate image dimension based on the given base value, inline value, and default value.
     * It checks if the base value is null and if the inline value is set. If so, it assigns the inline value to the base value.
     * Then, it validates the value against a regular expression to check if it is a valid dimension format.
     * If the value is not valid, it assigns null to the value.
     * If the value is still null, it assigns the default value.
     * Finally, if the value is numeric, it appends 'px' to the value.
     *
     * @param mixed $baseValue The base value for the image dimension.
     * @param mixed $inlineValue The inline value for the image dimension.
     * @param mixed $defaultValue The default value for the image dimension.
     *
     * @return mixed The chosen image dimension as a string. It can be in the format 'number+unit' (e.g. '10px') or null if the value is not valid.
     *
     * @see Regular expression pattern: /^([0-9\.]*(cm|mm|in|pt|pc|px|%|em|ex|)|auto)$/i
     */
    private function chooseImageDimension(mixed $baseValue, mixed $inlineValue, mixed $defaultValue): mixed
    {
        $value = $baseValue;
        if (null === $value && isset($inlineValue)) {
            $value = $inlineValue;
        }
        if (!preg_match('/^([0-9\.]*(cm|mm|in|pt|pc|px|%|em|ex|)|auto)$/i', $value ?? '')) {
            $value = null;
        }
        if (null === $value) {
            $value = $defaultValue;
        }
        if (is_numeric($value)) {
            $value .= 'px';
        }

        return $value;
    }

    /**
     * This method fixes the width and height ratio of an image based on the actual width and height values.
     * If the width or height is empty, it calculates the missing value based on the image ratio.
     * If both width and height are provided, it checks if the aspect ratio matches and adjusts one of the dimensions if necessary.
     *
     * @param string $width The width of the image. Can be in pixels or percentage. If empty, it will be calculated based on the height and image ratio.
     * @param string $height The height of the image. Can be in pixels or percentage. If empty, it will be calculated based on the width and image ratio.
     * @param float $actualWidth The actual width of the image.
     * @param float $actualHeight The actual height of the image.
     * @return void
     */
    private function fixImageWidthHeightRatio(string &$width, string &$height, float $actualWidth, float $actualHeight): void
    {
        $imageRatio = $actualWidth / $actualHeight;

        if (($width === '') && ($height === '')) { // defined size are empty
            $width = $actualWidth . 'px';
            $height = $actualHeight . 'px';
        } elseif ($width === '') { // defined width is empty
            $heightFloat = (float)$height;
            $widthFloat = $heightFloat * $imageRatio;
            $matches = [];
            preg_match('/\\d([a-z%]+)$/', $height, $matches);
            $width = $widthFloat . $matches[1];
        } elseif ($height === '') { // defined height is empty
            $widthFloat = (float)$width;
            $heightFloat = $widthFloat / $imageRatio;
            $matches = [];
            preg_match('/\\d([a-z%]+)$/', $width, $matches);
            $height = $heightFloat . $matches[1];
        } else { // we have defined size, but we need also check it aspect ratio
            $widthMatches = [];
            preg_match('/\\d([a-z%]+)$/', $width, $widthMatches);
            $heightMatches = [];
            preg_match('/\\d([a-z%]+)$/', $height, $heightMatches);
            // try to fix only if dimensions are same
            if ($widthMatches[1] == $heightMatches[1]) {
                $dimension = $widthMatches[1];
                $widthFloat = (float)$width;
                $heightFloat = (float)$height;
                $definedRatio = $widthFloat / $heightFloat;

                if ($imageRatio > $definedRatio) { // image wider than defined box
                    $height = ($widthFloat / $imageRatio) . $dimension;
                } elseif ($imageRatio < $definedRatio) { // image higher than defined box
                    $width = ($heightFloat * $imageRatio) . $dimension;
                }
            }
        }
    }

    /**
     * This method prepares the image attributes for replacement in a string.
     * It takes the replacement image and its inline arguments and calculates the final width and height based on these arguments.
     *
     * @param mixed $replaceImage The replacement image, which can be a string representing the image path or an array containing the image path and optional width, height, and ratio values.
     * @param array $varInlineArgs The inline arguments extracted from the variable name.
     * @return array An array containing the prepared image attributes. It includes the following keys:
     *   - src: The image path.
     *   - mime: The MIME type of the image.
     *   - width: The calculated width of the image.
     *   - height: The calculated height of the image.
     *
     * @throws Exception When an invalid image path is provided.
     */
    private function prepareImageAttrs(mixed $replaceImage, array $varInlineArgs): array
    {
        // get image path and size
        $width = null;
        $height = null;
        $ratio = null;

        // a closure can be passed as replacement value which after resolving, can contain the replacement info for the image
        // use case: only when a image if found, the replacement tags can be generated
        if (is_callable($replaceImage)) {
            $replaceImage = $replaceImage();
        }

        if (is_array($replaceImage) && isset($replaceImage['path'])) {
            $imgPath = $replaceImage['path'];
            if (isset($replaceImage['width'])) {
                $width = $replaceImage['width'];
            }
            if (isset($replaceImage['height'])) {
                $height = $replaceImage['height'];
            }
            if (isset($replaceImage['ratio'])) {
                $ratio = $replaceImage['ratio'];
            }
        } else {
            $imgPath = $replaceImage;
        }

        $width = $this->chooseImageDimension($width, $varInlineArgs['width'] ?? null, 115);
        $height = $this->chooseImageDimension($height, $varInlineArgs['height'] ?? null, 70);

        $imageData = @getimagesize($imgPath);
        if (!is_array($imageData)) {
            throw new Exception(sprintf('Invalid image: %s', $imgPath));
        }
        [$actualWidth, $actualHeight, $imageType] = $imageData;

        // fix aspect ratio (by default)
        if (null === $ratio && isset($varInlineArgs['ratio'])) {
            $ratio = $varInlineArgs['ratio'];
        }
        if (null === $ratio || !in_array(strtolower($ratio), ['', '-', 'f', 'false'])) {
            $this->fixImageWidthHeightRatio($width, $height, $actualWidth, $actualHeight);
        }

        $imageAttrs = [
            'src' => $imgPath,
            'mime' => image_type_to_mime_type($imageType),
            'width' => $width,
            'height' => $height,
        ];

        return $imageAttrs;
    }

    /**
     * Adds an image to the relations of the document.
     *
     * @param string $partFileName The name of the part file where the image will be added.
     * @param string $rid The unique ID of the image's relationship.
     * @param string $imgPath The path to the image file.
     * @param string $imageMimeType The MIME type of the image.
     * @throws Exception If the image type is not supported.
     */
    private function addImageToRelations($partFileName, $rid, $imgPath, $imageMimeType): void
    {
        // define templates
        $typeTpl = '<Override PartName="/word/media/{IMG}" ContentType="image/{EXT}"/>';
        $relationTpl = '<Relationship Id="{RID}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/{IMG}"/>';
        $newRelationsTpl = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n" . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';
        $newRelationsTypeTpl = '<Override PartName="/{RELS}" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>';
        $extTransform = [
            'image/jpeg' => 'jpeg',
            'image/png' => 'png',
            'image/bmp' => 'bmp',
            'image/gif' => 'gif',
        ];

        // get image embed name
        if (isset($this->tempDocumentNewImages[$imgPath])) {
            $imgName = $this->tempDocumentNewImages[$imgPath];
        } else {
            // transform extension
            if (isset($extTransform[$imageMimeType])) {
                $imgExt = $extTransform[$imageMimeType];
            } else {
                throw new Exception("Unsupported image type $imageMimeType");
            }

            // add image to document
            $imgName = 'image_' . $rid . '_' . pathinfo($partFileName, PATHINFO_FILENAME) . '.' . $imgExt;
            $this->zipClass->pclzipAddFile($imgPath, 'word/media/' . $imgName);
            $this->tempDocumentNewImages[$imgPath] = $imgName;

            // setup type for image
            $xmlImageType = str_replace(['{IMG}', '{EXT}'], [$imgName, $imgExt], $typeTpl);
            $this->tempDocumentContentTypes = str_replace('</Types>', $xmlImageType, $this->tempDocumentContentTypes) . '</Types>';
        }

        $xmlImageRelation = str_replace(['{RID}', '{IMG}'], [$rid, $imgName], $relationTpl);

        if (!isset($this->tempDocumentRelations[$partFileName])) {
            // create new relations file
            $this->tempDocumentRelations[$partFileName] = $newRelationsTpl;
            // and add it to content types
            $xmlRelationsType = str_replace('{RELS}', $this->getRelationsName($partFileName), $newRelationsTypeTpl);
            $this->tempDocumentContentTypes = str_replace('</Types>', $xmlRelationsType, $this->tempDocumentContentTypes) . '</Types>';
        }

        // add image to relations
        $this->tempDocumentRelations[$partFileName] = str_replace('</Relationships>', $xmlImageRelation, $this->tempDocumentRelations[$partFileName]) . '</Relationships>';
    }

    /**
     * @param mixed $search
     * @param mixed $replace Path to image, or array("path" => xx, "width" => yy, "height" => zz)
     * @param int $limit
     * @throws Exception
     */
    public function setImageValue(mixed $search, mixed $replace, int $limit = self::MAXIMUM_REPLACEMENTS_DEFAULT): void
    {
        // prepare $search_replace
        if (!is_array($search)) {
            $search = [$search];
        }

        $replacesList = [];
        if (!is_array($replace) || isset($replace['path'])) {
            $replacesList[] = $replace;
        } else {
            $replacesList = array_values($replace);
        }

        $searchReplace = [];
        foreach ($search as $searchIdx => $searchString) {
            $searchReplace[$searchString] = $replacesList[$searchIdx] ?? $replacesList[0];
        }

        // collect document parts
        $searchParts = [
            $this->getMainPartName() => &$this->tempDocumentMainPart,
        ];
        foreach (array_keys($this->tempDocumentHeaders) as $headerIndex) {
            $searchParts[$this->getHeaderName($headerIndex)] = &$this->tempDocumentHeaders[$headerIndex];
        }
        foreach (array_keys($this->tempDocumentFooters) as $headerIndex) {
            $searchParts[$this->getFooterName($headerIndex)] = &$this->tempDocumentFooters[$headerIndex];
        }

        // define templates
        // result can be verified via "Open XML SDK 2.5 Productivity Tool" (http://www.microsoft.com/en-us/download/details.aspx?id=30425)
        $imgTpl = '<w:pict><v:shape type="#_x0000_t75" style="width:{WIDTH};height:{HEIGHT}" stroked="f"><v:imagedata r:id="{RID}" o:title=""/></v:shape></w:pict>';

        $i = 0;
        foreach ($searchParts as $partFileName => &$partContent) {
            $partVariables = $this->getVariablesForPart($partContent);

            foreach ($searchReplace as $searchString => $replaceImage) {
                $varsToReplace = array_filter($partVariables, function ($partVar) use ($searchString) {
                    return ($partVar == $searchString) || preg_match('/^' . preg_quote($searchString) . ':/', $partVar);
                });

                foreach ($varsToReplace as $varNameWithArgs) {
                    $varInlineArgs = $this->getImageArgs($varNameWithArgs);
                    $preparedImageAttrs = $this->prepareImageAttrs($replaceImage, $varInlineArgs);
                    $imgPath = $preparedImageAttrs['src'];

                    // get image index
                    $imgIndex = $this->getNextRelationsIndex($partFileName);
                    $rid = 'rId' . $imgIndex;

                    // replace preparations
                    $this->addImageToRelations($partFileName, $rid, $imgPath, $preparedImageAttrs['mime']);
                    $xmlImage = str_replace(['{RID}', '{WIDTH}', '{HEIGHT}'], [$rid, $preparedImageAttrs['width'], $preparedImageAttrs['height']], $imgTpl);

                    // replace variable
                    $varNameWithArgsFixed = static::ensureMacroCompleted($varNameWithArgs);
                    $matches = [];
                    if (preg_match('/(<[^<]+>)([^<]*)(' . preg_quote($varNameWithArgsFixed) . ')([^>]*)(<[^>]+>)/Uu', $partContent, $matches)) {
                        $wholeTag = $matches[0];
                        array_shift($matches);
                        [$openTag, $prefix, , $postfix, $closeTag] = $matches;
                        $replaceXml = $openTag . $prefix . $closeTag . $xmlImage . $openTag . $postfix . $closeTag;
                        // replace on each iteration, because in one tag we can have 2+ inline variables => before proceed next variable we need to change $partContent
                        $partContent = $this->setValueForPart($wholeTag, $replaceXml, $partContent, $limit);
                    }

                    if (++$i >= $limit) {
                        break;
                    }
                }
            }
        }
    }

    /**
     * Returns count of all variables in template.
     *
     * @return array
     */
    public function getVariableCount(): array
    {
        $variables = $this->getVariablesForPart($this->tempDocumentMainPart);

        foreach ($this->tempDocumentHeaders as $headerXML) {
            $variables = array_merge(
                $variables,
                $this->getVariablesForPart($headerXML)
            );
        }

        foreach ($this->tempDocumentFooters as $footerXML) {
            $variables = array_merge(
                $variables,
                $this->getVariablesForPart($footerXML)
            );
        }

        return array_count_values($variables);
    }

    /**
     * Returns array of all variables in template.
     *
     * @return string[]
     */
    public function getVariables(): array
    {
        return array_keys($this->getVariableCount());
    }

    /**
     * Clone a table row in a template document.
     *
     * @param string $search
     * @param int $numberOfClones
     * @throws Exception
     */
    public function cloneRow(string $search, int $numberOfClones): void
    {
        $search = static::ensureMacroCompleted($search);

        $tagPos = strpos($this->tempDocumentMainPart, $search);
        if (!$tagPos) {
            throw new Exception('Can not clone row, template variable not found or variable contains markup.');
        }

        $rowStart = $this->findRowStart($tagPos);
        $rowEnd = $this->findRowEnd($tagPos);
        $xmlRow = $this->getSlice($rowStart, $rowEnd);

        // Check if there's a cell spanning multiple rows.
        if (preg_match('#<w:vMerge w:val="restart"/>#', $xmlRow)) {
            // $extraRowStart = $rowEnd;
            $extraRowEnd = $rowEnd;
            while (true) {
                $extraRowStart = $this->findRowStart($extraRowEnd + 1);
                $extraRowEnd = $this->findRowEnd($extraRowEnd + 1);

                // If extraRowEnd is lower than 7, there was no next row found.
                if ($extraRowEnd < 7) {
                    break;
                }

                // If tmpXmlRow doesn't contain continue, this row is no longer part of the spanned row.
                $tmpXmlRow = $this->getSlice($extraRowStart, $extraRowEnd);
                if (!str_contains($tmpXmlRow, '<w:vMerge/>') &&
                    !preg_match('#<w:vMerge w:val="continue"\s*/>#', $tmpXmlRow)
                ) {
                    break;
                }
                // This row was a spanned row, update $rowEnd and search for the next row.
                $rowEnd = $extraRowEnd;
            }
            $xmlRow = $this->getSlice($rowStart, $rowEnd);
        }

        $result = $this->getSlice(0, $rowStart);
        $result .= implode('', $this->indexClonedVariables($numberOfClones, $xmlRow));
        $result .= $this->getSlice($rowEnd);

        $this->tempDocumentMainPart = $result;
    }

    /**
     * Delete a table row in a template document.
     * @throws Exception
     */
    public function deleteRow(string $search): void
    {
        if (!str_starts_with($search, '${') && !str_ends_with($search, '}')) {
            $search = '${' . $search . '}';
        }

        $tagPos = strpos($this->tempDocumentMainPart, $search);
        if (!$tagPos) {
            throw new Exception(sprintf('Can not delete row %s, template variable not found or variable contains markup.', $search));
        }

        $tableStart = $this->findTableStart($tagPos);
        $tableEnd = $this->findTableEnd($tagPos);
        $xmlTable = $this->getSlice($tableStart, $tableEnd);

        if (substr_count($xmlTable, '<w:tr') === 1) {
            $this->tempDocumentMainPart = $this->getSlice(0, $tableStart) . $this->getSlice($tableEnd);

            return;
        }

        $rowStart = $this->findRowStart($tagPos);
        $rowEnd = $this->findRowEnd($tagPos);
        $xmlRow = $this->getSlice($rowStart, $rowEnd);

        $this->tempDocumentMainPart = $this->getSlice(0, $rowStart) . $this->getSlice($rowEnd);

        // Check if there's a cell spanning multiple rows.
        if (str_contains($xmlRow, '<w:vMerge w:val="restart"/>')) {
            $extraRowStart = $rowStart;
            while (true) {
                $extraRowStart = $this->findRowStart($extraRowStart + 1);
                $extraRowEnd = $this->findRowEnd($extraRowStart + 1);

                // If extraRowEnd is lower then 7, there was no next row found.
                if ($extraRowEnd < 7) {
                    break;
                }

                // If tmpXmlRow doesn't contain continue, this row is no longer part of the spanned row.
                $tmpXmlRow = $this->getSlice($extraRowStart, $extraRowEnd);
                if (!str_contains($tmpXmlRow, '<w:vMerge/>') &&
                    !str_contains($tmpXmlRow, '<w:vMerge w:val="continue" />')
                ) {
                    break;
                }

                $tableStart = $this->findTableStart($extraRowEnd + 1);
                $tableEnd = $this->findTableEnd($extraRowEnd + 1);
                $xmlTable = $this->getSlice($tableStart, $tableEnd);
                if (substr_count($xmlTable, '<w:tr') === 1) {
                    $this->tempDocumentMainPart = $this->getSlice(0, $tableStart) . $this->getSlice($tableEnd);

                    return;
                }

                $this->tempDocumentMainPart = $this->getSlice(0, $extraRowStart) . $this->getSlice($extraRowEnd);
            }
        }
    }

    /**
     * Clones a table row and populates it's values from a two-dimensional array in a template document.
     *
     * @param string $search
     * @param array $values
     * @throws Exception
     */
    public function cloneRowAndSetValues(string $search, array $values): void
    {
        $this->cloneRow($search, count($values));

        foreach ($values as $rowKey => $rowData) {
            $rowNumber = $rowKey + 1;
            foreach ($rowData as $macro => $replace) {
                $this->setValue($macro . '#' . $rowNumber, $replace);
            }
        }
    }

    /**
     * Clone a block.
     *
     * @param string $blockname
     * @param int $clones How many times the block should be cloned
     * @param bool $replace
     * @param bool $indexVariables If true, any variables inside the block will be indexed (postfixed with #1, #2, ...)
     * @param array|null $variableReplacements Array containing replacements for macros found inside the block to clone
     *
     * @return null|string
     */
    public function cloneBlock(string $blockname, int $clones = 1, bool $replace = true, bool $indexVariables = false, array $variableReplacements = null): ?string
    {
        $xmlBlock = null;
        $matches = [];
        $escapedMacroOpeningChars = self::$macroOpeningChars;
        $escapedMacroClosingChars = self::$macroClosingChars;
        preg_match(
        //'/(.*((?s)<w:p\b(?:(?!<w:p\b).)*?\{{' . $blockname . '}<\/w:.*?p>))(.*)((?s)<w:p\b(?:(?!<w:p\b).)[^$]*?\{{\/' . $blockname . '}<\/w:.*?p>)/is',
            '/(.*((?s)<w:p\b(?:(?!<w:p\b).)*?\\' . $escapedMacroOpeningChars . $blockname . $escapedMacroClosingChars . '<\/w:.*?p>))(.*)((?s)<w:p\b(?:(?!<w:p\b).)[^$]*?\\' . $escapedMacroOpeningChars . '\/' . $blockname . $escapedMacroClosingChars . '<\/w:.*?p>)/is',
            //'/(.*((?s)<w:p\b(?:(?!<w:p\b).)*?\\'. $escapedMacroOpeningChars . $blockname . '}<\/w:.*?p>))(.*)((?s)<w:p\b(?:(?!<w:p\b).)[^$]*?\\'.$escapedMacroOpeningChars.'\/' . $blockname . '}<\/w:.*?p>)/is',
            $this->tempDocumentMainPart,
            $matches
        );

        if (isset($matches[3])) {
            $xmlBlock = $matches[3];
            if ($indexVariables) {
                $cloned = $this->indexClonedVariables($clones, $xmlBlock);
            } elseif ($variableReplacements !== null && is_array($variableReplacements)) {
                $cloned = $this->replaceClonedVariables($variableReplacements, $xmlBlock);
            } else {
                $cloned = [];
                for ($i = 1; $i <= $clones; ++$i) {
                    $cloned[] = $xmlBlock;
                }
            }

            if ($replace) {
                $this->tempDocumentMainPart = str_replace(
                    $matches[2] . $matches[3] . $matches[4],
                    implode('', $cloned),
                    $this->tempDocumentMainPart
                );
            }
        }

        return $xmlBlock;
    }

    /**
     * Replace a block.
     *
     * @param string $blockname
     * @param string $replacement
     */
    public function replaceBlock(string $blockname, string $replacement): void
    {
        $matches = [];
        $escapedMacroOpeningChars = preg_quote(self::$macroOpeningChars);
        $escapedMacroClosingChars = preg_quote(self::$macroClosingChars);
        preg_match(
            '/(<\?xml.*)(<w:p.*>' . $escapedMacroOpeningChars . $blockname . $escapedMacroClosingChars . '<\/w:.*?p>)(.*)(<w:p.*' . $escapedMacroOpeningChars . '\/' . $blockname . $escapedMacroClosingChars . '<\/w:.*?p>)/is',
            $this->tempDocumentMainPart,
            $matches
        );

        if (isset($matches[3])) {
            $this->tempDocumentMainPart = str_replace(
                $matches[2] . $matches[3] . $matches[4],
                $replacement,
                $this->tempDocumentMainPart
            );
        }
    }

    /**
     * Delete a block of text.
     *
     * @param string $blockname
     */
    public function deleteBlock(string $blockname): void
    {
        $this->replaceBlock($blockname, '');
    }

    /**
     * Automatically Recalculate Fields on Open.
     *
     * @param bool $update
     */
    public function setUpdateFields(bool $update = true): void
    {
        $string = $update ? 'true' : 'false';
        $matches = [];
        if (preg_match('/<w:updateFields w:val=\"(true|false|1|0|on|off)\"\/>/', $this->tempDocumentSettingsPart, $matches)) {
            $this->tempDocumentSettingsPart = str_replace($matches[0], '<w:updateFields w:val="' . $string . '"/>', $this->tempDocumentSettingsPart);
        } else {
            $this->tempDocumentSettingsPart = str_replace('</w:settings>', '<w:updateFields w:val="' . $string . '"/></w:settings>', $this->tempDocumentSettingsPart);
        }
    }

    /**
     * Saves the result document.
     *
     * @return string
     * @throws Exception
     */
    public function save(): string
    {
        foreach ($this->tempDocumentHeaders as $index => $xml) {
            $this->savePartWithRels($this->getHeaderName($index), $xml);
        }

        $this->savePartWithRels($this->getMainPartName(), $this->tempDocumentMainPart);
        $this->savePartWithRels($this->getSettingsPartName(), $this->tempDocumentSettingsPart);

        foreach ($this->tempDocumentFooters as $index => $xml) {
            $this->savePartWithRels($this->getFooterName($index), $xml);
        }

        $this->zipClass->addFromString($this->getDocumentContentTypesName(), $this->tempDocumentContentTypes);

        // Close zip file
        if (false === $this->zipClass->close()) {
            throw new Exception('Could not close zip file.'); // @codeCoverageIgnore
        }

        return $this->tempDocumentFilename;
    }

    /**
     * @param string $fileName
     * @param string $xml
     */
    protected function savePartWithRels(string $fileName, string $xml): void
    {
        $this->zipClass->addFromString($fileName, $xml);
        if (isset($this->tempDocumentRelations[$fileName])) {
            $relsFileName = $this->getRelationsName($fileName);
            $this->zipClass->addFromString($relsFileName, $this->tempDocumentRelations[$fileName]);
        }
    }

    /**
     * Saves the result document to the user defined file.
     *
     * @param string $fileName
     * @throws Exception
     * @since 0.8.0
     *
     */
    public function saveAs(string $fileName): void
    {
        $tempFileName = $this->save();

        if (file_exists($fileName)) {
            unlink($fileName);
        }

        /*
         * Note: we do not use `rename` function here, because it loses file ownership data on Windows platform.
         * As a result, user cannot open the file directly getting "Access denied" message.
         *
         * @see https://github.com/PHPOffice/PHPWord/issues/532
         */
        copy($tempFileName, $fileName);
        unlink($tempFileName);
    }

    /**
     * Finds parts of broken macros and sticks them together.
     * Macros, while being edited, could be implicitly broken by some of the word processors.
     *
     * @param string $documentPart The document part in XML representation
     *
     * @return string
     */
    protected function fixBrokenMacros(string $documentPart): string
    {
        $brokenMacroOpeningChars = substr(self::$macroOpeningChars, 0, 1);
        $endMacroOpeningChars = substr(self::$macroOpeningChars, 1);
        $macroClosingChars = self::$macroClosingChars;

        return preg_replace_callback(
            '/\\' . $brokenMacroOpeningChars . '(?:\\' . $endMacroOpeningChars . '|[^{$]*\>\{)[^' . $macroClosingChars . '$]*\}/U',
            function ($match) {
                return strip_tags($match[0]);
            },
            $documentPart
        );
    }

    /**
     * Find and replace macros in the given XML section.
     *
     * @param mixed $search
     * @param mixed $replace
     * @param string|array<int, string> $documentPartXML
     * @param int $limit
     *
     * @return array|null|string|string[]
     */
    protected function setValueForPart(mixed $search, mixed $replace, array|string $documentPartXML, int $limit): array|string|null
    {
        // Note: we can't use the same function for both cases here, because of performance considerations.
        if (self::MAXIMUM_REPLACEMENTS_DEFAULT === $limit) {
            return str_replace($search, $replace, $documentPartXML);
        }
        $regExpEscaper = new RegExp();

        return preg_replace($regExpEscaper->escape($search), $replace, $documentPartXML, $limit);
    }

    /**
     * Find all variables in $documentPartXML.
     *
     * @param string $documentPartXML
     *
     * @return string[]
     */
    protected function getVariablesForPart(string $documentPartXML): array
    {
        $matches = [];
        $escapedMacroOpeningChars = preg_quote(self::$macroOpeningChars);
        $escapedMacroClosingChars = preg_quote(self::$macroClosingChars);

        preg_match_all("/$escapedMacroOpeningChars(.*?)$escapedMacroClosingChars/i", $documentPartXML, $matches);

        return $matches[1];
    }

    /**
     * Get the name of the header file for $index.
     *
     * @param int $index
     *
     * @return string
     */
    protected function getHeaderName(int $index): string
    {
        return sprintf('word/header%d.xml', $index);
    }

    /**
     * Usually, the name of main part document will be 'document.xml'. However, some .docx files (possibly those from Office 365, experienced also on documents from Word Online created from blank templates) have file 'document22.xml' in their zip archive instead of 'document.xml'. This method searches content types file to correctly determine the file name.
     *
     * @return string
     */
    protected function getMainPartName(): string
    {
        $contentTypes = $this->zipClass->getFromName('[Content_Types].xml');

        $pattern = '~PartName="\/(word\/document.*?\.xml)" ContentType="application\/vnd\.openxmlformats-officedocument\.wordprocessingml\.document\.main\+xml"~';

        $matches = [];
        preg_match($pattern, $contentTypes, $matches);

        return array_key_exists(1, $matches) ? $matches[1] : 'word/document.xml';
    }

    /**
     * The name of the file containing the Settings part.
     *
     * @return string
     */
    protected function getSettingsPartName(): string
    {
        return 'word/settings.xml';
    }

    /**
     * Get the name of the footer file for $index.
     *
     * @param int $index
     *
     * @return string
     */
    protected function getFooterName(int $index): string
    {
        return sprintf('word/footer%d.xml', $index);
    }

    /**
     * Get the name of the relations file for document part.
     *
     * @param string $documentPartName
     *
     * @return string
     */
    protected function getRelationsName(string $documentPartName): string
    {
        return 'word/_rels/' . pathinfo($documentPartName, PATHINFO_BASENAME) . '.rels';
    }

    /**
     * This method returns the next available index for a relation in a document part.
     * It checks if the document part has existing relations and finds the highest index,
     * then increments it to get the next available index.
     * If there are no existing relations, it returns 1 as the initial index.
     *
     * @param string $documentPartName The name of the document part.
     * @return int The next available index for a relation in the document part.
     */
    protected function getNextRelationsIndex(string $documentPartName): int
    {
        if (isset($this->tempDocumentRelations[$documentPartName])) {
            $candidate = substr_count($this->tempDocumentRelations[$documentPartName], '<Relationship');
            while (str_contains($this->tempDocumentRelations[$documentPartName], 'Id="rId' . $candidate . '"')) {
                ++$candidate;
            }

            return $candidate;
        }

        return 1;
    }

    /**
     * @return string
     */
    protected function getDocumentContentTypesName(): string
    {
        return '[Content_Types].xml';
    }

    /**
     * Find the start position of the nearest table before $offset.
     * @throws Exception
     */
    private function findTableStart(int $offset): int
    {
        $rowStart = strrpos(
            $this->tempDocumentMainPart,
            '<w:tbl ',
            ((strlen($this->tempDocumentMainPart) - $offset) * -1)
        );

        if (!$rowStart) {
            $rowStart = strrpos(
                $this->tempDocumentMainPart,
                '<w:tbl>',
                ((strlen($this->tempDocumentMainPart) - $offset) * -1)
            );
        }
        if (!$rowStart) {
            throw new Exception('Can not find the start position of the table.');
        }

        return $rowStart;
    }

    /**
     * Find the end position of the nearest table row after $offset.
     */
    private function findTableEnd(int $offset): int
    {
        return strpos($this->tempDocumentMainPart, '</w:tbl>', $offset) + 7;
    }

    /**
     * Find the start position of the nearest table row before $offset.
     *
     * @param int $offset
     *
     * @return int
     * @throws Exception
     */
    protected function findRowStart(int $offset): int
    {
        $rowStart = strrpos($this->tempDocumentMainPart, '<w:tr ', ((strlen($this->tempDocumentMainPart) - $offset) * -1));

        if (!$rowStart) {
            $rowStart = strrpos($this->tempDocumentMainPart, '<w:tr>', ((strlen($this->tempDocumentMainPart) - $offset) * -1));
        }
        if (!$rowStart) {
            throw new Exception('Can not find the start position of the row to clone.');
        }

        return $rowStart;
    }

    /**
     * Find the end position of the nearest table row after $offset.
     *
     * @param int $offset
     *
     * @return int
     */
    protected function findRowEnd(int $offset): int
    {
        return strpos($this->tempDocumentMainPart, '</w:tr>', $offset) + 7;
    }

    /**
     * Get a slice of a string.
     *
     * @param int $startPosition
     * @param int $endPosition
     *
     * @return string
     */
    protected function getSlice(int $startPosition, int $endPosition = 0): string
    {
        if (!$endPosition) {
            $endPosition = strlen($this->tempDocumentMainPart);
        }

        return substr($this->tempDocumentMainPart, $startPosition, ($endPosition - $startPosition));
    }

    /**
     * Replaces variable names in cloned
     * rows/blocks with indexed names.
     *
     * @param int $count
     * @param string $xmlBlock
     *
     * @return string
     */
    protected function indexClonedVariables(int $count, string $xmlBlock): array|string
    {
        $results = [];
        $escapedMacroOpeningChars = preg_quote(self::$macroOpeningChars);
        $escapedMacroClosingChars = preg_quote(self::$macroClosingChars);

        for ($i = 1; $i <= $count; ++$i) {
            $results[] = preg_replace("/$escapedMacroOpeningChars([^:]*?)(:.*?)?$escapedMacroClosingChars/", self::$macroOpeningChars . '\1#' . $i . '\2' . self::$macroClosingChars, $xmlBlock);
        }

        return $results;
    }

    /**
     * Replaces variables with values from array, array keys are the variable names.
     *
     * @param array $variableReplacements
     * @param string $xmlBlock
     *
     * @return string[]
     */
    protected function replaceClonedVariables(array $variableReplacements, string $xmlBlock): array
    {
        $results = [];
        foreach ($variableReplacements as $replacementArray) {
            $localXmlBlock = $xmlBlock;
            foreach ($replacementArray as $search => $replacement) {
                $localXmlBlock = $this->setValueForPart(self::ensureMacroCompleted($search), $replacement, $localXmlBlock, self::MAXIMUM_REPLACEMENTS_DEFAULT);
            }
            $results[] = $localXmlBlock;
        }

        return $results;
    }

    /**
     * Replace an XML block surrounding a macro with a new block.
     *
     * @param string $macro Name of macro
     * @param string $block New block content
     * @param string $blockType XML tag type of block
     *
     * @return TemplateProcessor Fluent interface
     */
    public function replaceXmlBlock(string $macro, string $block, string $blockType = 'w:p'): TemplateProcessor
    {
        $where = $this->findContainingXmlBlockForMacro($macro, $blockType);
        if (is_array($where)) {
            $this->tempDocumentMainPart = $this->getSlice(0, $where['start']) . $block . $this->getSlice($where['end']);
        }

        return $this;
    }

    /**
     * Find start and end of XML block containing the given macro
     * e.g. <w:p>...${macro}...</w:p>.
     *
     * Note that only the first instance of the macro will be found
     *
     * @param string $macro Name of macro
     * @param string $blockType XML tag for block
     *
     * @return bool|int[] FALSE if not found, otherwise array with start and end
     */
    protected function findContainingXmlBlockForMacro(string $macro, string $blockType = 'w:p'): array|bool
    {
        $macroPos = $this->findMacro($macro);
        if (0 > $macroPos) {
            return false;
        }
        $start = $this->findXmlBlockStart($macroPos, $blockType);
        if (0 > $start) {
            return false;
        }
        $end = $this->findXmlBlockEnd($start, $blockType);
        //if not found or if resulting string does not contain the macro we are searching for
        if (0 > $end || !str_contains($this->getSlice($start, $end), $macro)) {
            return false;
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Find the position of (the start of) a macro.
     *
     * Returns -1 if not found, otherwise position of opening $
     *
     * Note that only the first instance of the macro will be found
     *
     * @param string $search Macro name
     * @param int $offset Offset from which to start searching
     *
     * @return int -1 if macro not found
     */
    protected function findMacro(string $search, int $offset = 0): int
    {
        $search = static::ensureMacroCompleted($search);
        $pos = strpos($this->tempDocumentMainPart, $search, $offset);

        return ($pos === false) ? -1 : $pos;
    }

    /**
     * Find the start position of the nearest XML block start before $offset.
     *
     * @param int $offset Search position
     * @param string $blockType XML Block tag
     *
     * @return int -1 if block start not found
     */
    protected function findXmlBlockStart(int $offset, string $blockType): int
    {
        $reverseOffset = (strlen($this->tempDocumentMainPart) - $offset) * -1;
        // first try XML tag with attributes
        $blockStart = strrpos($this->tempDocumentMainPart, '<' . $blockType . ' ', $reverseOffset);
        // if not found, or if found but contains the XML tag without attribute
        if (false === $blockStart || strrpos($this->getSlice($blockStart, $offset), '<' . $blockType . '>')) {
            // also try XML tag without attributes
            $blockStart = strrpos($this->tempDocumentMainPart, '<' . $blockType . '>', $reverseOffset);
        }

        return ($blockStart === false) ? -1 : $blockStart;
    }

    /**
     * Find the nearest block end position after $offset.
     *
     * @param int $offset Search position
     * @param string $blockType XML Block tag
     *
     * @return int -1 if block end not found
     */
    protected function findXmlBlockEnd(int $offset, string $blockType): int
    {
        $blockEndStart = strpos($this->tempDocumentMainPart, '</' . $blockType . '>', $offset);
        // return position of end of tag if found, otherwise -1

        return ($blockEndStart === false) ? -1 : $blockEndStart + 3 + strlen($blockType);
    }

    /**
     * Splits a w:r/w:t into a list of w:r where each ${macro} is in a separate w:r.
     *
     * @param string $text
     *
     * @return string
     */
    protected function splitTextIntoTexts($text): string
    {
        if (!$this->textNeedsSplitting($text)) {
            return $text;
        }
        $matches = [];
        if (preg_match('/(<w:rPr.*<\/w:rPr>)/i', $text, $matches)) {
            $extractedStyle = $matches[0];
        } else {
            $extractedStyle = '';
        }

        $unformattedText = preg_replace('/>\s+</', '><', $text);
        $result = str_replace([self::$macroOpeningChars, self::$macroClosingChars], ['</w:t></w:r><w:r>' . $extractedStyle . '<w:t xml:space="preserve">' . self::$macroOpeningChars, self::$macroClosingChars . '</w:t></w:r><w:r>' . $extractedStyle . '<w:t xml:space="preserve">'], $unformattedText);

        return str_replace(['<w:r>' . $extractedStyle . '<w:t xml:space="preserve"></w:t></w:r>', '<w:r><w:t xml:space="preserve"></w:t></w:r>', '<w:t>'], ['', '', '<w:t xml:space="preserve">'], $result);
    }

    /**
     * Returns true if string contains a macro that is not in it its own w:r.
     *
     * @param string $text
     *
     * @return bool
     */
    protected function textNeedsSplitting(string $text): bool
    {
        $escapedMacroOpeningChars = preg_quote(self::$macroOpeningChars);
        $escapedMacroClosingChars = preg_quote(self::$macroClosingChars);

        return 1 === preg_match('/[^>]' . $escapedMacroOpeningChars . '|' . $escapedMacroClosingChars . '[^<]/i', $text);
    }

    /**
     * Sets the macro opening characters.
     *
     * @param string $macroOpeningChars The macro opening characters to be set.
     * @return void
     */
    public function setMacroOpeningChars(string $macroOpeningChars): void
    {
        self::$macroOpeningChars = $macroOpeningChars;
    }

    /**
     * Sets the macro closing characters.
     *
     * @param string $macroClosingChars The characters to be used as the macro closing characters.
     * @return void
     */
    public function setMacroClosingChars(string $macroClosingChars): void
    {
        self::$macroClosingChars = $macroClosingChars;
    }

    /**
     * Sets the macro opening and closing characters.
     *
     * @param string $macroOpeningChars The characters used to represent the opening of a macro.
     * @param string $macroClosingChars The characters used to represent the closing of a macro.
     * @return void
     */
    public function setMacroChars(string $macroOpeningChars, string $macroClosingChars): void
    {
        self::$macroOpeningChars = $macroOpeningChars;
        self::$macroClosingChars = $macroClosingChars;
    }
}
