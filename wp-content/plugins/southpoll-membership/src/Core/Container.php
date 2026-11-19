<?php
/**
 * Minimal autowiring service container.
 *
 * Resolves classes by name, injecting constructor dependencies by type-hint. Every resolved
 * object is a singleton for the request. Small on purpose: no interfaces-to-implementation
 * binding beyond bind(), no scopes.
 *
 * @package SouthPoll\Membership
 */

declare( strict_types=1 );

namespace SouthPoll\Membership\Core;

use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

final class Container {

	/** @var array<string, object> */
	private array $instances = [];

	/** @var array<string, callable|string> */
	private array $bindings = [];

	/** @var array<string, bool> Guards against circular dependencies. */
	private array $resolving = [];

	/**
	 * Bind an id (usually an interface or abstract) to a concrete class name or factory callable.
	 */
	public function bind( string $id, callable|string $concrete ): void {
		$this->bindings[ $id ] = $concrete;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Register an existing instance.
	 */
	public function instance( string $id, object $object ): void {
		$this->instances[ $id ] = $object;
	}

	public function has( string $id ): bool {
		return isset( $this->instances[ $id ] ) || isset( $this->bindings[ $id ] ) || class_exists( $id );
	}

	/**
	 * Resolve (and cache) an object by id.
	 *
	 * @template T of object
	 * @param class-string<T> $id
	 * @return T
	 */
	public function get( string $id ): object {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}
		if ( isset( $this->resolving[ $id ] ) ) {
			throw new RuntimeException( "Circular dependency while resolving {$id}" );
		}
		$this->resolving[ $id ] = true;
		try {
			$concrete = $this->bindings[ $id ] ?? $id;
			$object   = is_callable( $concrete ) ? $concrete( $this ) : $this->build( (string) $concrete );
		} finally {
			unset( $this->resolving[ $id ] );
		}
		$this->instances[ $id ] = $object;
		return $object;
	}

	/**
	 * Instantiate a class, autowiring constructor parameters that are type-hinted with classes.
	 */
	private function build( string $class ): object {
		if ( ! class_exists( $class ) ) {
			throw new RuntimeException( "Cannot resolve unknown class {$class}" );
		}
		$ref  = new ReflectionClass( $class );
		$ctor = $ref->getConstructor();
		if ( null === $ctor || 0 === $ctor->getNumberOfParameters() ) {
			return new $class();
		}
		$args = [];
		foreach ( $ctor->getParameters() as $param ) {
			$type = $param->getType();
			if ( $type instanceof ReflectionNamedType && ! $type->isBuiltin() ) {
				$args[] = $this->get( $type->getName() );
				continue;
			}
			if ( $param->isDefaultValueAvailable() ) {
				$args[] = $param->getDefaultValue();
				continue;
			}
			if ( $param->allowsNull() ) {
				$args[] = null;
				continue;
			}
			throw new RuntimeException( "Cannot autowire parameter \${$param->getName()} of {$class}" );
		}
		return $ref->newInstanceArgs( $args );
	}
}
