<?php

namespace App\Core;

class ParamExtractor
{
    private PatternConverter $patternConverter;
    private ParamValidator $paramValidator;

    public function __construct(PatternConverter $patternConverter, ParamValidator $paramValidator)
    {
        $this->patternConverter = $patternConverter;
        $this->paramValidator   = $paramValidator;
    }

    public function getQueryParams(array $queryParams): array
    {
        return $queryParams;
    }

    public function getParams(string $pattern, string $path, array $queryParams, bool $includeQueryParams = true): ?array
    {
        $regexPattern = $this->patternConverter->convertPatternToRegex($pattern);

        if (preg_match($regexPattern, $path, $matches)) {

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            if ($includeQueryParams) {
                $queryParams    = $this->getQueryParams($queryParams);
                $params         = array_merge($params, $queryParams);
            }

            unset($params['url']);

            if ($this->paramValidator->validateParams($pattern, $params)) {
                return $this->convertParamTypes($pattern, $params);
            }
        }

        return null;
    }

    private function convertParamTypes(string $pattern, array $params): array
    {
        preg_match_all('/\{(\w+)(?::(\w+))?(?::(\d+))?\}/', $pattern, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $param  = $match[1];
            $type   = $match[2] ?? 'any';

            if (isset($params[$param])) {
                $params[$param] = $this->castToType($params[$param], $type);
            }
        }

        return $params;
    }

    private function castToType(string $value, string $type): mixed
    {
        return match ($type) {
            'int'               => (int) $value,
            'float', 'decimal'  => (float) $value,
            'boolean'           => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            default             => $value,
        };
    }
}
