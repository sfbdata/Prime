<?php

declare(strict_types=1);

namespace App\Cobranca\Entity;

use App\Cobranca\Enum\TipoVinculo;
use App\Cobranca\Repository\VinculoPessoaObjetoRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Vínculo temporal entre uma Pessoa e um Objeto de Cobrança (SPEC §7).
 * A data final não é previamente definida; o vínculo permanece aberto até um evento real de
 * encerramento (venda, substituição, saída, fim da locação...). Pessoas e vínculos anteriores
 * NUNCA são apagados — o histórico permanece disponível (invariável 11).
 */
#[ORM\Entity(repositoryClass: VinculoPessoaObjetoRepository::class)]
#[ORM\Table(name: 'cobranca_vinculo_pessoa_objeto')]
#[ORM\HasLifecycleCallbacks]
class VinculoPessoaObjeto implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\ManyToOne(targetEntity: Pessoa::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Pessoa $pessoa = null;

    #[ORM\ManyToOne(targetEntity: ObjetoCobranca::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?ObjetoCobranca $objeto = null;

    #[ORM\Column(enumType: TipoVinculo::class)]
    private TipoVinculo $tipoVinculo = TipoVinculo::Outro;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dataInicio;

    /** Nulo enquanto o vínculo estiver aberto. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $dataFim = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motivoEncerramento = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $observacao = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $criadoPor = null;

    public function __construct()
    {
        $this->criadoEm = new \DateTimeImmutable();
        $this->dataInicio = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    /** Vínculo aberto = ainda sem data de encerramento. */
    public function estaAberto(): bool
    {
        return $this->dataFim === null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function setTenant(Tenant $tenant): self
    {
        $this->tenant = $tenant;

        return $this;
    }

    public function getPessoa(): ?Pessoa
    {
        return $this->pessoa;
    }

    public function setPessoa(Pessoa $pessoa): self
    {
        $this->pessoa = $pessoa;

        return $this;
    }

    public function getObjeto(): ?ObjetoCobranca
    {
        return $this->objeto;
    }

    public function setObjeto(ObjetoCobranca $objeto): self
    {
        $this->objeto = $objeto;

        return $this;
    }

    public function getTipoVinculo(): TipoVinculo
    {
        return $this->tipoVinculo;
    }

    public function setTipoVinculo(TipoVinculo $tipoVinculo): self
    {
        $this->tipoVinculo = $tipoVinculo;

        return $this;
    }

    public function getDataInicio(): \DateTimeImmutable
    {
        return $this->dataInicio;
    }

    public function setDataInicio(\DateTimeImmutable $dataInicio): self
    {
        $this->dataInicio = $dataInicio;

        return $this;
    }

    public function getDataFim(): ?\DateTimeImmutable
    {
        return $this->dataFim;
    }

    public function setDataFim(?\DateTimeImmutable $dataFim): self
    {
        $this->dataFim = $dataFim;

        return $this;
    }

    public function getMotivoEncerramento(): ?string
    {
        return $this->motivoEncerramento;
    }

    public function setMotivoEncerramento(?string $motivoEncerramento): self
    {
        $this->motivoEncerramento = $motivoEncerramento;

        return $this;
    }

    public function getObservacao(): ?string
    {
        return $this->observacao;
    }

    public function setObservacao(?string $observacao): self
    {
        $this->observacao = $observacao;

        return $this;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getAtualizadoEm(): ?\DateTimeImmutable
    {
        return $this->atualizadoEm;
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function setCriadoPor(?User $criadoPor): self
    {
        $this->criadoPor = $criadoPor;

        return $this;
    }
}
