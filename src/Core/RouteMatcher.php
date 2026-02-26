<?php

namespace App\Core;

class RouteMatcher
{
    private static PatternConverter $patternConverter;
    private static ParamValidator $paramValidator;
    private static ParamExtractor $paramExtractor;

    public static function init()
    {
        self::$patternConverter = new PatternConverter();
        self::$paramValidator   = new ParamValidator();
        self::$paramExtractor   = new ParamExtractor(self::$patternConverter, self::$paramValidator);
    }

    public static function getParams(string $pattern, string $path, array $queryParams, bool $includeQueryParams = true): ?array
    {
        return self::$paramExtractor->getParams($pattern, $path, $queryParams, $includeQueryParams);
    }
}

RouteMatcher::init();