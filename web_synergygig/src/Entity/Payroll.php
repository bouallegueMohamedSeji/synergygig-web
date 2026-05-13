<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

use App\Repository\PayrollRepository;

#[ORM\Entity(repositoryClass: PayrollRepository::class)]
#[ORM\Table(name: 'payrolls')]
class Payroll
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

    #[ORM\Column(type: 'integer', nullable: false)]
    private ?int $month = null;

    public function getMonth(): ?int
    {
        return $this->month;
    }

    public function setMonth(int $month): self
    {
        $this->month = $month;
        return $this;
    }

    #[ORM\Column(type: 'integer', nullable: false)]
    private ?int $year = null;

    public function getYear(): ?int
    {
        return $this->year;
    }

    public function setYear(int $year): self
    {
        $this->year = $year;
        return $this;
    }

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $amount = null;

    public function getAmount(): ?string
    {
        return $this->amount;
    }

    public function setAmount(?string $amount): self
    {
        $this->amount = $amount;
        return $this;
    }

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $base_salary = null;

    public function getBase_salary(): ?string
    {
        return $this->base_salary;
    }

    public function getBaseSalary(): ?string
    {
        return $this->getBase_salary();
    }

    public function setBase_salary(?string $base_salary): self
    {
        $this->base_salary = $base_salary;
        return $this;
    }

    public function setBaseSalary(?string $base_salary): self
    {
        return $this->setBase_salary($base_salary);
    }

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $bonus = null;

    public function getBonus(): ?string
    {
        return $this->bonus;
    }

    public function setBonus(?string $bonus): self
    {
        $this->bonus = $bonus;
        return $this;
    }

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $deductions = null;

    public function getDeductions(): ?string
    {
        return $this->deductions;
    }

    public function setDeductions(?string $deductions): self
    {
        $this->deductions = $deductions;
        return $this;
    }

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    private ?string $net_salary = null;

    public function getNet_salary(): ?string
    {
        return $this->net_salary;
    }

    public function getNetSalary(): ?string
    {
        return $this->getNet_salary();
    }

    public function setNet_salary(?string $net_salary): self
    {
        $this->net_salary = $net_salary;
        return $this;
    }

    public function setNetSalary(?string $net_salary): self
    {
        return $this->setNet_salary($net_salary);
    }

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $total_hours_worked = null;

    public function getTotal_hours_worked(): ?float
    {
        return $this->total_hours_worked;
    }

    public function getTotalHoursWorked(): ?float
    {
        return $this->getTotal_hours_worked();
    }

    public function setTotal_hours_worked(?float $total_hours_worked): self
    {
        $this->total_hours_worked = $total_hours_worked;
        return $this;
    }

    public function setTotalHoursWorked(?float $total_hours_worked): self
    {
        return $this->setTotal_hours_worked($total_hours_worked);
    }

    #[ORM\Column(type: 'float', nullable: true)]
    private ?float $hourly_rate = null;

    public function getHourly_rate(): ?float
    {
        return $this->hourly_rate;
    }

    public function getHourlyRate(): ?float
    {
        return $this->getHourly_rate();
    }

    public function setHourly_rate(?float $hourly_rate): self
    {
        $this->hourly_rate = $hourly_rate;
        return $this;
    }

    public function setHourlyRate(?float $hourly_rate): self
    {
        return $this->setHourly_rate($hourly_rate);
    }

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $status = null;

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status;
        return $this;
    }

    #[ORM\Column(type: 'datetime', nullable: false)]
    private ?\DateTimeInterface $generated_at = null;

    public function getGenerated_at(): ?\DateTimeInterface
    {
        return $this->generated_at;
    }

    public function getGeneratedAt(): ?\DateTimeInterface
    {
        return $this->getGenerated_at();
    }

    /** @internal Timestamp — set once */
    public function initGenerated_at(\DateTimeInterface $generated_at): self
    {
        $this->generated_at = $generated_at;
        return $this;
    }

    public function initGeneratedAt(\DateTimeInterface $generated_at): self
    {
        return $this->initGenerated_at($generated_at);
    }

}
