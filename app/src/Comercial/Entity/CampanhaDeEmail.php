<?php

declare(strict_types=1);

namespace App\Comercial\Entity;

use App\Comercial\Repository\CampanhaDeEmailRepository;
use App\Entity\Auth\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma campanha de e-mail confirmada pelo Master (spec `comercial-e-emails.md` §8.4, fatia 2b). Não há rascunho: a linha
 * só nasce no "Enviar campanha", depois da confirmação literal do dc (L.560), com a chave de idempotência gerada na tela
 * (UNIQUE: clique duplo não cria duas campanhas).
 *
 * Guarda a cópia congelada do conteúdo (`modelo_chave`, `modelo_nome`, `modo`, `assunto`, `selo`, `titulo`, `conteudo`):
 * excluir ou editar o modelo depois não muda a campanha. `publico_parametros`: sem filtro, os e-mails do CSV ou digitados
 * que passaram no B1; com o "Filtrar destinatários" (§9), `{informados, filtro}`, com a busca livre. Apagados quando a
 * campanha vira `enviada` ou `cancelada`.
 *
 * Plataforma (sem escritório, do Master): não é TenantAware. NÃO é `Auditavel`: a trilha é manual, na confirmação e no
 * encerramento, com `changes` só `{publico, frequencia, total}` (R6). Gravada e lida por SQL
 * ({@see CampanhaDeEmailRepository}); a entidade existe para o esquema.
 *
 * Datas em hora local (America/Sao_Paulo, sem fuso), como o resto do sistema.
 */
#[ORM\Entity(repositoryClass: CampanhaDeEmailRepository::class)]
#[ORM\Table(name: 'comercial_campanha')]
#[ORM\UniqueConstraint(name: 'uniq_comercial_campanha_chave', columns: ['chave_idempotencia'])]
#[ORM\Index(name: 'idx_comercial_campanha_estado', columns: ['estado', 'proxima_execucao'])]
class CampanhaDeEmail
{
    public const NA_FILA = 'na_fila';
    public const AGENDADA = 'agendada';
    public const RECORRENTE = 'recorrente';
    public const ENVIANDO = 'enviando';
    public const ENVIADA = 'enviada';
    public const CANCELADA = 'cancelada';

    /** Campanha ativa: a cadeia do expansor só para quando não sobra nenhuma destas (§8.5). */
    public const ATIVAS = [self::NA_FILA, self::AGENDADA, self::ENVIANDO, self::RECORRENTE];

    public const UNICA = 'unica';
    public const FREQUENCIAS = [self::UNICA, 'semanal', 'quinzenal', 'mensal'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'chave_idempotencia', length: 64)]
    private string $chaveIdempotencia = '';

    /** O assunto, como o dc chama a campanha (L.560, `nome: M.assunto`). */
    #[ORM\Column(length: 255)]
    private string $nome = '';

    #[ORM\Column(name: 'modelo_chave', length: 40)]
    private string $modeloChave = '';

    #[ORM\Column(name: 'modelo_nome', length: 200)]
    private string $modeloNome = '';

    #[ORM\Column(length: 10)]
    private string $modo = '';

    #[ORM\Column(length: 255)]
    private string $assunto = '';

    #[ORM\Column(length: 120)]
    private string $selo = '';

    #[ORM\Column(length: 255)]
    private string $titulo = '';

    /** O JSON dos campos do simples, dos blocos, ou o HTML (já validado e limitado a 200 KB). */
    #[ORM\Column(type: 'text')]
    private string $conteudo = '';

    #[ORM\Column(length: 10)]
    private string $publico = '';

    /** @var list<array{email: string, nome: string}>|null */
    #[ORM\Column(name: 'publico_parametros', type: 'json', nullable: true)]
    private ?array $publicoParametros = null;

    #[ORM\Column(length: 10)]
    private string $frequencia = self::UNICA;

    /** A data escolhida em "Agendar" ou "Primeiro envio"; na recorrente, a âncora do "+1 mês". */
    #[ORM\Column(name: 'agendada_para', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $agendadaPara = null;

    #[ORM\Column(name: 'proxima_execucao', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $proximaExecucao = null;

    #[ORM\Column(length: 12)]
    private string $estado = self::NA_FILA;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motivo = null;

    /** Quantas execuções já foram materializadas em `comercial_campanha_destino`. */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $execucoes = 0;

    /** Os destinatários da última execução (na confirmação, os da hora). */
    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $total = 0;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'criada_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $criadaPor = null;

    #[ORM\Column(name: 'criada_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    public function __construct()
    {
        $this->criadaEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }
}
