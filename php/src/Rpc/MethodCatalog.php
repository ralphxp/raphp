<?php

declare(strict_types=1);

namespace Raphp\Rpc;

use InvalidArgumentException;
use LogicException;
use Raphp\Ipc\Message\Validation;
use Raphp\Rpc\Attribute\Idempotent;
use Raphp\Rpc\Attribute\RpcMethod;
use ReflectionClass;
use ReflectionMethod;

/**
 * The RPC methods that classes declare with `#[RpcMethod]`, read from the attributes alone: no
 * object is needed. A process that only calls a method can build the same catalog from the
 * shared contract class to learn which methods are `#[Idempotent]`.
 *
 * Mistakes are refused when the catalog is built, not when a call arrives: a duplicate name, an
 * empty or too long name, an attribute on a method that is not public or is static, and
 * `#[Idempotent]` on a method that is not an RPC method.
 */
final readonly class MethodCatalog
{
    /**
     * @param array<string, MethodDescriptor> $methods by name
     */
    private function __construct(private array $methods) {}

    /**
     * @param list<string> $classes
     *
     * @throws LogicException when an attribute is misused or a name is used twice
     */
    public static function fromClasses(array $classes): self
    {
        $methods = [];

        foreach ($classes as $class) {
            if (!class_exists($class)) {
                throw new LogicException("the class {$class} does not exist");
            }

            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods() as $method) {
                $descriptor = self::describe($reflection, $method);
                if ($descriptor === null) {
                    continue;
                }

                $earlier = $methods[$descriptor->name] ?? null;
                if ($earlier !== null) {
                    throw new LogicException("the RPC method \"{$descriptor->name}\" is declared twice: by {$earlier->class}::{$earlier->method}() and by {$descriptor->class}::{$descriptor->method}()");
                }

                $methods[$descriptor->name] = $descriptor;
            }
        }

        return new self($methods);
    }

    public function has(string $name): bool
    {
        return isset($this->methods[$name]);
    }

    public function get(string $name): ?MethodDescriptor
    {
        return $this->methods[$name] ?? null;
    }

    /**
     * Whether the method is declared `#[Idempotent]`. False for a method that is unknown.
     */
    public function isIdempotent(string $name): bool
    {
        return ($this->methods[$name] ?? null)?->idempotent === true;
    }

    /**
     * @return list<MethodDescriptor>
     */
    public function all(): array
    {
        return array_values($this->methods);
    }

    /**
     * @param ReflectionClass<object> $class
     */
    private static function describe(ReflectionClass $class, ReflectionMethod $method): ?MethodDescriptor
    {
        // A method inherited from a parent is described under the class it was declared in once only.
        if ($method->getDeclaringClass()->getName() !== $class->getName()) {
            return null;
        }

        $where = "{$class->getName()}::{$method->getName()}()";
        $rpc = $method->getAttributes(RpcMethod::class);
        $idempotent = $method->getAttributes(Idempotent::class) !== [];

        if ($rpc === []) {
            if ($idempotent) {
                throw new LogicException("{$where} is marked #[Idempotent] but not #[RpcMethod]");
            }

            return null;
        }

        if (!$method->isPublic() || $method->isStatic()) {
            throw new LogicException("{$where} is marked #[RpcMethod] but is not a public instance method");
        }

        $name = $rpc[0]->newInstance()->name;

        try {
            Validation::method($name);
        } catch (InvalidArgumentException $exception) {
            throw new LogicException("{$where} has an invalid RPC method name: {$exception->getMessage()}", 0, $exception);
        }

        return new MethodDescriptor($name, $class->getName(), $method->getName(), $idempotent);
    }
}
