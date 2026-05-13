<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\ContractRepository;
use App\Entity\Trait\TimestampTrait;
use App\Entity\Trait\BlameableTrait;
use App\Entity\Embeddable\Money;

#[ORM\Entity(repositoryClass: ContractRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'contracts')]
class Contract
{
    use TimestampTrait;
    use BlameableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): self
    {
        $this->id = $id;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Offer::class, inversedBy: 'contracts')]
    #[ORM\JoinColumn(name: 'offer_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?Offer $offer = null;

    public function getOffer(): ?Offer
    {
        return $this->offer;
    }

    public function setOffer(?Offer $offer): self
    {
        $this->offer = $offer;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'applicant_id', referencedColumnName: 'id')]
    private ?User $applicant = null;

    public function getApplicant(): ?User
    {
        return $this->applicant;
    }

    public function setApplicant(?User $applicant): self
    {
        $this->applicant = $applicant;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'owner_id', referencedColumnName: 'id')]
    private ?User $owner = null;

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): self
    {
        $this->owner = $owner;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $terms = null;

    public function getTerms(): ?string
    {
        return $this->terms;
    }

    public function setTerms(?string $terms): self
    {
        $this->terms = $terms;
        return $this;
    }

    #[ORM\Embedded(class: Money::class, columnPrefix: 'money_')]
    private Money $budget;

    public function __construct()
    {
        $this->budget = new Money();
    }

    public function getBudget(): Money
    {
        return $this->budget;
    }

    public function setBudget(Money $budget): self
    {
        $this->budget = $budget;
        return $this;
    }

    public function getAmount(): ?string
    {
        return $this->budget->getAmount() !== 0 ? (string) ($this->budget->getAmount() / 100) : null;
    }

    public function setAmount(?string $amount): self
    {
        $cents = $amount !== null ? (int) round((float) $amount * 100) : 0;
        $this->budget = new Money($cents, $this->budget->getCurrency());
        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->budget->getCurrency();
    }

    public function setCurrency(?string $currency): self
    {
        $this->budget = new Money($this->budget->getAmount(), $currency ?? 'USD');
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $status = null;

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $risk_score = null;

    public function getRisk_score(): ?int
    {
        return $this->risk_score;
    }

    public function getRiskScore(): ?int
    {
        return $this->getRisk_score();
    }

    public function setRisk_score(?int $risk_score): self
    {
        $this->risk_score = $risk_score;
        return $this;
    }

    public function setRiskScore(?int $risk_score): self
    {
        return $this->setRisk_score($risk_score);
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $risk_factors = null;

    public function getRisk_factors(): ?string
    {
        return $this->risk_factors;
    }

    public function getRiskFactors(): ?string
    {
        return $this->getRisk_factors();
    }

    public function setRisk_factors(?string $risk_factors): self
    {
        $this->risk_factors = $risk_factors;
        return $this;
    }

    public function setRiskFactors(?string $risk_factors): self
    {
        return $this->setRisk_factors($risk_factors);
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $blockchain_hash = null;

    public function getBlockchain_hash(): ?string
    {
        return $this->blockchain_hash;
    }

    public function getBlockchainHash(): ?string
    {
        return $this->getBlockchain_hash();
    }

    public function setBlockchain_hash(?string $blockchain_hash): self
    {
        $this->blockchain_hash = $blockchain_hash;
        return $this;
    }

    public function setBlockchainHash(?string $blockchain_hash): self
    {
        return $this->setBlockchain_hash($blockchain_hash);
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $qr_code_url = null;

    public function getQr_code_url(): ?string
    {
        return $this->qr_code_url;
    }

    public function getQrCodeUrl(): ?string
    {
        return $this->getQr_code_url();
    }

    public function setQr_code_url(?string $qr_code_url): self
    {
        $this->qr_code_url = $qr_code_url;
        return $this;
    }

    public function setQrCodeUrl(?string $qr_code_url): self
    {
        return $this->setQr_code_url($qr_code_url);
    }

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $signed_at = null;

    public function getSigned_at(): ?\DateTimeInterface
    {
        return $this->signed_at;
    }

    public function getSignedAt(): ?\DateTimeInterface
    {
        return $this->getSigned_at();
    }

    /** @internal Timestamp — set once */
    public function initSigned_at(?\DateTimeInterface $signed_at): self
    {
        $this->signed_at = $signed_at;
        return $this;
    }

    public function initSignedAt(?\DateTimeInterface $signed_at): self
    {
        return $this->initSigned_at($signed_at);
    }

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $start_date = null;

    public function getStart_date(): ?\DateTimeInterface
    {
        return $this->start_date;
    }

    public function getStartDate(): ?\DateTimeInterface
    {
        return $this->getStart_date();
    }

    public function setStart_date(?\DateTimeInterface $start_date): self
    {
        $this->start_date = $start_date;
        return $this;
    }

    public function setStartDate(?\DateTimeInterface $start_date): self
    {
        return $this->setStart_date($start_date);
    }

    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $end_date = null;

    public function getEnd_date(): ?\DateTimeInterface
    {
        return $this->end_date;
    }

    public function getEndDate(): ?\DateTimeInterface
    {
        return $this->getEnd_date();
    }

    public function setEnd_date(?\DateTimeInterface $end_date): self
    {
        $this->end_date = $end_date;
        return $this;
    }

    public function setEndDate(?\DateTimeInterface $end_date): self
    {
        return $this->setEnd_date($end_date);
    }
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $signature_data = null;

    public function getSignature_data(): ?string
    {
        return $this->signature_data;
    }

    public function getSignatureData(): ?string
    {
        return $this->getSignature_data();
    }

    public function setSignature_data(?string $signature_data): self
    {
        $this->signature_data = $signature_data;
        return $this;
    }

    public function setSignatureData(?string $signature_data): self
    {
        return $this->setSignature_data($signature_data);
    }

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $signed_by_user_id = null;

    public function getSigned_by_user_id(): ?int
    {
        return $this->signed_by_user_id;
    }

    public function getSignedByUserId(): ?int
    {
        return $this->getSigned_by_user_id();
    }

    public function setSigned_by_user_id(?int $signed_by_user_id): self
    {
        $this->signed_by_user_id = $signed_by_user_id;
        return $this;
    }

    public function setSignedByUserId(?int $signed_by_user_id): self
    {
        return $this->setSigned_by_user_id($signed_by_user_id);
    }

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $counter_amount = null;

    public function getCounter_amount(): ?string
    {
        return $this->counter_amount;
    }

    public function getCounterAmount(): ?string
    {
        return $this->getCounter_amount();
    }

    public function setCounter_amount(?string $counter_amount): self
    {
        $this->counter_amount = $counter_amount;
        return $this;
    }

    public function setCounterAmount(?string $counter_amount): self
    {
        return $this->setCounter_amount($counter_amount);
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $counter_terms = null;

    public function getCounter_terms(): ?string
    {
        return $this->counter_terms;
    }

    public function getCounterTerms(): ?string
    {
        return $this->getCounter_terms();
    }

    public function setCounter_terms(?string $counter_terms): self
    {
        $this->counter_terms = $counter_terms;
        return $this;
    }

    public function setCounterTerms(?string $counter_terms): self
    {
        return $this->setCounter_terms($counter_terms);
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $negotiation_notes = null;

    public function getNegotiation_notes(): ?string
    {
        return $this->negotiation_notes;
    }

    public function getNegotiationNotes(): ?string
    {
        return $this->getNegotiation_notes();
    }

    public function setNegotiation_notes(?string $negotiation_notes): self
    {
        $this->negotiation_notes = $negotiation_notes;
        return $this;
    }

    public function setNegotiationNotes(?string $negotiation_notes): self
    {
        return $this->setNegotiation_notes($negotiation_notes);
    }

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $negotiation_round = null;

    public function getNegotiation_round(): ?int
    {
        return $this->negotiation_round;
    }

    public function getNegotiationRound(): ?int
    {
        return $this->getNegotiation_round();
    }

    public function setNegotiation_round(?int $negotiation_round): self
    {
        $this->negotiation_round = $negotiation_round;
        return $this;
    }

    public function setNegotiationRound(?int $negotiation_round): self
    {
        return $this->setNegotiation_round($negotiation_round);
    }

}
