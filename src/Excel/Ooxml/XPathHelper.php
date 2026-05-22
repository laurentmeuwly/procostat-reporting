<?php

namespace Procorad\ProcostatReporting\Excel\Ooxml;

/**
 * Centralises OOXML namespace declarations for DOMXPath queries.
 *
 * OOXML chart XML uses several interleaved namespaces:
 *   c  — chart elements       (drawingml/2006/chart)
 *   a  — drawing primitives   (drawingml/2006/main)
 *   r  — relationships        (officeDocument/2006/relationships)
 *
 * Having them in one place means:
 *   - no namespace typo scattered across builders
 *   - easy to extend if a new namespace is needed (e.g. mc: for compatibility)
 *   - consistent XPath expressions across all Builders and Injectors
 */
final class XPathHelper
{
    // Namespace URIs
    public const NS_C = 'http://schemas.openxmlformats.org/drawingml/2006/chart';
    public const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    public const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * Build a DOMXPath with all OOXML namespaces pre-registered.
     */
    public static function for(\DOMDocument $dom): \DOMXPath
    {
        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('c', self::NS_C);
        $xpath->registerNamespace('a', self::NS_A);
        $xpath->registerNamespace('r', self::NS_R);

        return $xpath;
    }

    /**
     * Create a namespaced element in the chart namespace (c:).
     */
    public static function createElement(\DOMDocument $dom, string $localName): \DOMElement
    {
        return $dom->createElementNS(self::NS_C, "c:{$localName}");
    }

    /**
     * Create a namespaced element in the drawing namespace (a:).
     */
    public static function createDrawingElement(\DOMDocument $dom, string $localName): \DOMElement
    {
        return $dom->createElementNS(self::NS_A, "a:{$localName}");
    }

    /**
     * Set an attribute on an element (convenience wrapper).
     */
    public static function attr(\DOMElement $el, string $name, string $value): \DOMElement
    {
        $el->setAttribute($name, $value);
        return $el;
    }

    /**
     * Remove all direct child elements with a given local name from a parent.
     * Used to clean up PhpSpreadsheet defaults before injecting our own.
     */
    public static function removeChildren(\DOMElement $parent, string $localName, string $ns = self::NS_C): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof \DOMElement && $child->localName === $localName && $child->namespaceURI === $ns) {
                $parent->removeChild($child);
            }
        }
    }

    /**
     * Insert $newNode before $referenceLocalName child of $parent.
     * Falls back to appendChild if the reference child is not found.
     */
    public static function insertBefore(\DOMElement $parent, \DOMNode $newNode, string $referenceLocalName, string $ns = self::NS_C): void
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $referenceLocalName && $child->namespaceURI === $ns) {
                $parent->insertBefore($newNode, $child);
                return;
            }
        }
        $parent->appendChild($newNode);
    }

    /**
     * Insert $newNode after $referenceLocalName child of $parent.
     */
    public static function insertAfter(\DOMElement $parent, \DOMNode $newNode, string $referenceLocalName, string $ns = self::NS_C): void
    {
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $referenceLocalName && $child->namespaceURI === $ns) {
                $next = $child->nextSibling;
                if ($next) {
                    $parent->insertBefore($newNode, $next);
                } else {
                    $parent->appendChild($newNode);
                }
                return;
            }
        }
        $parent->appendChild($newNode);
    }
}
