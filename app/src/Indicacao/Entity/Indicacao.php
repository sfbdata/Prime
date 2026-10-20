<?php

declare(strict_types=1);

namespace App\Indicacao\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Indicacao\Enum\CanalDeIndicacao;
use App\Indicacao\Repository\IndicacaoRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma indicação do BlueJus (spec `indicar-bluejus.md`, modelo de dados). Dado de plataforma: sem tenant (o escritório
 * de origem é só para mostrar ao Master e vira NULL se o escritório for purgado).
 *
 * Índice funcional `idx_indicacao_email_lower` em `lower(email)`: criado por SQL cru na migration, porque o Doctrine
 * não representa índice de expressão no mapeamento. Se um `make:migration` propuser um `DROP INDEX` dele,
 * esse DROP é ruído e sai do arquivo gerado (skill `criar-migration`).
 */
#[ORM\Entity(repositoryClass: IndicacaoRepository::class)]
#[ORM\Table(name: 'indicacao')]
#[ORM\Index(name: 'idx_indicacao_por_criada', columns: ['indicado_por_id', 'criada_em'])]
class Indicacao
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'indicado_por_id', nullable: false, onDelete: 'CASCADE')]
        private User $indicadoPor,
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_origem_id', nullable: true, onDelete: 'SET NULL')]
        private ?Tenant $tenantOrigem,
        #[ORM\Column(length: 10, enumType: CanalDeIndicacao::class)]
        private CanalDeIndicacao $canal,
        #[ORM\Column(name: 'criada_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $criadaEm,
        #[ORM\Column(length: 180, nullable: true)]
        private ?string $email = null,
        #[ORM\Column(length: 120, nullable: true)]
        private ?string $nome = null,
    ) {
        $this->exigirCoerenciaDoCanal();
    }

    public static function porEmail(User $por, ?Tenant $origem, string $email, ?string $nome, \DateTimeImmutable $em): self
    {
        $nome = $nome === null || trim($nome) === '' ? null : mb_substr(trim($nome), 0, 120);

        return new self($por, $origem, CanalDeIndicacao::Email, $em, mb_strtolower(trim($email)), $nome);
    }

    public static function porCanal(User $por, ?Tenant $origem, CanalDeIndicacao $canal, \DateTimeImmutable $em): self
    {
        return new self($por, $origem, $canal, $em);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIndicadoPor(): User
    {
        return $this->indicadoPor;
    }

    public function getTenantOrigem(): ?Tenant
    {
        return $this->tenantOrigem;
    }

    public function getCanal(): CanalDeIndicacao
    {
        return $this->canal;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function getNome(): ?string
    {
        return $this->nome;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    /** E-mail e nome só existem no canal e-mail; nos outros a indicação é só a contagem. */
    private function exigirCoerenciaDoCanal(): void
    {
        $porEmail = $this->canal === CanalDeIndicacao::Email;
        if ($porEmail && ($this->email === null || $this->email === '')) {
            throw new \InvalidArgumentException('Indicação por e-mail exige o endereço.');
        }
        if (!$porEmail && ($this->email !== null || $this->nome !== null)) {
            throw new \InvalidArgumentException('Só a indicação por e-mail guarda endereço e nome.');
        }
    }
}
