<?php

declare(strict_types=1);

namespace App\Auth\Entity;

use App\Auth\Enum\StatusOab;
use App\Auth\Enum\TipoInscricaoOab;
use App\Auth\Repository\InscricaoOabRepository;
use App\Entity\Auth\User;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Uma inscrição da pessoa na OAB (desenho "07 - Meu Perfil", VS 18.10.26; spec
 * docs/specs/meu-perfil-oab-inscricoes.md). No máximo uma por UF (seccional) e exatamente uma
 * PRINCIPAL, conferidas no UseCase e garantidas no banco (UNIQUE user+uf e índice único parcial da
 * principal, criados na migration). É da pessoa, não do escritório. Os campos User.oab* são o espelho
 * da principal.
 */
#[ORM\Entity(repositoryClass: InscricaoOabRepository::class)]
#[ORM\Table(name: 'inscricao_oab')]
#[ORM\UniqueConstraint(name: 'uniq_inscricao_oab_user_uf', columns: ['user_id', 'uf'])]
#[ORM\HasLifecycleCallbacks]
class InscricaoOab implements Auditavel
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(name: 'status', length: 20, enumType: StatusOab::class)]
    private StatusOab $status = StatusOab::NaoVerificada;

    #[ORM\Column(name: 'nome_oficial', length: 255, nullable: true)]
    private ?string $nomeOficial = null;

    #[ORM\Column(name: 'verificada_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $verificadaEm = null;

    #[ORM\Column(name: 'criado_em', type: 'datetime_immutable')]
    private \DateTimeImmutable $criadoEm;

    #[ORM\Column(name: 'atualizado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $atualizadoEm = null;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
        private User $usuario,
        #[ORM\Column(name: 'numero', length: 20)]
        private string $numero,
        #[ORM\Column(name: 'uf', length: 2)]
        private string $uf,
        #[ORM\Column(name: 'tipo', length: 20, enumType: TipoInscricaoOab::class)]
        private TipoInscricaoOab $tipo,
    ) {
        $this->criadoEm = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function aoAtualizar(): void
    {
        $this->atualizadoEm = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsuario(): User
    {
        return $this->usuario;
    }

    public function getNumero(): string
    {
        return $this->numero;
    }

    public function getUf(): string
    {
        return $this->uf;
    }

    public function getTipo(): TipoInscricaoOab
    {
        return $this->tipo;
    }

    public function ehPrincipal(): bool
    {
        return $this->tipo === TipoInscricaoOab::Principal;
    }

    public function getStatus(): StatusOab
    {
        return $this->status;
    }

    public function getNomeOficial(): ?string
    {
        return $this->nomeOficial;
    }

    public function getVerificadaEm(): ?\DateTimeImmutable
    {
        return $this->verificadaEm;
    }

    /**
     * Novo número ou UF voltam a Pendente (aguardando o Master), como no desenho; os mesmos valores
     * não mexem na situação nem na data.
     */
    public function alterar(string $numero, string $uf): void
    {
        if ($numero === $this->numero && $uf === $this->uf) {
            return;
        }
        $this->numero = $numero;
        $this->uf     = $uf;
        $this->registrarVerificacao(StatusOab::NaoVerificada, null);
    }

    public function tornarPrincipal(): void
    {
        $this->tipo = TipoInscricaoOab::Principal;
    }

    public function tornarSuplementar(): void
    {
        $this->tipo = TipoInscricaoOab::Suplementar;
    }

    /** Verificada em só com veredicto (Confirmada ou Divergente), como no VerificarOabUseCase. */
    public function registrarVerificacao(StatusOab $status, ?string $nomeOficial, ?\DateTimeImmutable $quando = null): void
    {
        $this->status       = $status;
        $this->nomeOficial  = $nomeOficial;
        $this->verificadaEm = $status === StatusOab::NaoVerificada ? null : ($quando ?? new \DateTimeImmutable());
    }

    /** Copia esta inscrição para os campos User.oab* (o espelho da principal). */
    public function espelharNoUsuario(): void
    {
        $this->usuario->setOabNumero($this->numero);
        $this->usuario->setOabUf($this->uf);
        $this->usuario->setOabStatus($this->status);
        $this->usuario->setOabNomeOficial($this->nomeOficial);
        $this->usuario->setOabVerificadaEm($this->verificadaEm);
    }
}
