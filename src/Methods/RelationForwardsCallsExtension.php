<?php

declare(strict_types=1);

namespace NunoMaduro\Larastan\Methods;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use NunoMaduro\Larastan\Reflection\EloquentBuilderMethodReflection;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\MissingMethodFromReflectionException;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\ShouldNotHappenException;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

final class RelationForwardsCallsExtension implements MethodsClassReflectionExtension
{
    /** @var BuilderHelper */
    private $builderHelper;

    /** @var array<string, MethodReflection> */
    private $cache = [];

    /** @var ReflectionProvider */
    private $reflectionProvider;

    /** @var EloquentBuilderForwardsCallsExtension */
    private $eloquentBuilderForwardsCallsExtension;

    public function __construct(BuilderHelper $builderHelper, ReflectionProvider $reflectionProvider, EloquentBuilderForwardsCallsExtension $eloquentBuilderForwardsCallsExtension)
    {
        $this->builderHelper = $builderHelper;
        $this->reflectionProvider = $reflectionProvider;
        $this->eloquentBuilderForwardsCallsExtension = $eloquentBuilderForwardsCallsExtension;
    }

    public function hasMethod(ClassReflection $classReflection, string $methodName): bool
    {
        if (array_key_exists($classReflection->getCacheKey().'-'.$methodName, $this->cache)) {
            return true;
        }

        $methodReflection = $this->findMethod($classReflection, $methodName);

        if ($methodReflection !== null) {
            $this->cache[$classReflection->getCacheKey().'-'.$methodName] = $methodReflection;

            return true;
        }

        return false;
    }

    public function getMethod(
        ClassReflection $classReflection,
        string $methodName
    ): MethodReflection {
        return $this->cache[$classReflection->getCacheKey().'-'.$methodName];
    }

    /**
     * @throws MissingMethodFromReflectionException
     * @throws ShouldNotHappenException
     */
    private function findMethod(ClassReflection $classReflection, string $methodName): ?MethodReflection
    {
        if (! $classReflection->isSubclassOf(Relation::class)) {
            return null;
        }

        $relatedModel = $classReflection->getActiveTemplateTypeMap()->getType('TRelatedModel');

        if ($relatedModel === null) {
            return null;
        }

        if ($relatedModel->getObjectClassReflections() !== []) {
            $modelReflection = $relatedModel->getObjectClassReflections()[0];
        } else {
            $modelReflection = $this->reflectionProvider->getClass(Model::class);
        }

        $builderName = $this->builderHelper->determineBuilderName($modelReflection->getName());

        $builderReflection = $this->reflectionProvider->getClass($builderName)->withTypes([$relatedModel]);

        if ($builderReflection->hasNativeMethod($methodName)) {
            $reflection = $builderReflection->getNativeMethod($methodName);
        } elseif ($this->eloquentBuilderForwardsCallsExtension->hasMethod($builderReflection, $methodName)) {
            $reflection = $this->eloquentBuilderForwardsCallsExtension->getMethod($builderReflection, $methodName);
        } else {
            return null;
        }

        $parametersAcceptor = ParametersAcceptorSelector::selectSingle($reflection->getVariants());
        $returnType = $parametersAcceptor->getReturnType();

        $types = [$relatedModel];

        // BelongsTo relation needs second generic type
        if ((new ObjectType(BelongsTo::class))->isSuperTypeOf(new ObjectType($classReflection->getName()))->yes()) {
            $childType = $classReflection->getActiveTemplateTypeMap()->getType('TChildModel');

            if ($childType !== null) {
                $types[] = $childType;
            }
        }

        if ((new ObjectType(Builder::class))->isSuperTypeOf($returnType)->yes()) {
            return new EloquentBuilderMethodReflection(
                $methodName, $classReflection,
                $parametersAcceptor->getParameters(),
                new GenericObjectType($classReflection->getName(), $types),
                $parametersAcceptor->isVariadic()
            );
        }

        return new EloquentBuilderMethodReflection(
            $methodName, $classReflection,
            $parametersAcceptor->getParameters(),
            $returnType,
            $parametersAcceptor->isVariadic()
        );
    }
}
