<?php

declare(strict_types=1);

namespace App\Pasta\Entity;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Exception\LinkExternoDoPushException;
use App\Pasta\Repository\LinkExternoDoPushRepository;
use App\Processo\Entity\Processo;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Link externo do Push Processual (spec push-link-externo §1): o endereço público
 * `/p/bluejus-<slug>-<8 aleatórios>` que mostra a terceiros, sem login, o andamento da pasta.
 *
 * NÃO é `Auditavel` de propósito: a linha guarda o código de acesso em claro (o escritório precisa
 * copiá-lo de novo na lista, desenho C.6) e o código nunca pode ir para o `audit_log`.
 *
 * O estado é derivado (`estado($agora)`): revogado > expirado > ativo. Revogar é definitivo (o
 * desenho não tem desfazer). O conteúdo NÃO é fotografado: a página lê as movimentações e as
 * análises ao vivo, então o link só guarda as escolhas (código, cliente, análises).
 */
#[ORM\Entity(repositoryClass: LinkExternoDoPushRepository::class)]
#[ORM\Table(name: 'push_link_externo')]
#[ORM\Index(name: 'idx_push_link_externo_tenant_pasta', columns: ['tenant_id', 'pasta_id'])]
#[ORM\Index(name: 'idx_push_link_externo_pasta', columns: ['pasta_id'])]
#[ORM\Index(name: 'idx_push_link_externo_processo', columns: ['processo_id'])]
#[ORM\Index(name: 'idx_push_link_externo_criado_por', columns: ['criado_por_id'])]
#[ORM\Index(name: 'idx_push_link_externo_revogado_por', columns: ['revogado_por_id'])]
#[ORM\UniqueConstraint(name: 'uniq_push_link_externo_endereco', columns: ['endereco'])]
class LinkExternoDoPush implements TenantAware
{
    public const VALIDADE = '+30 days';
    public const MAXIMO_DE_TENTATIVAS = 5;
    public const BLOQUEIO = '+15 minutes';
    public const RESPOSTA_RENOVADO = 'renovado';
    public const RESPOSTA_RECUSADO = 'recusado';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'expira_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $expiraEm;

    #[ORM\Column(name: 'revogado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revogadoEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'revogado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $revogadoPor = null;

    #[ORM\Column(name: 'renovacao_pedida_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $renovacaoPedidaEm = null;

    #[ORM\Column(name: 'renovacao_nome', length: 60, nullable: true)]
    private ?string $renovacaoNome = null;

    #[ORM\Column(name: 'renovacao_mensagem', length: 280, nullable: true)]
    private ?string $renovacaoMensagem = null;

    #[ORM\Column(name: 'renovacao_resposta', length: 10, nullable: true)]
    private ?string $renovacaoResposta = null;

    #[ORM\Column(name: 'tentativas_codigo', type: 'integer', options: ['default' => 0])]
    private int $tentativasCodigo = 0;

    #[ORM\Column(name: 'bloqueado_ate', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $bloqueadoAte = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(name: 'tenant_id', nullable: false)]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: Pasta::class)]
        #[ORM\JoinColumn(name: 'pasta_id', nullable: false, onDelete: 'CASCADE')]
        private Pasta $pasta,
        #[ORM\ManyToOne(targetEntity: Processo::class)]
        #[ORM\JoinColumn(name: 'processo_id', nullable: false, onDelete: 'CASCADE')]
        private Processo $processo,
        #[ORM\Column(length: 80)]
        private string $endereco,
        #[ORM\Column(type: 'string', length: 6, nullable: true, options: ['fixed' => true])]
        private ?string $codigo,
        #[ORM\Column(name: 'mostrar_cliente', type: 'boolean')]
        private bool $mostrarCliente,
        #[ORM\Column(name: 'incluir_analises', type: 'boolean')]
        private bool $incluirAnalises,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'criado_por_id', nullable: false, onDelete: 'CASCADE')]
        private User $criadoPor,
        #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $criadoEm,
    ) {
        $this->expiraEm = $criadoEm->modify(self::VALIDADE);
    }

    // ---------------------------------------------------------------------------------------
    // Estado
    // ---------------------------------------------------------------------------------------

    public function estado(\DateTimeImmutable $agora): EstadoDoLinkExterno
    {
        if ($this->estaRevogado()) {
            return EstadoDoLinkExterno::Revogado;
        }
        if (!$this->estaExpirado($agora)) {
            return EstadoDoLinkExterno::Ativo;
        }

        return $this->temPedidoPendente() ? EstadoDoLinkExterno::RenovacaoPedida : EstadoDoLinkExterno::Expirado;
    }

    public function estaRevogado(): bool
    {
        return $this->revogadoEm !== null;
    }

    public function estaExpirado(\DateTimeImmutable $agora): bool
    {
        return $this->expiraEm <= $agora;
    }

    public function estaAtivo(\DateTimeImmutable $agora): bool
    {
        return $this->estado($agora) === EstadoDoLinkExterno::Ativo;
    }

    public function temPedidoPendente(): bool
    {
        return $this->renovacaoPedidaEm !== null && $this->renovacaoResposta === null;
    }

    public function renovacaoFoiRecusada(): bool
    {
        return $this->renovacaoPedidaEm !== null && $this->renovacaoResposta === self::RESPOSTA_RECUSADO;
    }

    /** Terceiro pode pedir: expirado, não revogado, sem pedido pendente nem recusado ([PC] 138-141). */
    public function aceitaPedidoDeRenovacao(\DateTimeImmutable $agora): bool
    {
        return !$this->estaRevogado()
            && $this->estaExpirado($agora)
            && !$this->temPedidoPendente()
            && !$this->renovacaoFoiRecusada();
    }

    public function exigeCodigo(): bool
    {
        return $this->codigo !== null;
    }

    public function estaBloqueado(\DateTimeImmutable $agora): bool
    {
        return $this->bloqueadoAte !== null && $this->bloqueadoAte > $agora;
    }

    // ---------------------------------------------------------------------------------------
    // Transições
    // ---------------------------------------------------------------------------------------

    /** Definitivo e idempotente: revogar um revogado não muda quem nem quando. */
    public function revogar(User $por, \DateTimeImmutable $agora): void
    {
        if ($this->estaRevogado()) {
            return;
        }

        $this->revogadoEm = $agora;
        $this->revogadoPor = $por;
    }

    /** Mais 30 dias a partir de agora. Com pedido pendente, ele é respondido como renovado. */
    public function renovar(\DateTimeImmutable $agora): void
    {
        $this->exigirRenovavel($agora);

        $this->expiraEm = $agora->modify(self::VALIDADE);
        $this->tentativasCodigo = 0;
        $this->bloqueadoAte = null;
        if ($this->temPedidoPendente()) {
            $this->renovacaoResposta = self::RESPOSTA_RENOVADO;
        }
    }

    public function recusarRenovacao(): void
    {
        if (!$this->temPedidoPendente()) {
            throw LinkExternoDoPushException::semPedidoPendente();
        }

        $this->renovacaoResposta = self::RESPOSTA_RECUSADO;
    }

    /** Um pedido novo substitui o anterior já atendido (renovado e expirado de novo). */
    public function pedirRenovacao(?string $nome, ?string $mensagem, \DateTimeImmutable $agora): void
    {
        if (!$this->aceitaPedidoDeRenovacao($agora)) {
            throw LinkExternoDoPushException::pedidoIndisponivel();
        }

        $this->renovacaoPedidaEm = $agora;
        $this->renovacaoNome = self::textoOuNulo($nome, 60);
        $this->renovacaoMensagem = self::textoOuNulo($mensagem, 280);
        $this->renovacaoResposta = null;
    }

    /**
     * Confere o código digitado em tempo constante. Acerto zera as tentativas; erro soma uma e, na
     * quinta seguida, bloqueia o link por 15 minutos. Bloqueado, nem o código certo passa.
     */
    public function conferirCodigo(string $digitado, \DateTimeImmutable $agora): bool
    {
        if ($this->codigo === null) {
            return true;
        }
        if ($this->estaBloqueado($agora)) {
            return false;
        }
        if (hash_equals($this->codigo, $digitado)) {
            $this->tentativasCodigo = 0;
            $this->bloqueadoAte = null;

            return true;
        }

        $this->registrarErroDeCodigo($agora);

        return false;
    }

    private function registrarErroDeCodigo(\DateTimeImmutable $agora): void
    {
        ++$this->tentativasCodigo;
        if ($this->tentativasCodigo >= self::MAXIMO_DE_TENTATIVAS) {
            $this->bloqueadoAte = $agora->modify(self::BLOQUEIO);
            $this->tentativasCodigo = 0;
        }
    }

    private function exigirRenovavel(\DateTimeImmutable $agora): void
    {
        if ($this->estaRevogado()) {
            throw LinkExternoDoPushException::renovarRevogado();
        }
        if (!$this->estaExpirado($agora)) {
            throw LinkExternoDoPushException::renovarAtivo();
        }
    }

    private static function textoOuNulo(?string $texto, int $limite): ?string
    {
        // Vem do público: byte UTF-8 inválido derrubaria o flush no PostgreSQL.
        $texto = trim(mb_scrub((string) $texto, 'UTF-8'));

        return $texto === '' ? null : mb_substr($texto, 0, $limite);
    }

    // ---------------------------------------------------------------------------------------
    // Leitura
    // ---------------------------------------------------------------------------------------

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getPasta(): Pasta
    {
        return $this->pasta;
    }

    public function getProcesso(): Processo
    {
        return $this->processo;
    }

    public function getEndereco(): string
    {
        return $this->endereco;
    }

    public function getCodigo(): ?string
    {
        return $this->codigo;
    }

    public function mostraCliente(): bool
    {
        return $this->mostrarCliente;
    }

    public function incluiAnalises(): bool
    {
        return $this->incluirAnalises;
    }

    public function getCriadoPor(): User
    {
        return $this->criadoPor;
    }

    public function getCriadoEm(): \DateTimeImmutable
    {
        return $this->criadoEm;
    }

    public function getExpiraEm(): \DateTimeImmutable
    {
        return $this->expiraEm;
    }

    public function getRevogadoEm(): ?\DateTimeImmutable
    {
        return $this->revogadoEm;
    }

    public function getRevogadoPor(): ?User
    {
        return $this->revogadoPor;
    }

    public function getRenovacaoPedidaEm(): ?\DateTimeImmutable
    {
        return $this->renovacaoPedidaEm;
    }

    public function getRenovacaoNome(): ?string
    {
        return $this->renovacaoNome;
    }

    public function getRenovacaoMensagem(): ?string
    {
        return $this->renovacaoMensagem;
    }

    public function getRenovacaoResposta(): ?string
    {
        return $this->renovacaoResposta;
    }

    public function getTentativasCodigo(): int
    {
        return $this->tentativasCodigo;
    }

    public function getBloqueadoAte(): ?\DateTimeImmutable
    {
        return $this->bloqueadoAte;
    }
}
