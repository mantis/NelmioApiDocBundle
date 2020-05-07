<?php

/*
 * This file is part of the NelmioApiDocBundle package.
 *
 * (c) Nelmio
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Nelmio\ApiDocBundle\RouteDescriber;

use Doctrine\Common\Annotations\Reader;
use FOS\RestBundle\Controller\Annotations\QueryParam;
use FOS\RestBundle\Controller\Annotations\RequestParam;
use Nelmio\ApiDocBundle\OpenApiPhp\Util;
use OpenApi\Annotations as OA;
use Symfony\Component\Routing\Route;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\Regex;

final class FosRestDescriber implements RouteDescriberInterface
{
    use RouteDescriberTrait;

    /** @var Reader */
    private $annotationReader;

    /** @var string */
    private $mediaType;

    public function __construct(Reader $annotationReader, string $mediaType = 'json')
    {
        $this->annotationReader = $annotationReader;
        $this->mediaType = $mediaType;
    }

    public function describe(OA\OpenApi $api, Route $route, \ReflectionMethod $reflectionMethod)
    {
        $annotations = $this->annotationReader->getMethodAnnotations($reflectionMethod);
        $annotations = array_filter($annotations, static function ($value) {
            return $value instanceof RequestParam || $value instanceof QueryParam;
        });

        foreach ($this->getOperations($api, $route) as $operation) {
            foreach ($annotations as $annotation) {
                $parameterName = $annotation->key ?? $annotation->getName(); // the key used by fosrest

                if ($annotation instanceof QueryParam) {
                    $name = $parameterName.($annotation->map ? '[]' : '');
                    $parameter = Util::getOperationParameter($operation, $name, 'query');
                    $parameter->allowEmptyValue = $annotation->nullable && $annotation->allowBlank;

                    $parameter->required = !$annotation->nullable && $annotation->strict;

                    if (OA\UNDEFINED === $parameter->description) {
                        $parameter->description = $annotation->description;
                    }

                    $schema = Util::getChild($parameter, OA\Schema::class);
                } else {
                    /** @var OA\RequestBody $requestBody */
                    $requestBody = Util::getChild($operation, OA\RequestBody::class);
                    $contentSchema = $this->getContentSchema($requestBody);
                    $schema = Util::getProperty($contentSchema, $parameterName);

                    if (!$annotation->nullable && $annotation->strict) {
                        $requiredParameters = is_array($contentSchema->required) ? $contentSchema->required : [];
                        $requiredParameters[] = $parameterName;

                        $contentSchema->required = array_values(array_unique($requiredParameters));
                    }
                }

                $schema->default = $annotation->getDefault();

                if (OA\UNDEFINED === $schema->type) {
                    $schema->type = $annotation->map ? 'array' : 'string';
                }

                if ($annotation->map) {
                    $schema->type = 'array';
                    $schema->collectionFormat = 'multi';
                    $schema->items = Util::getChild($schema, OA\Items::class);
                }

                $pattern = $this->getPattern($annotation->requirements);
                if (null !== $pattern) {
                    $schema->pattern = $pattern;
                }

                $format = $this->getFormat($annotation->requirements);
                if (null !== $format) {
                    $schema->format = $format;
                }
            }
        }
    }

    private function getPattern($requirements)
    {
        if (is_array($requirements) && isset($requirements['rule'])) {
            return (string) $requirements['rule'];
        }

        if (is_string($requirements)) {
            return $requirements;
        }

        if ($requirements instanceof Regex) {
            return $requirements->getHtmlPattern();
        }

        return null;
    }

    private function getFormat($requirements)
    {
        if ($requirements instanceof Constraint && !$requirements instanceof Regex) {
            $reflectionClass = new \ReflectionClass($requirements);

            return $reflectionClass->getShortName();
        }

        return null;
    }

    private function getContentSchema(OA\RequestBody $requestBody): OA\Schema
    {
        $requestBody->content = OA\UNDEFINED !== $requestBody->content ? $requestBody->content : [];
        switch ($this->mediaType) {
            case 'json':
                $contentType = 'application\json';

                break;
            case 'xml':
                $contentType = 'application\xml';

                break;
            default:
                throw new \InvalidArgumentException('Unsupported media type');
        }
        if (!isset($requestBody->content[$contentType])) {
            $requestBody->content[$contentType] = new OA\MediaType(
                [
                    'mediaType' => $contentType,
                ]
            );
            /** @var OA\Schema $schema */
            $schema = Util::getChild(
                $requestBody->content[$contentType],
                OA\Schema::class
            );
            $schema->type = 'object';
        }

        return Util::getChild(
            $requestBody->content[$contentType],
            OA\Schema::class
        );
    }
}
