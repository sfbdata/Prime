<?php

declare(strict_types=1);

namespace App\Inteligencia\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Enum\Agente;
use App\Inteligencia\Enum\StatusDaAnalise;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Repository\AnaliseDeInteligenciaRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Solicitação + resultado de UMA análise por IA, numa tabela só: o `status` é a máquina de estados
 * (pendente → processando → concluida | falhou | indisponivel) e a listagem por pasta é uma
 * consulta. Não há N resultados por pedido.
 *
 * O que fica gravado do que foi enviado é `contextoHash` + `contextoResumo` (ids e contagens) —
 * NUNCA o texto integral (decisão D5). A resposta integral do modelo fica em `textoBruto` para
 * auditoria/reparse.
 *
 * `solicitante` é a trilha de "quem pediu" — no worker o `AuditLogSubscriber` não tem sessão, então
 * o ator do audit_log sai nulo; a autoria mora aqui. `excluir()` é soft delete: a linha e a trilha
 * ficam, a tela deixa de mostrar.
 *
 * Índices nomeados à mão (inclusive os de FK) para a migration escrita à mão bater com o mapeamento
 * sem depender de nome gerado por hash. O `criada_em` do índice de alvo é ASC: o atributo não
 * representa DESC e o Postgres varre btree nos dois sentidos.
 */
#[ORM\Entity(repositoryClass: AnaliseDeInteligenciaRepository::class)]
#[ORM\Table(name: 'inteligencia_analise')]
#[ORM\Index(name: 'idx_inteligencia_analise_tenant', columns: ['tenant_id'])]
#[ORM\Index(name: 'idx_inteligencia_analise_alvo', columns: ['tenant_id', 'alvo_tipo', 'alvo_id', 'criada_em'])]
#[ORM\Index(name: 'idx_inteligencia_analise_tenant_status', columns: ['tenant_id', 'status'])]
#[ORM\Index(name: 'idx_inteligencia_analise_tenant_criada', columns: ['tenant_id', 'criada_em'])]
#[ORM\Index(name: 'idx_inteligencia_analise_solicitante', columns: ['solicitante_id'])]
#[ORM\Index(name: 'idx_inteligencia_analise_lida_por', columns: ['lida_por_id'])]
#[ORM\Index(name: 'idx_inteligencia_analise_excluida_por', columns: ['excluida_por_id'])]
class AnaliseDeInteligencia implements TenantAware, Auditavel
{
    public const ALVO_PASTA = 'pasta';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(length: 20, enumType: StatusDaAnalise::class)]
    private StatusDaAnalise $status = StatusDaAnalise::Pendente;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $provedor = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $modelo = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $tokensEntrada = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $tokensSaida = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $duracaoMs = null;

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    private int $tentativas = 0;

    /** Mensagem técnica (classe do erro, status HTTP) — só quem tem `admin.inteligencia.manage` vê. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $erroMotivo = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $resumo = null;

    /** @var list<array{tipo: string, texto: string}>|null */
    #[ORM\Column(type: 'json', nullable: true, options: ['jsonb' => true])]
    private ?array $pontos = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $quemAge = null;

    /**
     * Análise integral de um agente da pasta, no formato do Designer (CONCLUSÃO, EVIDÊNCIAS, CONTEXTO,
     * PONTOS DE ATENÇÃO, PRÓXIMA PROVIDÊNCIA). Só `analise_pasta`; o Push não a usa. `resumo` e
     * `pontos` continuam alimentando o cartão.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $textoDaAnalise = null;

    /** Resposta integral do modelo, para auditoria/reparse. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $textoBruto = null;

    /** Cadeado: análise interna nunca vai a um link público (Push Compartilhado, frente futura). */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $internaDoEscritorio = true;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lidaEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $lidaPor = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $excluidaEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $excluidaPor = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $iniciadaEm = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $concluidaEm = null;

    /**
     * @param array<string, mixed> $contextoResumo ids/chaves/contagens — nunca o texto (D5)
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
        private ?User $solicitante,
        #[ORM\Column(length: 40, enumType: TipoDeAnalise::class)]
        private TipoDeAnalise $tipo,
        #[ORM\Column(length: 20)]
        private string $alvoTipo,
        #[ORM\Column(type: 'integer')]
        private int $alvoId,
        #[ORM\Column(length: 40)]
        private string $versaoDoPrompt,
        #[ORM\Column(length: 64)]
        private string $contextoHash,
        #[ORM\Column(type: 'json', options: ['jsonb' => true])]
        private array $contextoResumo,
        /** Qual agente da pasta gerou a análise (`analise_pasta`); nulo no Push e nos demais tipos. */
        #[ORM\Column(length: 20, nullable: true, enumType: Agente::class)]
        private ?Agente $agente = null,
    ) {
        $this->criadaEm = new \DateTimeImmutable();
    }

    // ---------------------------------------------------------------------------------------
    // Máquina de estados
    // ---------------------------------------------------------------------------------------

    /** pendente | falhou → processando. Conta a tentativa (retry do Messenger passa por aqui). */
    public function iniciarProcessamento(): void
    {
        $this->exigirStatus([StatusDaAnalise::Pendente, StatusDaAnalise::Falhou], 'iniciar o processamento');

        $this->status = StatusDaAnalise::Processando;
        $this->iniciadaEm = new \DateTimeImmutable();
        ++$this->tentativas;
        $this->erroMotivo = null;
    }

    /**
     * O contexto efetivamente usado pelo worker — pode diferir do que a solicitação calculou se
     * chegou publicação entre o pedido e o processamento.
     *
     * @param array<string, mixed> $contextoResumo
     */
    public function registrarContexto(string $contextoHash, array $contextoResumo): void
    {
        $this->contextoHash = $contextoHash;
        $this->contextoResumo = $contextoResumo;
    }

    public function registrarProvedor(string $provedor, ?string $modelo = null): void
    {
        $this->provedor = $provedor;
        $this->modelo = $modelo;
    }

    /**
     * processando → concluida, com o resultado interpretado e os metadados do provedor.
     *
     * @param list<array{tipo: string, texto: string}> $pontos
     */
    public function concluir(
        string $resumo,
        array $pontos,
        ?string $quemAge,
        string $textoBruto,
        string $provedor,
        string $modelo,
        ?int $tokensEntrada,
        ?int $tokensSaida,
        ?int $duracaoMs,
        ?string $textoDaAnalise = null,
    ): void {
        $this->exigirStatus([StatusDaAnalise::Processando], 'concluir');

        $this->status = StatusDaAnalise::Concluida;
        $this->resumo = $resumo;
        $this->pontos = $pontos;
        $this->quemAge = $quemAge;
        $this->textoDaAnalise = $textoDaAnalise;
        $this->textoBruto = $textoBruto;
        $this->provedor = $provedor;
        $this->modelo = $modelo;
        $this->tokensEntrada = $tokensEntrada;
        $this->tokensSaida = $tokensSaida;
        $this->duracaoMs = $duracaoMs;
        $this->erroMotivo = null;
        $this->concluidaEm = new \DateTimeImmutable();
    }

    /**
     * processando → pendente: falha TRANSITÓRIA que o Messenger ainda vai retentar. Fica "em
     * andamento" de propósito — enquanto a fila retenta, um novo clique não pode abrir uma segunda
     * análise (gasto duplo). O motivo fica registrado; `concluidaEm` continua nulo.
     */
    public function devolverParaFila(string $motivo): void
    {
        $this->exigirStatus([StatusDaAnalise::Processando], 'devolver à fila');

        $this->status = StatusDaAnalise::Pendente;
        $this->erroMotivo = $motivo;
    }

    /** pendente | processando → falhou. Guarda a resposta bruta quando houve (JSON inválido). */
    public function falhar(string $motivo, ?string $textoBruto = null): void
    {
        $this->exigirStatus([StatusDaAnalise::Pendente, StatusDaAnalise::Processando], 'marcar como falha');

        $this->status = StatusDaAnalise::Falhou;
        $this->erroMotivo = $motivo;
        if ($textoBruto !== null) {
            $this->textoBruto = $textoBruto;
        }
        $this->concluidaEm = new \DateTimeImmutable();
    }

    /** pendente | processando → indisponivel (sem provedor). Terminal: repetir não muda nada. */
    public function marcarIndisponivel(string $motivo): void
    {
        $this->exigirStatus([StatusDaAnalise::Pendente, StatusDaAnalise::Processando], 'marcar como indisponível');

        $this->status = StatusDaAnalise::Indisponivel;
        $this->erroMotivo = $motivo;
        $this->concluidaEm = new \DateTimeImmutable();
    }

    /** @param list<StatusDaAnalise> $permitidos */
    private function exigirStatus(array $permitidos, string $acao): void
    {
        if (!in_array($this->status, $permitidos, true)) {
            throw new \DomainException(sprintf(
                'Não é possível %s: a análise está "%s".',
                $acao,
                $this->status->value,
            ));
        }
    }

    // ---------------------------------------------------------------------------------------
    // Ações do usuário
    // ---------------------------------------------------------------------------------------

    /** Idempotente: marcar de novo não troca quem leu primeiro. */
    public function marcarLida(User $por): void
    {
        if ($this->lidaEm !== null) {
            return;
        }

        $this->lidaEm = new \DateTimeImmutable();
        $this->lidaPor = $por;
    }

    /** Soft delete: some da tela, fica na trilha. Idempotente. */
    public function excluir(User $por): void
    {
        if ($this->excluidaEm !== null) {
            return;
        }

        $this->excluidaEm = new \DateTimeImmutable();
        $this->excluidaPor = $por;
    }

    /** Alterna o cadeado "interna do escritório" e devolve o estado novo. */
    public function alternarInterna(): bool
    {
        $this->internaDoEscritorio = !$this->internaDoEscritorio;

        return $this->internaDoEscritorio;
    }

    // ---------------------------------------------------------------------------------------
    // Leitura
    // ---------------------------------------------------------------------------------------

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): ?Tenant
    {
        return $this->tenant;
    }

    public function getSolicitante(): ?User
    {
        return $this->solicitante;
    }

    public function getTipo(): TipoDeAnalise
    {
        return $this->tipo;
    }

    public function getAgente(): ?Agente
    {
        return $this->agente;
    }

    public function getTextoDaAnalise(): ?string
    {
        return $this->textoDaAnalise;
    }

    public function getAlvoTipo(): string
    {
        return $this->alvoTipo;
    }

    public function getAlvoId(): int
    {
        return $this->alvoId;
    }

    public function getStatus(): StatusDaAnalise
    {
        return $this->status;
    }

    public function estaEmAndamento(): bool
    {
        return $this->status->emAndamento();
    }

    public function estaConcluida(): bool
    {
        return $this->status === StatusDaAnalise::Concluida;
    }

    public function getVersaoDoPrompt(): string
    {
        return $this->versaoDoPrompt;
    }

    public function getContextoHash(): string
    {
        return $this->contextoHash;
    }

    /** @return array<string, mixed> */
    public function getContextoResumo(): array
    {
        return $this->contextoResumo;
    }

    /** @return list<string> chaves das movimentações lidas por esta análise */
    public function getChavesAnalisadas(): array
    {
        $chaves = $this->contextoResumo['chaves'] ?? [];

        return is_array($chaves) ? array_values(array_filter($chaves, 'is_string')) : [];
    }

    public function getProvedor(): ?string
    {
        return $this->provedor;
    }

    public function getModelo(): ?string
    {
        return $this->modelo;
    }

    public function getTokensEntrada(): ?int
    {
        return $this->tokensEntrada;
    }

    public function getTokensSaida(): ?int
    {
        return $this->tokensSaida;
    }

    public function getDuracaoMs(): ?int
    {
        return $this->duracaoMs;
    }

    public function getTentativas(): int
    {
        return $this->tentativas;
    }

    public function getErroMotivo(): ?string
    {
        return $this->erroMotivo;
    }

    public function getResumo(): ?string
    {
        return $this->resumo;
    }

    /** @return list<array{tipo: string, texto: string}>|null */
    public function getPontos(): ?array
    {
        return $this->pontos;
    }

    public function getQuemAge(): ?string
    {
        return $this->quemAge;
    }

    public function getTextoBruto(): ?string
    {
        return $this->textoBruto;
    }

    public function isInternaDoEscritorio(): bool
    {
        return $this->internaDoEscritorio;
    }

    public function foiLida(): bool
    {
        return $this->lidaEm !== null;
    }

    public function getLidaEm(): ?\DateTimeImmutable
    {
        return $this->lidaEm;
    }

    public function getLidaPor(): ?User
    {
        return $this->lidaPor;
    }

    public function estaExcluida(): bool
    {
        return $this->excluidaEm !== null;
    }

    public function getExcluidaEm(): ?\DateTimeImmutable
    {
        return $this->excluidaEm;
    }

    public function getExcluidaPor(): ?User
    {
        return $this->excluidaPor;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    public function getIniciadaEm(): ?\DateTimeImmutable
    {
        return $this->iniciadaEm;
    }

    public function getConcluidaEm(): ?\DateTimeImmutable
    {
        return $this->concluidaEm;
    }
}
