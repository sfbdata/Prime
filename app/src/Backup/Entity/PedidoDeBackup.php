<?php

declare(strict_types=1);

namespace App\Backup\Entity;

use App\Backup\DTO\ResultadoDoBackup;
use App\Backup\Enum\SituacaoDoBackup;
use App\Backup\Repository\PedidoDeBackupRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * Um pedido de backup de uma pessoa num escritório (spec backup-e-exportacao-de-dados, seção 3). Guarda o estado da
 * montagem em segundo plano, o dono, a validade e a chave do ZIP no armazenamento (categoria `BACKUP_ZIP`, escopo do
 * escritório do pedido; a coluna guarda só o nome).
 *
 * Ciclo: pedido, montando, pronto (até `expira_em`) e vencido; ou falhou. Só quem pediu, no mesmo escritório, baixa
 * ({@see podeSerBaixadoPor()}). Não é `Auditavel`: o worker grava sem usuário logado, e o pedido e o download são
 * auditados à mão pelos UseCases (ações `backup` e `backup_dl`).
 */
#[ORM\Entity(repositoryClass: PedidoDeBackupRepository::class)]
#[ORM\Table(name: 'pedido_de_backup')]
#[ORM\Index(name: 'idx_pedido_de_backup_pessoa', columns: ['tenant_id', 'usuario_id', 'pedido_em'])]
class PedidoDeBackup implements TenantAware
{
    /** Tamanho da coluna `erro`: o motivo é curto e nunca leva dado de cliente. */
    public const TAMANHO_DO_ERRO = 255;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 20, enumType: SituacaoDoBackup::class)]
    private SituacaoDoBackup $situacao = SituacaoDoBackup::Pedido;

    #[ORM\Column(name: 'iniciado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $iniciadoEm = null;

    #[ORM\Column(name: 'concluido_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $concluidoEm = null;

    #[ORM\Column(name: 'expira_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $expiraEm = null;

    /** Só o nome da chave `BACKUP_ZIP`; o escopo é o escritório do pedido. Nulo antes de pronto e depois de vencido. */
    #[ORM\Column(name: 'chave_arquivo', length: 255, nullable: true)]
    private ?string $chaveArquivo = null;

    #[ORM\Column(name: 'tamanho_bytes', type: 'bigint', nullable: true)]
    private ?int $tamanhoBytes = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sha256 = null;

    #[ORM\Column(name: 'arquivos_incluidos', type: 'integer')]
    private int $arquivosIncluidos = 0;

    #[ORM\Column(name: 'arquivos_fora', type: 'integer')]
    private int $arquivosFora = 0;

    #[ORM\Column(length: self::TAMANHO_DO_ERRO, nullable: true)]
    private ?string $erro = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_id', nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
        #[ORM\Column(name: 'pedido_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $pedidoEm,
    ) {
    }

    /** O worker pegou o pedido. */
    public function iniciarMontagem(\DateTimeImmutable $em): void
    {
        $this->exigirSituacao([SituacaoDoBackup::Pedido], 'Só um pedido na fila começa a ser montado.');
        $this->situacao = SituacaoDoBackup::Montando;
        $this->iniciadoEm = $em;
    }

    /** O ZIP está gravado no armazenamento e pode ser baixado até `$expiraEm`. */
    public function concluir(ResultadoDoBackup $resultado, \DateTimeImmutable $em, \DateTimeImmutable $expiraEm): void
    {
        $this->exigirSituacao([SituacaoDoBackup::Montando], 'Só um pedido em preparo pode ficar pronto.');
        if ($expiraEm <= $em) {
            throw new \DomainException('A validade do backup precisa ser depois da conclusão.');
        }
        $this->situacao = SituacaoDoBackup::Pronto;
        $this->chaveArquivo = $resultado->chaveArquivo;
        $this->tamanhoBytes = $resultado->tamanhoBytes;
        $this->sha256 = $resultado->sha256;
        $this->arquivosIncluidos = $resultado->arquivosIncluidos;
        $this->arquivosFora = $resultado->arquivosFora;
        $this->concluidoEm = $em;
        $this->expiraEm = $expiraEm;
    }

    /** A montagem não deu certo. O motivo é curto e sem dado de cliente. */
    public function falhar(string $motivo, \DateTimeImmutable $em): void
    {
        $this->exigirSituacao([SituacaoDoBackup::Pedido, SituacaoDoBackup::Montando], 'Este pedido já terminou.');
        $this->situacao = SituacaoDoBackup::Falhou;
        $this->erro = mb_substr(trim($motivo), 0, self::TAMANHO_DO_ERRO);
        $this->concluidoEm = $em;
    }

    /**
     * Passou da validade: o pedido deixa de apontar para o ZIP. Devolve o nome da chave para quem vence apagar o
     * arquivo depois do COMMIT.
     */
    public function vencer(\DateTimeImmutable $agora): string
    {
        $this->exigirSituacao([SituacaoDoBackup::Pronto], 'Só um backup pronto vence.');
        if (!$this->venceuEm($agora)) {
            throw new \DomainException('Este backup ainda está dentro da validade.');
        }
        $chave = (string) $this->chaveArquivo;
        $this->situacao = SituacaoDoBackup::Vencido;
        $this->chaveArquivo = null;

        return $chave;
    }

    public function venceuEm(\DateTimeImmutable $agora): bool
    {
        return SituacaoDoBackup::Pronto === $this->situacao && null !== $this->expiraEm && $agora >= $this->expiraEm;
    }

    /** Só quem pediu, no mesmo escritório, com o backup pronto e dentro da validade. */
    public function podeSerBaixadoPor(User $usuario, Tenant $tenant, \DateTimeImmutable $agora): bool
    {
        return SituacaoDoBackup::Pronto === $this->situacao
            && null !== $this->chaveArquivo
            && !$this->venceuEm($agora)
            && null !== $this->usuario->getId() && $this->usuario->getId() === $usuario->getId()
            && null !== $this->tenant->getId() && $this->tenant->getId() === $tenant->getId();
    }

    /** @param list<SituacaoDoBackup> $permitidas */
    private function exigirSituacao(array $permitidas, string $mensagem): void
    {
        if (!\in_array($this->situacao, $permitidas, true)) {
            throw new \DomainException($mensagem);
        }
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTenant(): Tenant
    {
        return $this->tenant;
    }

    public function getUsuario(): User
    {
        return $this->usuario;
    }

    public function getSituacao(): SituacaoDoBackup
    {
        return $this->situacao;
    }

    public function getPedidoEm(): \DateTimeImmutable
    {
        return $this->pedidoEm;
    }

    public function getIniciadoEm(): ?\DateTimeImmutable
    {
        return $this->iniciadoEm;
    }

    public function getConcluidoEm(): ?\DateTimeImmutable
    {
        return $this->concluidoEm;
    }

    public function getExpiraEm(): ?\DateTimeImmutable
    {
        return $this->expiraEm;
    }

    public function getChaveArquivo(): ?string
    {
        return $this->chaveArquivo;
    }

    public function getTamanhoBytes(): ?int
    {
        return $this->tamanhoBytes;
    }

    public function getSha256(): ?string
    {
        return $this->sha256;
    }

    public function getArquivosIncluidos(): int
    {
        return $this->arquivosIncluidos;
    }

    public function getArquivosFora(): int
    {
        return $this->arquivosFora;
    }

    public function getErro(): ?string
    {
        return $this->erro;
    }
}
