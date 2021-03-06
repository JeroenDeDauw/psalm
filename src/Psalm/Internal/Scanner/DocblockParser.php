<?php

namespace Psalm\Internal\Scanner;

use function trim;
use function preg_replace;
use function explode;
use function preg_match;
use function strlen;
use function str_replace;
use const PREG_OFFSET_CAPTURE;
use function strpos;
use function rtrim;
use function array_filter;
use function min;
use function str_repeat;

class DocblockParser
{
    public static function parse(string $docblock) : ParsedDocblock
    {
        // Strip off comments.
        $docblock = trim($docblock);

        $docblock = preg_replace('@^/\*\*@', '', $docblock);
        $docblock = preg_replace('@\*\*?/$@', '', $docblock);

        // Normalize multi-line @specials.
        $lines = explode("\n", $docblock);

        $special = [];

        $last = false;
        foreach ($lines as $k => $line) {
            if (preg_match('/^[ \t]*\*?\s*@\w/i', $line)) {
                $last = $k;
            } elseif (preg_match('/^\s*\r?$/', $line)) {
                $last = false;
            } elseif ($last !== false) {
                $old_last_line = $lines[$last];
                $lines[$last] = $old_last_line . "\n" . $line;

                unset($lines[$k]);
            }
        }

        $line_offset = 0;

        foreach ($lines as $line) {
            $original_line_length = strlen($line);

            $line = str_replace("\r", '', $line);

            if (preg_match('/^[ \t]*\*?\s*@([\w\-:]+)[\t ]*(.*)$/sm', $line, $matches, PREG_OFFSET_CAPTURE)) {
                /** @var array<int, array{string, int}> $matches */
                list($full_match_info, $type_info, $data_info) = $matches;

                list($full_match) = $full_match_info;
                list($type) = $type_info;
                list($data, $data_offset) = $data_info;

                if (strpos($data, '*')) {
                    $data = rtrim(preg_replace('/^[ \t]*\*\s*$/m', '', $data));
                }

                $docblock = str_replace($full_match, '', $docblock);

                if (empty($special[$type])) {
                    $special[$type] = [];
                }

                $data_offset += $line_offset;

                $special[$type][$data_offset + 3] = $data;
            }

            $line_offset += $original_line_length + 1;
        }

        $docblock = str_replace("\t", '  ', $docblock);

        // Smush the whole docblock to the left edge.
        $min_indent = 80;
        $indent = 0;
        foreach (array_filter(explode("\n", $docblock)) as $line) {
            for ($ii = 0; $ii < strlen($line); ++$ii) {
                if ($line[$ii] != ' ') {
                    break;
                }
                ++$indent;
            }

            $min_indent = min($indent, $min_indent);
        }

        $docblock = preg_replace('/^' . str_repeat(' ', $min_indent) . '/m', '', $docblock);
        $docblock = rtrim($docblock);

        // Trim any empty lines off the front, but leave the indent level if there
        // is one.
        $docblock = preg_replace('/^\s*\n/', '', $docblock);

        $parsed = new ParsedDocblock($docblock, $special);

        self::resolveTags($parsed);

        return $parsed;
    }

    private static function resolveTags(ParsedDocblock $docblock) : void
    {
        if (isset($docblock->tags['template'])
            || isset($docblock->tags['psalm-template'])
            || isset($docblock->tags['phpstan-template'])
        ) {
            $docblock->combined_tags['template']
                = ($docblock->tags['template'] ?? [])
                + ($docblock->tags['phpstan-template'] ?? [])
                + ($docblock->tags['psalm-template'] ?? []);
        }

        if (isset($docblock->tags['template-covariant'])
            || isset($docblock->tags['psalm-template-covariant'])
            || isset($docblock->tags['phpstan-template-covariant'])
        ) {
            $docblock->combined_tags['template-covariant']
                = ($docblock->tags['template-covariant'] ?? [])
                + ($docblock->tags['phpstan-template-covariant'] ?? [])
                + ($docblock->tags['psalm-template-covariant'] ?? []);
        }

        if (isset($docblock->tags['template-extends'])
            || isset($docblock->tags['inherits'])
            || isset($docblock->tags['extends'])
            || isset($docblock->tags['psalm-extends'])
            || isset($docblock->tags['phpstan-extends'])
        ) {
            $docblock->combined_tags['extends']
                = ($docblock->tags['template-extends'] ?? [])
                + ($docblock->tags['inherits'] ?? [])
                + ($docblock->tags['extends'] ?? [])
                + ($docblock->tags['psalm-extends'] ?? [])
                + ($docblock->tags['phpstan-extends'] ?? []);
        }

        if (isset($docblock->tags['template-implements'])
            || isset($docblock->tags['implements'])
            || isset($docblock->tags['phpstan-implements'])
            || isset($docblock->tags['psalm-implements'])
        ) {
            $docblock->combined_tags['implements']
                = ($docblock->tags['template-implements'] ?? [])
                + ($docblock->tags['implements'] ?? [])
                + ($docblock->tags['phpstan-implements'] ?? [])
                + ($docblock->tags['psalm-implements'] ?? []);
        }

        if (isset($docblock->tags['template-use'])
            || isset($docblock->tags['use'])
            || isset($docblock->tags['phpstan-use'])
            || isset($docblock->tags['psalm-use'])
        ) {
            $docblock->combined_tags['use']
                = ($docblock->tags['template-use'] ?? [])
                + ($docblock->tags['use'] ?? [])
                + ($docblock->tags['phpstan-use'] ?? [])
                + ($docblock->tags['psalm-use'] ?? []);
        }

        if (isset($docblock->tags['method'])
            || isset($docblock->tags['psalm-method'])
        ) {
            $docblock->combined_tags['method']
                = ($docblock->tags['method'] ?? [])
                + ($docblock->tags['psalm-method'] ?? []);
        }

        if (isset($docblock->tags['return'])
            || isset($docblock->tags['psalm-return'])
            || isset($docblock->tags['phpstan-return'])
        ) {
            if (isset($docblock->tags['psalm-return'])) {
                $docblock->combined_tags['return'] = $docblock->tags['psalm-return'];
            } elseif (isset($docblock->tags['phpstan-return'])) {
                $docblock->combined_tags['return'] = $docblock->tags['phpstan-return'];
            } else {
                $docblock->combined_tags['return'] = $docblock->tags['return'];
            }
        }

        if (isset($docblock->tags['param'])
            || isset($docblock->tags['psalm-param'])
            || isset($docblock->tags['phpstan-param'])
        ) {
            $docblock->combined_tags['param']
                = ($docblock->tags['param'] ?? [])
                + ($docblock->tags['phpstan-param'] ?? [])
                + ($docblock->tags['psalm-param'] ?? []);
        }

        if (isset($docblock->tags['var'])
            || isset($docblock->tags['psalm-var'])
            || isset($docblock->tags['phpstan-var'])
        ) {
            $docblock->combined_tags['var']
                = ($docblock->tags['var'] ?? [])
                + ($docblock->tags['phpstan-var'] ?? [])
                + ($docblock->tags['psalm-var'] ?? []);
        }
    }
}
