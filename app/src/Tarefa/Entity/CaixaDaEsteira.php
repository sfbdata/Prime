<?php

declare(strict_types=1);

namespace App\Tarefa\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use App\Tarefa\Enum\EtapaDaPasta;
use App\Tarefa\Repository\CaixaDaEsteiraRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * O que o escritório mudou numa caixa da esteira (modal "Configurar caixa", desenho 04 de 22/10, dc L133):
 * o nome da caixa, o nome da etapa e quem é avisado quando chega demanda nela. A esteira é única do
 * escritório, então a configuração também é. Caixa sem linha aqui é a padrão ("CAIXA N" e o nome do
 * catálogo); "Restaurar padrão" apaga a linha.
 */
#[ORM\Entity(repositoryClass: CaixaDaEsteiraRepository::class)]
#[ORM\Table(name: 'tarefa_caixa_esteira')]
#[ORM\UniqueConstraint(name: 'uniq_tarefa_caixa_esteira_tenant_etapa', columns: ['tenant_id', 'etapa'])]
class CaixaDaEsteira implements TenantAware
{
    public const TAMANHO_MAXIMO_DO_NOME = 60;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'nome_da_caixa', length: self::TAMANHO_MAXIMO_DO_NOME, nullable: true)]
    private ?string $nomeDaCaixa = null;

    #[ORM\Column(name: 'nome_da_etapa', length: self::TAMANHO_MAXIMO_DO_NOME, nullable: true)]
    private ?string $nomeDaEtapa = null;

    /** Quem é avisado quando chega demanda na caixa. Sem tenant próprio: o escopo vem da caixa. */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'tarefa_caixa_esteira_avisado')]
    #[ORM\JoinColumn(name: 'caixa_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'usuario_id', onDelete: 'CASCADE')]
    private Collection $avisados;

    #[ORM\Column(name: 'atualizado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $atualizadoEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        #[ORM\Column(length: 20, enumType: EtapaDaPasta::class)]
        private EtapaDaPasta $etapa,
    ) {
        $this->avisados = new ArrayCollection();
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    /**
     * Nome vazio volta ao padrão (dc L190: "nome vazio volta ao padrão"), por isso vira `null`.
     *
     * @param list<User> $avisados
     */
    public function configurar(?string $nomeDaCaixa, ?string $nomeDaEtapa, array $avisados): void
    {
        $this->nomeDaCaixa = self::nomeOuPadrao($nomeDaCaixa);
        $this->nomeDaEtapa = self::nomeOuPadrao($nomeDaEtapa);
        $this->avisados->clear();
        foreach ($avisados as $usuario) {
            if (!$this->avisados->contains($usuario)) {
                $this->avisados->add($usuario);
            }
        }
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    private static function nomeOuPadrao(?string $nome): ?string
    {
        $nome = trim((string) $nome);

        return $nome === '' ? null : $nome;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getEtapa(): EtapaDaPasta
    {
        return $this->etapa;
    }

    public function getNomeDaCaixa(): ?string
    {
        return $this->nomeDaCaixa;
    }

    public function getNomeDaEtapa(): ?string
    {
        return $this->nomeDaEtapa;
    }

    /** @return Collection<int, User> */
    public function getAvisados(): Collection
    {
        return $this->avisados;
    }
}
