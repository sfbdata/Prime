<?php

declare(strict_types=1);

namespace App\Voto\Entity;

use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use App\Voto\Repository\OpcaoDaPautaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Opção de voto de uma pauta (spec docs/specs/voto-assembleia-condominio.md, seções 2 e 3), com o contador de votos.
 * A abstenção é uma opção com `abstencao = true` e não conta como voto válido.
 *
 * SIGILO (seção 2): o voto só soma no contador (`total_unidades`, `total_peso`) por SQL direto, na mesma transação do
 * INSERT da participação ({@see \App\Voto\Repository\OpcaoDaPautaRepository::somarVoto()}). Por isso esta entidade
 * NÃO é `Auditavel` e não tem método que mude o contador: o audit_log guardaria a hora da mudança e ligaria o voto à
 * participação da mesma hora. Depois de um voto, a instância em memória fica com o contador antigo; quem precisar do
 * número atual relê do banco.
 *
 * O tenant vem da pauta (nunca de fora).
 */
#[ORM\Entity(repositoryClass: OpcaoDaPautaRepository::class)]
#[ORM\Table(name: 'assembleia_opcao', schema: 'voto')]
class OpcaoDaPauta implements TenantAware
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Tenant $tenant;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $totalUnidades = 0;

    #[ORM\Column(type: 'decimal', precision: 14, scale: 6, options: ['default' => 0])]
    private string $totalPeso = '0.000000';

    public function __construct(
        #[ORM\ManyToOne(targetEntity: PautaDaAssembleia::class, inversedBy: 'opcoes')]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private PautaDaAssembleia $pauta,
        #[ORM\Column(type: 'integer')]
        private int $numero,
        #[ORM\Column(length: 120)]
        private string $rotulo,
        #[ORM\Column(type: 'boolean', options: ['default' => false])]
        private bool $abstencao = false,
    ) {
        $this->tenant = $pauta->getTenant();
        $this->rotulo = mb_substr(trim($rotulo), 0, 120);
        $pauta->registrarOpcao($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getPauta(): PautaDaAssembleia
    {
        return $this->pauta;
    }

    public function getNumero(): int
    {
        return $this->numero;
    }

    public function getRotulo(): string
    {
        return $this->rotulo;
    }

    public function isAbstencao(): bool
    {
        return $this->abstencao;
    }

    /** Unidades que escolheram esta opção (contador; ver o sigilo no cabeçalho). */
    public function getTotalUnidades(): int
    {
        return $this->totalUnidades;
    }

    /** Soma do peso de quem escolheu esta opção, decimal com 6 casas em string (nunca float). */
    public function getTotalPeso(): string
    {
        return $this->totalPeso;
    }
}
