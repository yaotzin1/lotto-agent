<?php

namespace App\Entity;

use App\Repository\TicketRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TicketRepository::class)]
class Ticket
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: "integer")]
    private ?int $id = null;

    #[ORM\Column(type: "json")]
    #[Assert\Count(min: 6, max: 6, exactMessage: "A ticket must have exactly 6 numbers.")]
    private array $numbers = [];

    #[ORM\Column(type: "datetime_immutable")]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: "json", nullable: true)]
    private ?array $drawResults = null;

    #[ORM\Column(type: "integer", nullable: true)]
    private ?int $matchCount = null;

    #[ORM\Column(type: "boolean", options: ["default" => false])]
    private bool $isSimulated = false;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumbers(): array
    {
        return $this->numbers;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function setNumbers(array $numbers): self
    {
        // Ensure numbers are unique and within range
        $validNumbers = array_filter(array_unique($numbers), function ($number) {
            return $number >= 1 && $number <= 49;
        });

        if (count($validNumbers) !== 6) {
            throw new \InvalidArgumentException('A ticket must have exactly 6 unique numbers between 1 and 49.');
        }

        sort($validNumbers);
        $this->numbers = $validNumbers;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getDrawResults(): ?array
    {
        return $this->drawResults;
    }

    public function setDrawResults(?array $drawResults): self
    {
        $this->drawResults = $drawResults;
        return $this;
    }

    public function getMatchCount(): ?int
    {
        return $this->matchCount;
    }

    public function setMatchCount(?int $matchCount): self
    {
        $this->matchCount = $matchCount;
        return $this;
    }

    public function isSimulated(): bool
    {
        return $this->isSimulated;
    }

    public function setIsSimulated(bool $isSimulated): self
    {
        $this->isSimulated = $isSimulated;
        return $this;
    }

    /**
     * Calculate the number of matching numbers between the ticket and draw results
     */
    public function calculateMatchCount(): int
    {
        if (!$this->drawResults) {
            return 0;
        }

        $matches = array_intersect($this->numbers, $this->drawResults);
        $this->matchCount = count($matches);

        return $this->matchCount;
    }
}
