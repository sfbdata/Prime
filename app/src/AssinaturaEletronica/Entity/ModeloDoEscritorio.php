<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Entity;

use App\AssinaturaEletronica\DTO\DadosDoModelo;
use App\AssinaturaEletronica\Repository\ModeloDoEscritorioRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um modelo do escritório da aba Modelos (contrato da etapa 4, seção 3): a estrutura que o editor do navegador produz
 * (campos na forma do catálogo, papéis de quem assina e os blocos do texto), já conferida pelo
 * {@see \App\AssinaturaEletronica\Service\ValidadorDoModelo}. Nenhum HTML. O documento feito com o modelo guarda os
 * blocos dele congelados e só a chave `esc-<id>`: editar ou excluir o modelo não muda nada já salvo ou enviado.
 */
#[ORM\Entity(repositoryClass: ModeloDoEscritorioRepository::class)]
#[ORM\Table(name: 'assinatura_eletronica_modelo')]
class ModeloDoEscritorio implements TenantAware, Auditavel
{
    /** O `modelo_chave` do documento feito com este modelo é o prefixo seguido do id. */
    public const PREFIXO_DA_CHAVE = 'esc-';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Tenant $tenant;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'criado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $criadoPor;

    #[ORM\Column(length: 200)]
    private string $nome = '';

    #[ORM\Column(length: 80)]
    private string $categoria = '';

    /** @var list<list<int|string|list<string>>> */
    #[ORM\Column(type: 'json')]
    private array $campos = [];

    /** @var list<array{papel: string}> */
    #[ORM\Column(type: 'json')]
    private array $signatarios = [];

    /** @var list<array<string, int|string>> */
    #[ORM\Column(type: 'json')]
    private array $corpo = [];

    #[ORM\Column(length: 10)]
    private string $layout = '';

    // "lateral" é palavra reservada no Postgres: o nome vai entre crases para o Doctrine pôr aspas.
    #[ORM\Column(name: '`lateral`', type: 'text', nullable: true)]
    private ?string $lateral = null;

    #[ORM\Column(name: 'folha_branca')]
    private bool $folhaBranca = false;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(name: 'alterado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $alteradoEm;

    public function __construct(Tenant $tenant, ?User $criadoPor, \DateTimeImmutable $agora)
    {
        $this->tenant = $tenant;
        $this->criadoPor = $criadoPor;
        $this->criadoEm = $agora;
        $this->alteradoEm = $agora;
    }

    /** "Salvar modelo": troca o conteúdo inteiro pelo que o editor mandou; o salvo sobe na lista. */
    public function aplicar(DadosDoModelo $dados, \DateTimeImmutable $agora): void
    {
        $this->nome = $dados->nome;
        $this->categoria = $dados->categoria;
        $this->campos = $dados->campos;
        $this->signatarios = $dados->signatarios;
        $this->corpo = $dados->corpo;
        $this->layout = $dados->layout;
        $this->lateral = $dados->lateral;
        $this->folhaBranca = $dados->folhaBranca;
        $this->alteradoEm = $agora;
    }

    public function chave(): string
    {
        return self::PREFIXO_DA_CHAVE . (int) $this->id;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getCriadoPor(): ?User
    {
        return $this->criadoPor;
    }

    public function getNome(): string
    {
        return $this->nome;
    }

    public function getCategoria(): string
    {
        return $this->categoria;
    }

    /** @return list<list<int|string|list<string>>> */
    public function getCampos(): array
    {
        return $this->campos;
    }

    /** @return list<array{papel: string}> */
    public function getSignatarios(): array
    {
        return $this->signatarios;
    }

    /** @return list<array<string, int|string>> */
    public function getCorpo(): array
    {
        return $this->corpo;
    }

    public function getLayout(): string
    {
        return $this->layout;
    }

    public function getLateral(): ?string
    {
        return $this->lateral;
    }

    public function isFolhaBranca(): bool
    {
        return $this->folhaBranca;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getAlteradoEm(): \DateTimeImmutable
    {
        return $this->alteradoEm;
    }
}
