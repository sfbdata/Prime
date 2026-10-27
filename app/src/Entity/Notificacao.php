<?php

namespace App\Entity;

use App\Entity\Auth\User;
use App\Entity\Tarefa\Tarefa;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\MessageHandler\ProcessarAnaliseDeInteligenciaHandler;
use App\Repository\NotificacaoRepository;
use App\Shared\Contract\Auditavel;
use App\Shared\Contract\TenantAware;
use App\Tarefa\UseCase\AlertarResponsavelDaMetaUseCase;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: NotificacaoRepository::class)]
#[ORM\HasLifecycleCallbacks]
// O "um não lido por pasta" do Acompanhar alterações (`NotificacaoRepository::idsDosUsuariosComNaoLidaNoLink`).
#[ORM\Index(name: 'idx_notificacao_tenant_tipo_lida', columns: ['tenant_id', 'tipo', 'lida'])]
class Notificacao implements Auditavel, TenantAware
{
    public const TIPO_TAREFA_CRIADA = 'tarefa_criada';
    public const TIPO_TAREFA_EM_REVISAO = 'tarefa_em_revisao';
    public const TIPO_TAREFA_PENDENTE = 'tarefa_pendente';
    public const TIPO_TAREFA_CONCLUIDA = 'tarefa_concluida';
    public const TIPO_EVENTO_CRIADO = 'evento_criado';
    public const TIPO_EVENTO_CANCELADO = 'evento_cancelado';
    public const TIPO_PONTO_JUSTIFICATIVA_ENVIADA    = 'ponto_justificativa_enviada';
    public const TIPO_PONTO_JUSTIFICATIVA_APROVADA  = 'ponto_justificativa_abonada';
    public const TIPO_PONTO_JUSTIFICATIVA_REJEITADA = 'ponto_justificativa_rejeitada';
    public const TIPO_SERVICEDESK = 'servicedesk';
    public const TIPO_SERVICEDESK_NOVO = 'servicedesk_novo';
    public const TIPO_SERVICEDESK_ATRIBUICAO = 'servicedesk_atribuicao';
    public const TIPO_DJEN_PUBLICACAO = 'djen_publicacao';
    /** Movimentação nova numa pasta da qual o destinatário é o responsável ("Avisar quando chegar" do Push). */
    public const TIPO_PUSH_PUBLICACAO_PASTA = 'push_publicacao_pasta';
    /** Alguém respondeu, no Registro da pasta, um comentário do destinatário. */
    public const TIPO_PASTA_RESPOSTA_REGISTRO = 'pasta_resposta_registro';
    /** Alguém @mencionou o destinatário num registro (ou resposta) do Registro da pasta. */
    public const TIPO_PASTA_MENCAO_REGISTRO = 'pasta_mencao_registro';
    /** Alteração numa pasta que o destinatário acompanha ("Acompanhar alterações" do menu ⋮; hoje, mensagem nova no Registro). */
    public const TIPO_PASTA_ACOMPANHADA = 'pasta_acompanhada';
    /** Alguém @mencionou o destinatário numa nota técnica de processo (escrita numa pasta). */
    public const TIPO_NOTA_TECNICA_MENCAO = 'nota_tecnica_mencao';
    // Pedido de acesso a pasta com cadeado (spec pasta-cadeado K5): vai para cada primário ativo e o Master.
    public const TIPO_PASTA_PEDIDO_ACESSO = 'pasta_pedido_acesso';
    /** Resposta ao pedido de acesso a uma pasta com cadeado (liberado ou recusado): vai para quem pediu. */
    public const TIPO_PASTA_ACESSO_DECIDIDO = 'pasta_acesso_decidido';
    /** Alguém @mencionou o destinatário numa conversa da Central de Comunicação (sem o texto: é auditada). */
    public const TIPO_CENTRAL_MENCAO = 'central_mencao';
    /** Alguém adicionou o destinatário a um grupo da Central de Comunicação. */
    public const TIPO_CENTRAL_GRUPO_ADICIONADO = 'central_grupo_adicionado';
    /** Despacho urgente de um item da pasta para a Central ("Encaminhar via Chat I.A"; sem o texto: é auditada). */
    public const TIPO_CENTRAL_DESPACHO = 'central_despacho';
    /** Quem recebeu o link externo do Push pediu a renovação; vai para quem criou o link. */
    public const TIPO_PUSH_LINK_RENOVACAO = 'push_link_renovacao';
    /** Um primário pediu a exclusão da conta do escritório (PR 5); vai para os Masters. */
    public const TIPO_CONTA_PEDIDO_EXCLUSAO = 'conta_pedido_exclusao';

    // Trabalho Externo (F4): avisos de vagas e candidaturas.
    public const TIPO_TRABALHO_EXTERNO_PEDIDO_DE_VAGA = 'trabalho_externo_pedido_de_vaga';
    public const TIPO_TRABALHO_EXTERNO_CANDIDATURA = 'trabalho_externo_candidatura';
    public const TIPO_TRABALHO_EXTERNO_OFERTA_ACEITA = 'trabalho_externo_oferta_aceita';
    public const TIPO_TRABALHO_EXTERNO_CANDIDATOS_ENVIADOS = 'trabalho_externo_candidatos_enviados';
    public const TIPO_TRABALHO_EXTERNO_VAGA_APROVADA = 'trabalho_externo_vaga_aprovada';
    public const TIPO_TRABALHO_EXTERNO_VAGA_REPROVADA = 'trabalho_externo_vaga_reprovada';
    public const TIPO_TRABALHO_EXTERNO_CANDIDATURA_STATUS = 'trabalho_externo_candidatura_status';
    public const TIPO_TRABALHO_EXTERNO_VAGA_NA_AREA = 'trabalho_externo_vaga_na_area';
    public const TIPO_TRABALHO_EXTERNO_DEMANDA_INFORMACOES = 'trabalho_externo_demanda_informacoes';
    public const TIPO_TRABALHO_EXTERNO_DEMANDA_ANDAMENTO = 'trabalho_externo_demanda_andamento';
    public const TIPO_TRABALHO_EXTERNO_DESPACHO_RESPOSTA = 'trabalho_externo_despacho_resposta';
    public const TIPO_TRABALHO_EXTERNO_OFERTA_RECUSADA = 'trabalho_externo_oferta_recusada';
    public const TIPO_TRABALHO_EXTERNO_TERMO_ENVIADO = 'trabalho_externo_termo_enviado';
    public const TIPO_TRABALHO_EXTERNO_TERMO_ACEITO = 'trabalho_externo_termo_aceito';
    /** Os ícones desses tipos no sino (fora do `match` de getIcone, que já está no limite de tamanho). */
    public const ICONES_DO_TRABALHO_EXTERNO = [
        self::TIPO_TRABALHO_EXTERNO_PEDIDO_DE_VAGA => 'bi-inbox text-warning',
        self::TIPO_TRABALHO_EXTERNO_CANDIDATURA => 'bi-send-check text-primary',
        self::TIPO_TRABALHO_EXTERNO_OFERTA_ACEITA => 'bi-hand-thumbs-up text-success',
        self::TIPO_TRABALHO_EXTERNO_CANDIDATOS_ENVIADOS => 'bi-people text-primary',
        self::TIPO_TRABALHO_EXTERNO_VAGA_APROVADA => 'bi-check2-circle text-success',
        self::TIPO_TRABALHO_EXTERNO_VAGA_REPROVADA => 'bi-x-circle text-danger',
        self::TIPO_TRABALHO_EXTERNO_CANDIDATURA_STATUS => 'bi-arrow-repeat text-info',
        self::TIPO_TRABALHO_EXTERNO_VAGA_NA_AREA => 'bi-megaphone text-primary',
        self::TIPO_TRABALHO_EXTERNO_DEMANDA_INFORMACOES => 'bi-question-circle text-warning',
        self::TIPO_TRABALHO_EXTERNO_DEMANDA_ANDAMENTO => 'bi-briefcase text-primary',
        self::TIPO_TRABALHO_EXTERNO_DESPACHO_RESPOSTA => 'bi-reply text-success',
        self::TIPO_TRABALHO_EXTERNO_OFERTA_RECUSADA => 'bi-x-circle text-danger',
        self::TIPO_TRABALHO_EXTERNO_TERMO_ENVIADO => 'bi-file-earmark-check text-warning',
        self::TIPO_TRABALHO_EXTERNO_TERMO_ACEITO => 'bi-file-earmark-check text-success',
    ];

    /** Categorias de notificação (derivadas do tipo). */
    public const CATEGORIA_PESSOAL = 'pessoal';
    public const CATEGORIA_GESTAO  = 'gestao';

    /** Tipos que representam ações de gestão/admin (o resto é pessoal). */
    public const TIPOS_GESTAO = [
        self::TIPO_PONTO_JUSTIFICATIVA_ENVIADA,
        self::TIPO_SERVICEDESK_NOVO,
        self::TIPO_SERVICEDESK_ATRIBUICAO,
        self::TIPO_PASTA_PEDIDO_ACESSO,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $usuario = null;

    #[ORM\ManyToOne(targetEntity: Tenant::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Tenant $tenant = null;

    #[ORM\Column(length: 50)]
    private string $tipo;

    #[ORM\Column(length: 255)]
    private string $titulo;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $mensagem = null;

    #[ORM\ManyToOne(targetEntity: Tarefa::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    private ?Tarefa $tarefa = null;

    #[ORM\Column(type: 'boolean')]
    private bool $lida = false;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $criadaEm;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lidaEm = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $url = null;

    public function __construct()
    {
        $this->criadaEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsuario(): ?User
    {
        return $this->usuario;
    }

    public function setUsuario(?User $usuario): self
    {
        $this->usuario = $usuario;
        return $this;
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

    public function getTipo(): string
    {
        return $this->tipo;
    }

    public function setTipo(string $tipo): self
    {
        $this->tipo = $tipo;
        return $this;
    }

    public function getTitulo(): string
    {
        return $this->titulo;
    }

    public function setTitulo(string $titulo): self
    {
        $this->titulo = $titulo;
        return $this;
    }

    public function getMensagem(): ?string
    {
        return $this->mensagem;
    }

    public function setMensagem(?string $mensagem): self
    {
        $this->mensagem = $mensagem;
        return $this;
    }

    public function getTarefa(): ?Tarefa
    {
        return $this->tarefa;
    }

    public function setTarefa(?Tarefa $tarefa): self
    {
        $this->tarefa = $tarefa;
        return $this;
    }

    public function isLida(): bool
    {
        return $this->lida;
    }

    public function setLida(bool $lida): self
    {
        $this->lida = $lida;
        if ($lida && $this->lidaEm === null) {
            $this->lidaEm = new \DateTimeImmutable();
        }
        return $this;
    }

    public function getCriadaEm(): \DateTimeImmutable
    {
        return $this->criadaEm;
    }

    public function getLidaEm(): ?\DateTimeImmutable
    {
        return $this->lidaEm;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): self
    {
        $this->url = $url;
        return $this;
    }

    public function getIcone(): string
    {
        // Trabalho Externo (F4): avisos de vagas e candidaturas.
        return self::ICONES_DO_TRABALHO_EXTERNO[$this->tipo] ?? $this->iconeDosDemaisTipos();
    }

    private function iconeDosDemaisTipos(): string
    {
        return match ($this->tipo) {
            self::TIPO_TAREFA_CRIADA => 'bi-plus-circle text-primary',
            self::TIPO_TAREFA_EM_REVISAO => 'bi-hourglass-split text-warning',
            self::TIPO_TAREFA_PENDENTE => 'bi-arrow-repeat text-info',
            self::TIPO_TAREFA_CONCLUIDA => 'bi-check-circle text-success',
            self::TIPO_EVENTO_CRIADO => 'bi-calendar-plus text-primary',
            self::TIPO_EVENTO_CANCELADO => 'bi-calendar-x text-danger',
            self::TIPO_PONTO_JUSTIFICATIVA_ENVIADA    => 'bi-file-earmark-text text-info',
            self::TIPO_PONTO_JUSTIFICATIVA_APROVADA  => 'bi-check2-circle text-success',
            self::TIPO_PONTO_JUSTIFICATIVA_REJEITADA => 'bi-x-circle text-danger',
            self::TIPO_SERVICEDESK_NOVO => 'bi-ticket-detailed text-primary',
            self::TIPO_SERVICEDESK_ATRIBUICAO => 'bi-person-check text-info',
            self::TIPO_DJEN_PUBLICACAO, self::TIPO_PUSH_PUBLICACAO_PASTA => 'bi-newspaper text-primary',
            // Cadeado da pasta (desenho 02, `bi-lock`/`bi-unlock`): o pedido e a resposta.
            self::TIPO_PASTA_PEDIDO_ACESSO => 'bi-lock text-primary',
            self::TIPO_PASTA_ACESSO_DECIDIDO => 'bi-unlock text-primary',
            // Desenho (bluejus-central.js, tipo "direcionada"): `bi-person-check` em âmbar.
            self::TIPO_PASTA_RESPOSTA_REGISTRO => 'bi-person-check text-warning',
            self::TIPO_CENTRAL_DESPACHO => 'bi-person-check text-warning',
            // Desenho (bluejus-central.js, tipo "mencao"): `bi-at` em azul.
            self::TIPO_PASTA_MENCAO_REGISTRO => 'bi-at text-primary',
            self::TIPO_PASTA_ACOMPANHADA => 'bi-bell text-primary',
            // Menção numa nota técnica: o mesmo tipo "mencao" do desenho — `bi-at` em azul.
            self::TIPO_NOTA_TECNICA_MENCAO => 'bi-at text-primary',
            self::TIPO_CENTRAL_MENCAO => 'bi-at text-primary',
            // Desenho (bluejus-central.js L6185): "adicionou você ao grupo" — bi-people.
            self::TIPO_CENTRAL_GRUPO_ADICIONADO => 'bi-people text-primary',
            // Desenho (bluejus-central.js, tipo "alerta"): `bi-bell-fill` em laranja (#e8590c),
            // o mesmo sino da meta já alertada. `text-orange` não é utilitário do Bootstrap: a
            // regra (com o par do tema escuro) precisa existir na folha global (app.css); sem
            // ela o ícone herda a cor de `.notif-icon`.
            AlertarResponsavelDaMetaUseCase::TIPO_NOTIFICACAO => 'bi-bell-fill text-orange',
            // Desenho (bluejus-central.js, tipo "documento_analisado"): `bi-file-earmark-check` em azul.
            ProcessarAnaliseDeInteligenciaHandler::TIPO_NOTIFICACAO => 'bi-file-earmark-check text-primary',
            default => 'bi-bell text-secondary',
        };
    }

    /**
     * Categoria da notificação, derivada do tipo: "gestao" para ações
     * administrativas (aprovar justificativa de ponto, chamado atribuído),
     * "pessoal" para o restante (demandas, agenda, resultado do próprio ponto).
     */
    public function getCategoria(): string
    {
        return in_array($this->tipo, self::TIPOS_GESTAO, true)
            ? self::CATEGORIA_GESTAO
            : self::CATEGORIA_PESSOAL;
    }

    public function getTempoRelativo(): string
    {
        $agora = new \DateTimeImmutable();
        $diff = $agora->diff($this->criadaEm);

        if ($diff->days === 0) {
            if ($diff->h === 0) {
                if ($diff->i === 0) {
                    return 'agora';
                }
                return $diff->i . ' min atrás';
            }
            return $diff->h . 'h atrás';
        }

        if ($diff->days === 1) {
            return 'ontem';
        }

        if ($diff->days < 7) {
            return $diff->days . ' dias atrás';
        }

        return $this->criadaEm->format('d/m/Y');
    }
}
