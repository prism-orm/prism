<?php

declare(strict_types=1);

namespace Prism\Orm\Metadata;

/**
 * @api
 */
enum AssociationType
{
    case ManyToOne;
    case OneToOne;
    case OneToMany;
    case ManyToMany;

    public function isToOne(): bool
    {
        return self::ManyToOne === $this || self::OneToOne === $this;
    }

    public function isCollection(): bool
    {
        return !$this->isToOne();
    }
}
