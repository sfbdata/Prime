<?php

declare(strict_types=1);

namespace App\Produto\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Produto\Enum\OrigemDaContratacao;
use App\Produto\Enum\Produto;
use App\Produto\Enum\SituacaoDaContratacao;
use App\Produto\Repository\ContratacaoRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um produto que o escritório tem (spec docs/specs/multiproduto-contratacao-e-voto.md, §4).
 *
 * - Sem nenhuma linha, o escritório é completo, como os de hoje (quem decide é o ProdutosDoEscritorio).
 * - Uma linha ativa por produto: o índice único parcial `uniq_contratacao_ativa` recusa a segunda.
 * - Religar cria uma linha nova; a encerrada fica como histórico.
 * - `origem` e `alteradaPor` guardam a ÚLTIMA mudança da linha (quem ativou ou quem encerrou). O histórico completo
 *   fica no `audit_log` (Auditavel).
 *
 * A purga do escritório apaga as linhas pela ordem de deleção; a FK CASCADE para `tenant` é a segunda rede. Apagar o
 * usuário deixa a linha sem autor (SET NULL).
 */
#[ORM\Entity(repositoryClass: ContratacaoRepository::class)]
#[ORM\Table(name: 'contratacao')]
#[ORM\UniqueConstraint(name: 'uniq_contratacao_ativa', columns: ['tenant_id', 'produto'], options: ['where' => "(situacao = 'ativa')"])]
#[ORM\Index(name: 'idx_contratacao_tenant', columns: ['tenant_id'])]
#[ORM\HasLifecycleCallbacks]
class Contratacao implements TenantAware, Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 10, enumType: SituacaoDaContratacao::class)]
    private SituacaoDaContratacao $situacao = SituacaoDaContratacao::Ativa;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $fim = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $atualizadaEm;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        #[ORM\Column(length: 20, enumType: Produto::class)]
        private Produto $produto,
        #[ORM\Column(length: 10, enumType: OrigemDaContratacao::class)]
        private OrigemDaContratacao $origem,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'alterada_por_id', nullable: true, onDelete: 'SET NULL')]
        private ?User $alteradaPor,
        #[ORM\Column(type: 'datetime_immutable')]
        private \DateTimeImmutable $inicio,
    ) {
        $this->criadaEm = $inicio;
        $this->atualizadaEm = $inicio;
    }

    /** Liga o produto no escritório a partir de `agora`. `alteradaPor` é nulo quando ninguém logado agiu (cadastro). */
    public static function ativar(Tenant $tenant, Produto $produto, OrigemDaContratacao $origem, ?User $alteradaPor, \DateTimeImmutable $agora): self
    {
        return new self($tenant, $produto, $origem, $alteradaPor, $agora);
    }

    /** Desliga o produto. A linha fica como histórico; religar é `ativar()` de novo, com linha nova. */
    public function encerrar(OrigemDaContratacao $origem, ?User $alteradaPor, \DateTimeImmutable $agora): void
    {
        $this->exigirAtiva();
        $this->situacao = SituacaoDaContratacao::Encerrada;
        // O CHECK `fim >= inicio` do banco: um relógio que volte não pode deixar a linha impossível de gravar.
        $this->fim = $agora < $this->inicio ? $this->inicio : $agora;
        $this->origem = $origem;
        $this->alteradaPor = $alteradaPor;
    }

    /** A linha vale em `agora`: ativa e já começada. */
    public function valeEm(\DateTimeImmutable $agora): bool
    {
        return SituacaoDaContratacao::Ativa === $this->situacao && $this->inicio <= $agora;
    }

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadaEm = new \DateTimeImmutable();
    }

    private function exigirAtiva(): void
    {
        if (SituacaoDaContratacao::Ativa !== $this->situacao) {
            throw new \DomainException('Esta ferramenta já está desligada.');
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getProduto(): Produto
    {
        return $this->produto;
    }

    public function getSituacao(): SituacaoDaContratacao
    {
        return $this->situacao;
    }

    public function getOrigem(): OrigemDaContratacao
    {
        return $this->origem;
    }

    public function getAlteradaPor(): ?User
    {
        return $this->alteradaPor;
    }

    public function getInicio(): \DateTimeImmutable
    {
        return $this->inicio;
    }

    public function getFim(): ?\DateTimeImmutable
    {
        return $this->fim;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    public function getAtualizadaEm(): \DateTimeImmutable
    {
        return $this->atualizadaEm;
    }
}
