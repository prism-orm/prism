<?php

declare(strict_types=1);

namespace Prism\Orm\Tests\Unit\Mapping\Fixtures;

use Prism\Orm\Collection\ArrayCollection;
use Prism\Orm\Collection\Collection;
use Prism\Orm\Mapping as Prism;
use Prism\Orm\Mapping\Cascade;
use Prism\Orm\Mapping\Order;
use Prism\Orm\Vector\Distance;

/**
 * Entité de démonstration utilisant la quasi-totalité des attributs de la 1.0.
 */
#[Prism\Entity(trackingPolicy: Prism\TrackingPolicy::Explicit)]
#[Prism\Table(name: 'invoice', options: ['engine' => 'InnoDB'])]
#[Prism\Index(fields: ['issuedAt'])]
#[Prism\Index(fields: ['status', 'issuedAt'], where: "status <> 'draft'")]
#[Prism\UniqueConstraint(fields: ['number'])]
#[Prism\Tenanted(strictForeignKeys: true)]
#[Prism\Auditable(exclude: ['updatedAt'], retention: 'P10Y')]
#[Prism\Timestampable]
#[Prism\Blameable]
#[Prism\SoftDeletable]
#[Prism\FullTextIndex(fields: ['number', 'notes'], language: 'french')]
#[Prism\HasLifecycleCallbacks]
#[Prism\EntityListeners([InvoiceListener::class])]
final class Invoice
{
    #[Prism\Id, Prism\GeneratedValue]
    public ?int $id = null;

    #[Prism\Version]
    public int $version = 1;

    #[Prism\Column(length: 20)]
    public string $number = '';

    #[Prism\Column]
    public FixtureStatus $status = FixtureStatus::Draft;

    #[Prism\Column(type: 'decimal', precision: 12, scale: 2)]
    public string $total = '0.00';

    #[Prism\Column(type: 'text', nullable: true)]
    public ?string $notes = null;

    #[Prism\Column(sensitive: true)]
    public ?string $iban = null;

    #[Prism\Column]
    public \DateTimeImmutable $issuedAt;

    #[Prism\Column]
    public \DateTimeImmutable $createdAt;

    #[Prism\Column(nullable: true)]
    public ?\DateTimeImmutable $updatedAt = null;

    #[Prism\Column(length: 120)]
    public string $createdBy = '';

    #[Prism\Column(length: 120, nullable: true)]
    public ?string $updatedBy = null;

    #[Prism\Column(nullable: true)]
    public ?\DateTimeImmutable $deletedAt = null;

    /** @var list<float> */
    #[Prism\Column(type: 'vector', dimensions: 1024, lazy: true)]
    #[Prism\VectorIndex(distance: Distance::Cosine, m: 16, efConstruction: 64)]
    #[Prism\EmbeddedVector(source: ['number', 'notes'])]
    public array $embedding = [];

    #[Prism\ManyToOne(inversedBy: 'invoices', cascade: [Cascade::Persist], batchSize: 200)]
    public Client $client;

    #[Prism\Embedded(columnPrefix: 'billing_')]
    public ?Address $billingAddress = null;

    #[Prism\OneToOne(targetEntity: self::class, joinColumn: 'credit_note_id', onDelete: Prism\OnDelete::SetNull)]
    public ?Invoice $creditNote = null;

    /** @var Collection<int, InvoiceLine> */
    #[Prism\OneToMany(targetEntity: InvoiceLine::class, mappedBy: 'invoice', cascade: [Cascade::Persist, Cascade::Remove], orphanRemoval: true)]
    #[Prism\OrderBy(['position' => Order::Asc])]
    public Collection $lines;

    /** @var Collection<int, Tag> */
    #[Prism\ManyToMany(targetEntity: Tag::class, inversedBy: 'invoices', batch: false)]
    #[Prism\JoinTable(name: 'invoice_tag', onDelete: Prism\OnDelete::Cascade)]
    public Collection $tags;

    public function __construct(Client $client, \DateTimeImmutable $issuedAt)
    {
        $this->lines = new ArrayCollection();
        $this->tags = new ArrayCollection();
        $this->client = $client;
        $this->issuedAt = $issuedAt;
        $this->createdAt = $issuedAt;
    }

    #[Prism\PrePersist]
    public function onPrePersist(): void
    {
    }

    #[Prism\PreUpdate]
    public function onPreUpdate(): void
    {
    }

    #[Prism\PostLoad]
    public function onPostLoad(): void
    {
    }

    #[Prism\PreRemove, Prism\PostRemove, Prism\PostUpdate]
    public function onOtherEvents(): void
    {
    }
}
