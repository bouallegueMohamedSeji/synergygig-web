<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\TrainingCertificateRepository;

#[ORM\Entity(repositoryClass: TrainingCertificateRepository::class)]
#[ORM\Table(name: 'training_certificates')]
class TrainingCertificate
{
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

    #[ORM\ManyToOne(targetEntity: TrainingEnrollment::class, inversedBy: 'certificates')]
    #[ORM\JoinColumn(name: 'enrollment_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?TrainingEnrollment $enrollment = null;

    public function getEnrollment(): ?TrainingEnrollment
    {
        return $this->enrollment;
    }

    public function setEnrollment(?TrainingEnrollment $enrollment): self
    {
        $this->enrollment = $enrollment;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id')]
    private ?User $user = null;

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: TrainingCourse::class, inversedBy: 'certificates')]
    #[ORM\JoinColumn(name: 'course_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?TrainingCourse $course = null;

    public function getCourse(): ?TrainingCourse
    {
        return $this->course;
    }

    public function setCourse(?TrainingCourse $course): self
    {
        $this->course = $course;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $certificate_number = null;

    public function getCertificate_number(): ?string
    {
        return $this->certificate_number;
    }

    public function getCertificateNumber(): ?string
    {
        return $this->getCertificate_number();
    }

    public function setCertificate_number(string $certificate_number): self
    {
        $this->certificate_number = $certificate_number;
        return $this;
    }

    public function setCertificateNumber(string $certificate_number): self
    {
        return $this->setCertificate_number($certificate_number);
    }

    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?\DateTimeInterface $issued_at = null;

    public function getIssued_at(): ?\DateTimeInterface
    {
        return $this->issued_at;
    }

    public function getIssuedAt(): ?\DateTimeInterface
    {
        return $this->getIssued_at();
    }

    /** @internal Timestamp — set once */
    public function initIssued_at(\DateTimeInterface $issued_at): self
    {
        $this->issued_at = $issued_at;
        return $this;
    }

    public function initIssuedAt(\DateTimeInterface $issued_at): self
    {
        return $this->initIssued_at($issued_at);
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

}
