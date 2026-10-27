<?php

declare(strict_types=1);

namespace App\Recompensa\Entity;

use App\Entity\Auth\User;
use App\Recompensa\Exception\RecompensaRecusada;
use App\Recompensa\Repository\CondicaoDeRecompensaRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Condição individual (★) de um indicador: valor, "libera com" e carência próprios, que NÃO mudam quando a regra geral
 * muda (desenho 05 Perfis 1.2 VS 25.10.26, "Condição individual"; spec perfis-1.2-vs-25-10-recompensas-e-planos, F1).
 * Campo nulo usa a regra geral ({@see ProgramaDeRecompensa}). A recorrência (crédito mensal por escritório ativo) só é
 * guardada aqui; quem lança o crédito mensal é a F6.
 *
 * F3 (antifraude): a mesma linha guarda se a recompensa do indicador está bloqueada (`bloqueadoEm`) e se ele foi apagado da
 * lista (`apagadoEm`). Os dois independem da condição individual, então "voltar à regra geral" não os desfaz.
 *
 * GLOBAL, sem tenant: a chave é o usuário que indica (PK = user_id, apagada junto com ele). Dinheiro em centavos.
 */
#[ORM\Entity(repositoryClass: CondicaoDeRecompensaRepository::class)]
#[ORM\Table(name: 'condicao_de_recompensa')]
#[ORM\Index(name: 'idx_condicao_de_recompensa_alterada_por', columns: ['alterada_por_id'])]
class CondicaoDeRecompensa implements Auditavel
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $valorCentavos = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $minEscritorios = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $carenciaDias = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $recorrente = false;

    #[ORM\Column(type: 'integer', nullable: true)]
    private ?int $mensalCentavos = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $motivo = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $bloqueadoEm = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $apagadoEm = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'alterada_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $alteradaPor = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $alteradaEm;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->alteradaEm = new \DateTimeImmutable();
    }

    /** Vazio (nulo) volta o campo para a regra geral. Recorrente exige o valor mensal. */
    public function definir(?int $valorCentavos, ?int $minEscritorios, ?int $carenciaDias, bool $recorrente, ?int $mensalCentavos, ?string $motivo): void
    {
        if ($valorCentavos !== null && ($valorCentavos < 0 || $valorCentavos > ProgramaDeRecompensa::VALOR_MAXIMO)) {
            throw new RecompensaRecusada('Informe valores válidos ou deixe em branco para usar a regra geral.');
        }
        if ($minEscritorios !== null && ($minEscritorios < 1 || $minEscritorios > ProgramaDeRecompensa::LIMITE_CONTAGEM)) {
            throw new RecompensaRecusada('Informe valores válidos ou deixe em branco para usar a regra geral.');
        }
        if ($carenciaDias !== null && ($carenciaDias < 0 || $carenciaDias > ProgramaDeRecompensa::CARENCIA_MAXIMA)) {
            throw new RecompensaRecusada('Informe valores válidos ou deixe em branco para usar a regra geral.');
        }
        if ($recorrente && ($mensalCentavos === null || $mensalCentavos <= 0 || $mensalCentavos > ProgramaDeRecompensa::VALOR_MAXIMO)) {
            throw new RecompensaRecusada('Informe o valor mensal por escritório para o parceiro recorrente.');
        }
        $motivo = $motivo === null ? null : trim($motivo);
        if ($motivo !== null && mb_strlen($motivo) > 255) {
            throw new RecompensaRecusada('O motivo deve ter até 255 caracteres.');
        }

        $this->valorCentavos = $valorCentavos;
        $this->minEscritorios = $minEscritorios;
        $this->carenciaDias = $carenciaDias;
        $this->recorrente = $recorrente;
        $this->mensalCentavos = $recorrente ? $mensalCentavos : null;
        $this->motivo = $motivo === '' ? null : $motivo;
    }

    /** Sem condição própria, recorrência, bloqueio nem apagado a linha não muda nada: o UseCase a descarta. */
    public function semEfeito(): bool
    {
        return !$this->temCondicaoPropria() && $this->bloqueadoEm === null && $this->apagadoEm === null;
    }

    public function temCondicaoPropria(): bool
    {
        return $this->valorCentavos !== null || $this->minEscritorios !== null || $this->carenciaDias !== null || $this->recorrente;
    }

    /** "Voltar à regra geral": zera só a condição individual; bloqueio e apagado continuam. */
    public function voltarParaARegraGeral(): void
    {
        $this->definir(null, null, null, false, null, null);
    }

    public function bloquear(\DateTimeImmutable $em): void
    {
        $this->bloqueadoEm ??= $em;
    }

    public function desbloquear(): void
    {
        $this->bloqueadoEm = null;
    }

    public function apagar(\DateTimeImmutable $em): void
    {
        $this->apagadoEm ??= $em;
    }

    public function isBloqueada(): bool
    {
        return $this->bloqueadoEm !== null;
    }

    public function isApagada(): bool
    {
        return $this->apagadoEm !== null;
    }

    public function getBloqueadoEm(): ?\DateTimeImmutable
    {
        return $this->bloqueadoEm;
    }

    public function getApagadoEm(): ?\DateTimeImmutable
    {
        return $this->apagadoEm;
    }

    public function registrarAlteracao(?User $por, \DateTimeImmutable $em): void
    {
        $this->alteradaPor = $por;
        $this->alteradaEm = $em;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getUserId(): int
    {
        return (int) $this->user->getId();
    }

    public function getValorCentavos(): ?int
    {
        return $this->valorCentavos;
    }

    public function getMinEscritorios(): ?int
    {
        return $this->minEscritorios;
    }

    public function getCarenciaDias(): ?int
    {
        return $this->carenciaDias;
    }

    public function isRecorrente(): bool
    {
        return $this->recorrente;
    }

    public function getMensalCentavos(): ?int
    {
        return $this->mensalCentavos;
    }

    public function getMotivo(): ?string
    {
        return $this->motivo;
    }

    public function getAlteradaPor(): ?User
    {
        return $this->alteradaPor;
    }

    public function getAlteradaEm(): \DateTimeImmutable
    {
        return $this->alteradaEm;
    }
}
