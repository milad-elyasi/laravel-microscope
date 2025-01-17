<?php

namespace Imanghafoori\LaravelMicroscope\SearchReplace;

use Illuminate\Support\Str;
use Imanghafoori\Filesystem\Filesystem;
use Imanghafoori\LaravelMicroscope\Check;
use Imanghafoori\LaravelMicroscope\ErrorReporters\ErrorPrinter;
use Imanghafoori\LaravelMicroscope\Foundations\PhpFileDescriptor;
use Imanghafoori\SearchReplace\Finder;
use Imanghafoori\SearchReplace\Replacer;
use Imanghafoori\SearchReplace\Stringify;
use Imanghafoori\TokenAnalyzer\Refactor;

class PatternRefactorings implements Check
{
    public static $patternFound = false;

    public static function check(PhpFileDescriptor $file, $patterns)
    {
        $absFilePath = $file->getAbsolutePath();

        foreach ($patterns[0] as $pattern) {
            $cacheKey = $pattern['cacheKey'] ?? null;

            if ($cacheKey && CachedFiles::isCheckedBefore($cacheKey, $file)) {
                continue;
            }

            $tokens = $file->getTokens();

            if (isset($pattern['file']) && ! Str::endsWith($absFilePath, $pattern['file'])) {
                continue;
            }

            if (isset($pattern['directory']) && ! Str::startsWith($absFilePath, $pattern['directory'])) {
                continue;
            }

            $i = 0;
            start:
            $namedPatterns = $pattern['named_patterns'] ?? [];
            $matchedValues = Finder::getMatches($pattern['search'], $tokens, $pattern['predicate'], $pattern['mutator'], $namedPatterns, $pattern['filters'], $i);

            if (! $matchedValues) {
                $cacheKey && CachedFiles::addToCache($cacheKey, $file);
                continue;
            }

            $postReplaces = $pattern['post_replace'] ?? [];
            if (! isset($pattern['replace'])) {
                foreach ($matchedValues as $matchedValue) {
                    self::show($matchedValue, $tokens, $absFilePath);
                }
                continue;
            }

            foreach ($matchedValues as $matchedValue) {
                [$newTokens, $lineNum] = Replacer::applyMatch(
                    $pattern['replace'],
                    $matchedValue,
                    $tokens,
                    $pattern['avoid_syntax_errors'] ?? false,
                    $pattern['avoid_result_in'] ?? [],
                    $postReplaces,
                    $namedPatterns
                );

                if ($lineNum === null) {
                    continue;
                }

                $to = Replacer::applyWithPostReplacements($pattern['replace'], $matchedValue['values'], $postReplaces, $namedPatterns);
                $countOldTokens = count($tokens);
                $tokens = self::save($matchedValue, $tokens, $to, $lineNum, $absFilePath, $newTokens);

                $tokens = token_get_all(Stringify::fromTokens($tokens));
                $diff = count($tokens) - $countOldTokens;
                $minCount = self::minimumMatchLength($pattern['search']);

                $i = $matchedValue['end'] + $diff + 1 - $minCount + 1;

                goto start;
            }
        }
    }

    private static function printLinks($lineNum, $absFilePath, $startingCode, $endResult)
    {
        $printer = ErrorPrinter::singleton();
        // Print Replacement Links
        $printer->print('Replacing:
<fg=yellow>'.Str::limit($startingCode, 150).'</>', '', 0);
        $printer->print('With:
<fg=yellow>'.Str::limit($endResult, 150).'</>', '', 0);

        $printer->print('<fg=red>Replacement will occur at:</>', '', 0);

        $lineNum && $printer->printLink($absFilePath, $lineNum, 0);
    }

    private static function askToRefactor($absFilePath)
    {
        $text = 'Do you want to replace '.basename($absFilePath).' with new version of it?';

        return ErrorPrinter::singleton()->printer->confirm($text);
    }

    private static function save($matchedValue, $tokens, $to, $lineNum, $absFilePath, $newTokens)
    {
        $from = Finder::getPortion($matchedValue['start'] + 1, $matchedValue['end'] + 1, $tokens);
        self::printLinks($lineNum, $absFilePath, $from, $to);

        if (self::askToRefactor($absFilePath)) {
            Filesystem::$fileSystem::file_put_contents($absFilePath, Refactor::toString($newTokens));
            $tokens = $newTokens;
        }

        return $tokens;
    }

    private static function minimumMatchLength($patternTokens)
    {
        $count = 0;
        foreach ($patternTokens as $token) {
            ! Finder::isOptional($token[1] ?? $token[0]) && $count++;
        }

        return $count;
    }

    private static function show($matchedValue, $tokens, $absFilePath)
    {
        [$message, $lineNum] = self::getShowMessage($matchedValue, $tokens);

        self::printShow($message, $absFilePath, $lineNum);
    }

    private static function getShowMessage($matchedValue, $tokens): array
    {
        $start = $matchedValue['start'] + 1;
        $end = $matchedValue['end'] + 1;

        $from = '';
        $lineNum = 0;
        for ($i = $start - 1; $i < $end; $i++) {
            ! $lineNum && $lineNum = ($tokens[$i][2] ?? 0);
            $from .= $tokens[$i][1] ?? $tokens[$i][0];
        }
        $message = 'Detected:
<fg=yellow>'.Str::limit($from, 150).'</>
<fg=red>Found at:</>';
        self::$patternFound = true;

        return [$message, $lineNum];
    }

    public static function printShow(string $message, $absFilePath, $lineNum): void
    {
        $printer = ErrorPrinter::singleton();
        $printer->print($message, '', 0);
        $lineNum && $printer->printLink($absFilePath, $lineNum, 0);
    }
}
