<?php

declare(strict_types=1);

namespace Dregs\Model;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * An identity's four category scores.
 *
 * Iterate it, count it, or ask for a category by name:
 *
 * ```php
 * $scores = $client->identities->scores('user_12345');
 *
 * if ($scores->authenticity() !== null && $scores->authenticity() < 40) {
 *     holdForReview('user_12345');
 * }
 *
 * foreach ($scores as $score) {
 *     echo $score->category?->value, ': ', $score->value, PHP_EOL;
 * }
 * ```
 *
 * A category Dregs has not scored yet is absent from the collection, and its named accessor
 * returns null. Treat that as "no opinion" rather than as a zero: a brand-new identity comes
 * back empty, and refusing a signup on an absent score would refuse every signup.
 *
 * @implements IteratorAggregate<int, Score>
 */
final readonly class Scores implements Countable, IteratorAggregate
{
    /**
     * @param list<Score> $items The scores Dregs has, in the order the API returned them.
     */
    public function __construct(public array $items = [])
    {
    }

    /**
     * Returns every score, as a plain list.
     *
     * @return list<Score>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * How many categories have been scored. Between 0 and 4.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Whether nothing has been scored yet, which is the normal state of a new identity.
     */
    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * @return Traversable<int, Score>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * Returns the whole {@see Score} for a category, or null when it has not been scored.
     *
     * Use this when you want the observations alongside the number, on a result from
     * {@see \Dregs\Identities::analysis()}.
     */
    public function get(Category $category): ?Score
    {
        foreach ($this->items as $score) {
            if ($score->category === $category) {
                return $score;
            }
        }

        return null;
    }

    /**
     * How likely it is that a person, rather than a script, is behind the account.
     *
     * @return int|null 0 to 100, or null when the category has not been scored.
     */
    public function humanity(): ?int
    {
        return $this->get(Category::Humanity)?->value;
    }

    /**
     * How genuine the details on the account look.
     *
     * @return int|null 0 to 100, or null when the category has not been scored.
     */
    public function authenticity(): ?int
    {
        return $this->get(Category::Authenticity)?->value;
    }

    /**
     * How distinct the account is from others in the same tenant.
     *
     * @return int|null 0 to 100, or null when the category has not been scored.
     */
    public function uniqueness(): ?int
    {
        return $this->get(Category::Uniqueness)?->value;
    }

    /**
     * How ordinary the account's activity looks.
     *
     * @return int|null 0 to 100, or null when the category has not been scored.
     */
    public function behavior(): ?int
    {
        return $this->get(Category::Behavior)?->value;
    }

    /**
     * Builds the collection from a decoded `GET /api/identities/{id}/scores` response.
     *
     * @param list<array<string, mixed>> $payload
     */
    public static function fromApi(array $payload): self
    {
        $items = [];

        foreach ($payload as $score) {
            $items[] = Score::fromApi($score);
        }

        return new self($items);
    }
}
