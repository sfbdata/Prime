<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Entity;

use App\Entity\Auth\User;
use App\TrabalhoExterno\Repository\LinkDeVagasRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * O link público de "Compartilhar vagas" (spec VS 18.10.26 §4.2). O protótipo põe as vagas no
 * próprio endereço; aqui o endereço leva só um token aleatório, e é gravado só o hash dele (como o
 * convite): quem vê o banco não remonta o link, e o link não carrega dado nenhum.
 */
#[ORM\Entity(repositoryClass: LinkDeVagasRepository::class)]
#[ORM\Table(name: 'trabalho_externo_link_vagas')]
#[ORM\UniqueConstraint(name: 'uniq_te_link_vagas_token', columns: ['token_hash'])]
class LinkDeVagas
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $tokenHash;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    /** @param list<int> $vagas ids das vagas, na ordem em que foram escolhidas */
    private function __construct(
        string $token,
        #[ORM\Column(type: 'json')]
        private array $vagas,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $criadoPor,
        /** "Indicadas por …" da página: o nome de quem compartilhou. */
        #[ORM\Column(length: 150)]
        private string $indicadoPor,
    ) {
        $this->tokenHash = self::hash($token);
        $this->criadoEm = new \DateTimeImmutable();
    }

    /**
     * @param list<int> $vagas
     *
     * @return array{0: self, 1: string} o link e o token em claro (só existe neste momento)
     */
    public static function gerar(array $vagas, User $autor, string $indicadoPor): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return [new self($token, array_values(array_unique($vagas)), $autor, mb_substr($indicadoPor, 0, 150)), $token];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    /** @return list<int> */
    public function getVagas(): array
    {
        return $this->vagas;
    }

    public function getIndicadoPor(): string
    {
        return $this->indicadoPor;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }
}
