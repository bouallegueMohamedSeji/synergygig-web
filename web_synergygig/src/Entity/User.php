<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Attribute\Ignore;

use App\Repository\UserRepository;
use App\Entity\Trait\TimestampTrait;
use App\Entity\Trait\BlameableTrait;
use App\Entity\Embeddable\Email;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: 'app_user')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
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

    #[ORM\Embedded(class: Email::class, columnPrefix: 'email_address_')]
    private ?Email $emailAddress = null;

    public function getEmailAddress(): ?Email
    {
        return $this->emailAddress;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $email = null;

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    #[Ignore]
    private ?string $password = null;

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $first_name = null;

    public function getFirst_name(): ?string
    {
        return $this->first_name;
    }

    public function getFirstName(): ?string
    {
        return $this->first_name;
    }

    public function setFirst_name(string $first_name): self
    {
        $this->first_name = $first_name;
        return $this;
    }

    public function setFirstName(string $first_name): self
    {
        $this->first_name = $first_name;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: false)]
    private ?string $last_name = null;

    public function getLast_name(): ?string
    {
        return $this->last_name;
    }

    public function getLastName(): ?string
    {
        return $this->last_name;
    }

    public function setLast_name(string $last_name): self
    {
        $this->last_name = $last_name;
        return $this;
    }

    public function setLastName(string $last_name): self
    {
        $this->last_name = $last_name;
        return $this;
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $role = null;

    public function getRole(): ?string
    {
        return $this->role;
    }

    public function setRole(?string $role): self
    {
        $this->role = $role;
        return $this;
    }
    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $avatar_path = null;

    public function getAvatar_path(): ?string
    {
        return $this->avatar_path;
    }

    public function getAvatarPath(): ?string
    {
        return $this->avatar_path;
    }

    public function setAvatar_path(?string $avatar_path): self
    {
        $this->avatar_path = $avatar_path;
        return $this;
    }

    public function setAvatarPath(?string $avatar_path): self
    {
        $this->avatar_path = $avatar_path;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $face_encoding = null;

    public function getFace_encoding(): ?string
    {
        return $this->face_encoding;
    }

    public function getFaceEncoding(): ?string
    {
        return $this->face_encoding;
    }

    public function setFace_encoding(?string $face_encoding): self
    {
        $this->face_encoding = $face_encoding;
        return $this;
    }

    public function setFaceEncoding(?string $face_encoding): self
    {
        $this->face_encoding = $face_encoding;
        return $this;
    }

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $is_online = null;

    public function is_online(): ?bool
    {
        return $this->is_online;
    }

    public function isOnline(): ?bool
    {
        return $this->is_online;
    }

    public function setIs_online(?bool $is_online): self
    {
        $this->is_online = $is_online;
        return $this;
    }

    public function setIsOnline(?bool $is_online): self
    {
        $this->is_online = $is_online;
        return $this;
    }

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $is_verified = null;

    public function is_verified(): ?bool
    {
        return $this->is_verified;
    }

    public function isVerified(): bool
    {
        return $this->is_verified === true;
    }

    public function setIs_verified(?bool $is_verified): self
    {
        $this->is_verified = $is_verified;
        return $this;
    }

    public function setIsVerified(?bool $is_verified): self
    {
        $this->is_verified = $is_verified;
        return $this;
    }

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $is_active = null;

    public function is_active(): ?bool
    {
        return $this->is_active;
    }

    public function isActive(): ?bool
    {
        return $this->is_active;
    }

    public function setIs_active(?bool $is_active): self
    {
        $this->is_active = $is_active;
        return $this;
    }

    public function setIsActive(?bool $is_active): self
    {
        $this->is_active = $is_active;
        return $this;
    }

    #[ORM\ManyToOne(targetEntity: Department::class)]
    #[ORM\JoinColumn(name: 'department_id', referencedColumnName: 'id')]
    private ?Department $department = null;

    public function getDepartment(): ?Department
    {
        return $this->department;
    }

    public function setDepartment(?Department $department): self
    {
        $this->department = $department;
        return $this;
    }

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $hourly_rate = null;

    public function getHourly_rate(): ?float
    {
        return $this->hourly_rate;
    }

    public function getHourlyRate(): ?float
    {
        return $this->hourly_rate;
    }

    public function setHourly_rate(?float $hourly_rate): self
    {
        $this->hourly_rate = $hourly_rate;
        return $this;
    }

    public function setHourlyRate(?float $hourly_rate): self
    {
        $this->hourly_rate = $hourly_rate;
        return $this;
    }

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $monthly_salary = null;

    public function getMonthly_salary(): ?string
    {
        return $this->monthly_salary;
    }

    public function getMonthlySalary(): ?string
    {
        return $this->monthly_salary;
    }

    public function setMonthly_salary(?string $monthly_salary): self
    {
        $this->monthly_salary = $monthly_salary;
        return $this;
    }

    public function setMonthlySalary(?string $monthly_salary): self
    {
        $this->monthly_salary = $monthly_salary;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $bio = null;

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): self
    {
        $this->bio = $bio;
        return $this;
    }

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $cover_base64 = null;

    public function getCover_base64(): ?string
    {
        return $this->cover_base64;
    }

    public function getCoverBase64(): ?string
    {
        return $this->cover_base64;
    }

    public function setCover_base64(?string $cover_base64): self
    {
        $this->cover_base64 = $cover_base64;
        return $this;
    }

    public function setCoverBase64(?string $cover_base64): self
    {
        $this->cover_base64 = $cover_base64;
        return $this;
    }

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    #[Ignore]
    private ?string $reset_token = null;

    public function getResetToken(): ?string
    {
        return $this->reset_token;
    }

    public function setResetToken(?string $reset_token): self
    {
        $this->reset_token = $reset_token;
        return $this;
    }

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $reset_token_expires_at = null;

    public function getResetTokenExpiresAt(): ?\DateTimeInterface
    {
        return $this->reset_token_expires_at;
    }

    /** @internal Timestamp — set once */
    public function initResetTokenExpiresAt(?\DateTimeInterface $reset_token_expires_at): self
    {
        $this->reset_token_expires_at = $reset_token_expires_at;
        return $this;
    }

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $last_seen_at = null;

    public function getLastSeenAt(): ?\DateTimeInterface { return $this->last_seen_at; }
    /** @internal Timestamp */ public function initLastSeenAt(?\DateTimeInterface $v): self { $this->last_seen_at = $v; return $this; }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $online_status = null; // online | away | offline

    public function getOnlineStatus(): ?string { return $this->online_status; }
    public function setOnlineStatus(?string $v): self { $this->online_status = $v; return $this; }

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $google_id = null;

    public function getGoogleId(): ?string { return $this->google_id; }
    public function setGoogleId(?string $v): self { $this->google_id = $v; return $this; }

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $github_id = null;

    public function getGithubId(): ?string { return $this->github_id; }
    public function setGithubId(?string $v): self { $this->github_id = $v; return $this; }

    // ── CV / Resume ──

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $cv_path = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $cv_original_name = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $cv_uploaded_at = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $cv_skills_text = null;

    public function getCvPath(): ?string { return $this->cv_path; }
    public function setCvPath(?string $v): self { $this->cv_path = $v; return $this; }

    public function getCvOriginalName(): ?string { return $this->cv_original_name; }
    public function setCvOriginalName(?string $v): self { $this->cv_original_name = $v; return $this; }

    public function getCvUploadedAt(): ?\DateTimeInterface { return $this->cv_uploaded_at; }
    /** @internal Timestamp */ public function initCvUploadedAt(?\DateTimeInterface $v): self { $this->cv_uploaded_at = $v; return $this; }

    public function getCvSkillsText(): ?string { return $this->cv_skills_text; }
    public function setCvSkillsText(?string $v): self { $this->cv_skills_text = $v; return $this; }

    // ── UserInterface methods ──

    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    public function getRoles(): array
    {
        $role = $this->role ?? 'EMPLOYEE';
        $mapped = match (strtoupper($role)) {
            'ADMIN' => 'ROLE_ADMIN',
            'HR_MANAGER', 'HR' => 'ROLE_HR',
            'PROJECT_OWNER', 'MANAGER' => 'ROLE_PROJECT_OWNER',
            'GIG_WORKER' => 'ROLE_GIG_WORKER',
            'EMPLOYEE' => 'ROLE_EMPLOYEE',
            default => 'ROLE_USER',
        };
        return [$mapped];
    }

    public function eraseCredentials(): void
    {
    }
}
