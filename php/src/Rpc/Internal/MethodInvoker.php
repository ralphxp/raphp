<?php

declare(strict_types=1);

namespace Raphp\Rpc\Internal;

use Amp\Cancellation;
use Amp\NullCancellation;
use LogicException;
use Raphp\Error\InvalidParamsException;
use Raphp\Error\Text;
use Raphp\Rpc\ProgressReporter;
use Raphp\Rpc\RpcRequest;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;

/**
 * Calls a method of a service for an incoming RPC request: each parameter is filled from the
 * request by name and type, and a call that does not fit the method is answered with
 * `E_INVALID_PARAMS` before the method runs.
 *
 * What is understood is decided when the invoker is built, and anything else is refused then (a
 * `LogicException`), so a method that could never be called correctly is found at boot:
 * - `RpcRequest`, `Cancellation` and `ProgressReporter` are given by the framework;
 * - every other parameter takes a value from `params` of the same name, typed with `int`,
 *   `float`, `string`, `bool`, `array`, `mixed`, a union of these, or `null` allowed;
 * - a parameter with a default may be left out; any other must be present;
 * - a name in `params` that no parameter has is an error (a typo must not pass silently).
 *
 * @internal
 */
final class MethodInvoker
{
    private const array PARAM_TYPES = ['int', 'float', 'string', 'bool', 'array', 'mixed', 'null'];

    /**
     * @param list<ParameterBinding> $bindings
     */
    private function __construct(
        private readonly object $service,
        private readonly ReflectionMethod $method,
        private readonly array $bindings,
    ) {}

    /**
     * @throws LogicException when a parameter cannot be filled from an RPC call
     */
    public static function create(object $service, ReflectionMethod $method): self
    {
        $bindings = [];
        foreach ($method->getParameters() as $parameter) {
            $bindings[] = self::bind($method, $parameter);
        }

        return new self($service, $method, $bindings);
    }

    /**
     * @throws InvalidParamsException when the call does not fit the method
     */
    public function __invoke(RpcRequest $request, ?Cancellation $cancellation = null): mixed
    {
        $given = $request->params;
        $arguments = [];

        foreach ($this->bindings as $binding) {
            $arguments[] = match ($binding->kind) {
                BindingKind::Request => $request,
                BindingKind::Cancellation => $cancellation ?? ($binding->nullable ? null : new NullCancellation()),
                BindingKind::Progress => $request->progress,
                BindingKind::Param => $this->argumentFor($binding, $given),
            };
        }

        if ($given !== []) {
            $name = Text::truncate((string) array_key_first($given), 64);

            throw new InvalidParamsException("unknown parameter \"{$name}\"", ['param' => $name]);
        }

        return $this->method->invokeArgs($this->service, $arguments);
    }

    /**
     * Takes the parameter's value out of what was given.
     *
     * @param array<array-key, mixed> $given
     *
     * @throws InvalidParamsException
     */
    private function argumentFor(ParameterBinding $binding, array &$given): mixed
    {
        if (!array_key_exists($binding->name, $given)) {
            if ($binding->hasDefault) {
                return $binding->default;
            }

            throw new InvalidParamsException("\"{$binding->name}\" is required", ['param' => $binding->name]);
        }

        $value = $given[$binding->name];
        unset($given[$binding->name]);

        if (!$binding->accepts($value)) {
            throw new InvalidParamsException("\"{$binding->name}\" must be of type {$binding->describeTypes()}", ['param' => $binding->name]);
        }

        return $value;
    }

    private static function bind(ReflectionMethod $method, ReflectionParameter $parameter): ParameterBinding
    {
        $where = "{$method->getDeclaringClass()->getName()}::{$method->getName()}(), parameter \${$parameter->getName()}";

        if ($parameter->isVariadic() || $parameter->isPassedByReference()) {
            throw new LogicException("{$where}: a variadic or by-reference parameter cannot be filled from an RPC call");
        }

        $type = $parameter->getType();
        $names = match (true) {
            $type === null => ['mixed'],
            $type instanceof ReflectionNamedType => [$type->getName()],
            $type instanceof ReflectionUnionType => array_map(static fn(ReflectionNamedType|\ReflectionIntersectionType $part): string => $part instanceof ReflectionNamedType ? $part->getName() : '', $type->getTypes()),
            default => throw new LogicException("{$where}: an intersection type cannot be filled from an RPC call"),
        };

        $nullable = $type === null || $type->allowsNull();
        $hasDefault = $parameter->isDefaultValueAvailable();
        $default = $hasDefault ? $parameter->getDefaultValue() : null;

        if (count($names) === 1) {
            $kind = match ($names[0]) {
                RpcRequest::class => BindingKind::Request,
                Cancellation::class => BindingKind::Cancellation,
                ProgressReporter::class => BindingKind::Progress,
                default => null,
            };

            if ($kind !== null) {
                return new ParameterBinding($parameter->getName(), $kind, [], $hasDefault, $default, $nullable);
            }
        }

        if ($nullable && !in_array('null', $names, true) && !in_array('mixed', $names, true)) {
            $names[] = 'null';
        }

        foreach ($names as $name) {
            if (!in_array($name, self::PARAM_TYPES, true)) {
                throw new LogicException("{$where}: the type \"{$name}\" cannot be filled from an RPC call (use int, float, string, bool, array, mixed, or RpcRequest, Cancellation, ProgressReporter)");
            }
        }

        return new ParameterBinding($parameter->getName(), BindingKind::Param, array_values($names), $hasDefault, $default, $nullable);
    }
}
