<?php

declare(strict_types=1);

namespace Dom;

/**
 * PHP 8.3 only (3.7): the part of PHP 8.4's HTML5 DOM (Dom\HTMLDocument and the classes around it) that Kaleta uses.
 * PHP 8.4 has these classes built in and never loads this folder (the autoloader in system/bootstrap.php).
 *
 * The classes extend the legacy DOM (\DOMElement, \DOMText…), so the usual navigation and changes work as in 8.4;
 * Kaleta\Compat\Html5Parser builds the tree like PHP 8.4 parses HTML, Html5Serializer writes it back, Selector answers
 * querySelector(). Here Node is an interface – the legacy element, text and comment classes have no common parent
 * of ours. The contract PHPStan checks the code against is tools/phpstan-dom.php: use nothing outside it.
 */
interface Node
{
}
