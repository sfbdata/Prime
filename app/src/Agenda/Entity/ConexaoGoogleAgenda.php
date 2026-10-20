<?php

declare(strict_types=1);

namespace App\Agenda\Entity;

use App\Agenda\Repository\ConexaoGoogleAgendaRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Shared\Contract\TenantAware;
use Doctrine\ORM\Mapping as ORM;

/**
 * A conta Google de uma pessoa num escritório (entrega 6, modelo do dono de 20/10/2026). O BlueJus trabalha só
 * na agenda secundária que criou nessa conta, com o nome do escritório (`calendarioId`); a agenda principal
 * da pessoa não é lida. Guarda só o refresh token, cifrado; o access token vive na memória da sincronia. O
 * `syncToken` do Google permite trazer só o que mudou nessa agenda.
 */
#[ORM\Entity(repositoryClass: ConexaoGoogleAgendaRepository::class)]
#[ORM\Table(name: 'agenda_google_conexao')]
#[ORM\UniqueConstraint(name: 'uniq_agenda_google_conexao_pessoa', columns: ['usuario_id', 'tenant_id'])]
class ConexaoGoogleAgenda implements TenantAware
{
    public const ERRO_AUTORIZACAO = 'autorizacao_recusada';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    /** A agenda do escritório na conta (criada pela sincronia; nula até lá ou depois de sumir no Google). */
    #[ORM\Column(name: 'calendario_id', length: 1024, nullable: true)]
    private ?string $calendarioId = null;

    #[ORM\Column(name: 'sync_token', type: 'text', nullable: true)]
    private ?string $syncToken = null;

    #[ORM\Column(name: 'ultima_sincronia', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $ultimaSincronia = null;

    #[ORM\Column(name: 'ocupada_desde', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $ocupadaDesde = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $erro = null;

    #[ORM\Column(name: 'conectada_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $conectadaEm;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Tenant::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private Tenant $tenant,
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'usuario_id', nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
        #[ORM\Column(name: 'refresh_token_cifrado', type: 'text')]
        private string $refreshTokenCifrado,
        #[ORM\Column(length: 255)]
        private string $conta,
        #[ORM\Column(length: 64)]
        private string $fuso,
    ) {
        $this->conectadaEm = new \DateTimeImmutable();
    }

    /**
     * Reconectar renova a autorização. Com outra conta, a agenda do escritório é outra (criada de novo na conta
     * nova); com a mesma, segue a mesma agenda. Nos dois casos a sincronia recomeça do zero.
     */
    public function reconectar(string $refreshTokenCifrado, string $conta, string $fuso): void
    {
        if (0 !== strcasecmp($this->conta, $conta)) {
            $this->calendarioId = null;
        }
        $this->refreshTokenCifrado = $refreshTokenCifrado;
        $this->conta = $conta;
        $this->fuso = $fuso;
        $this->syncToken = null;
        $this->erro = null;
        $this->conectadaEm = new \DateTimeImmutable();
    }

    public function sincronizou(?string $syncToken, \DateTimeImmutable $quando): void
    {
        $this->syncToken = $syncToken;
        $this->ultimaSincronia = $quando;
        $this->erro = null;
    }

    public function falhou(string $erro): void
    {
        $this->erro = mb_substr($erro, 0, 255);
    }

    public function recomecar(): void
    {
        $this->syncToken = null;
    }

    public function agendaCriada(string $calendarioId): void
    {
        $this->calendarioId = $calendarioId;
        $this->syncToken = null;
    }

    /** A agenda do escritório sumiu no Google (a pessoa apagou): a próxima sincronia cria outra. */
    public function agendaSumiu(): void
    {
        $this->calendarioId = null;
        $this->syncToken = null;
    }

    public function getCalendarioId(): ?string
    {
        return $this->calendarioId;
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

    public function getRefreshTokenCifrado(): string
    {
        return $this->refreshTokenCifrado;
    }

    public function getConta(): string
    {
        return $this->conta;
    }

    public function getFuso(): string
    {
        return $this->fuso;
    }

    public function getSyncToken(): ?string
    {
        return $this->syncToken;
    }

    public function getUltimaSincronia(): ?\DateTimeImmutable
    {
        return $this->ultimaSincronia;
    }

    public function getErro(): ?string
    {
        return $this->erro;
    }

    /** Autorização recusada pelo Google (revogada na conta): o painel volta a "Não conectado". */
    public function precisaReconectar(): bool
    {
        return self::ERRO_AUTORIZACAO === $this->erro;
    }
}
