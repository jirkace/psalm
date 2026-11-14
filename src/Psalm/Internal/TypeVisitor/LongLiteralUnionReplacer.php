<?php

declare(strict_types=1);

namespace Psalm\Internal\TypeVisitor;

use Override;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\MutableTypeVisitor;
use Psalm\Type\TypeNode;
use Psalm\Type\Union;

use function assert;
use function count;
use function implode;
use function preg_replace_callback;
use function sort;

/**
 * Replaces the literal ints (and strings) of a union that has more than three of them, at any depth of a type, by
 * a placeholder class: printing a union abbreviates such literals to `int` (`string`), so the printed type would
 * not parse back to the same type. {@see self::restore()} puts them back into the printed string.
 *
 * @internal
 */
final class LongLiteralUnionReplacer extends MutableTypeVisitor
{
    private const PLACEHOLDER = 'psalm_literals_';

    /**
     * @var list<string>
     */
    private array $literals = [];

    public function __construct(
        private readonly ?string $namespace,
        /** @var array<lowercase-string, string> */
        private readonly array $aliased_classes,
    ) {
    }

    /**
     * @return self::STOP_TRAVERSAL|self::DONT_TRAVERSE_CHILDREN|null
     * @psalm-capabilities read-props|write-this-props|write-refs
     */
    #[Override]
    protected function enterNode(TypeNode &$type): ?int
    {
        if (!$type instanceof Union) {
            return null;
        }

        $ints = [];
        $strings = [];
        $others = [];

        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TLiteralInt) {
                $ints[] = $atomic;
            } elseif ($atomic instanceof TLiteralString) {
                $strings[] = $atomic;
            } else {
                $others[] = $atomic;
            }
        }

        if (count($ints) <= 3 && count($strings) <= 3) {
            return null;
        }

        foreach ([$ints, $strings] as $literals) {
            if (count($literals) <= 3) {
                $others = [...$others, ...$literals];

                continue;
            }

            $printed = [];

            foreach ($literals as $literal) {
                $printed[] = $literal->toNamespacedString($this->namespace, $this->aliased_classes, null, false);
            }

            sort($printed);
            $this->literals[] = implode('|', $printed);
            $others[] = new TNamedObject(self::PLACEHOLDER . (count($this->literals) - 1));
        }

        assert($others !== []);

        $type = $type->setTypes($others);

        return null;
    }

    /**
     * @psalm-capabilities read-props
     */
    public function restore(string $printed_type): string
    {
        $literals = $this->literals;

        return (string) preg_replace_callback(
            '/\\\\?' . self::PLACEHOLDER . '(\d+)/',
            static fn(array $match): string => $literals[(int) ($match[1] ?? -1)] ?? ($match[0] ?? ''),
            $printed_type,
        );
    }
}
