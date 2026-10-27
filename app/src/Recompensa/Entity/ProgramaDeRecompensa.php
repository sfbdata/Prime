<?php

declare(strict_types=1);

namespace App\Recompensa\Entity;

use App\Entity\Auth\User;
use App\Recompensa\Exception\RecompensaRecusada;
use App\Recompensa\Repository\ProgramaDeRecompensaRepository;
use App\Shared\Contract\Auditavel;
use Doctrine\ORM\Mapping as ORM;

/**
 * Regra geral do programa "Indique e ganhe" (seção Recompensas do /master; desenho 05 Perfis 1.2 VS 25.10.26, bloco
 * "Regras do programa"; spec docs/specs/perfis-1.2-vs-25-10-recompensas-e-planos.md, F1). Linha ÚNICA (id 1), escrita só
 * pelo Master.
 *
 * GLOBAL, sem tenant, de propósito: é configuração da PLATAFORMA, como a {@see \App\Master\Entity\MetaDeDivulgacao}.
 * Dinheiro em centavos inteiros. Sem a linha valem os padrões das constantes. Auditável: o AuditLogSubscriber grava cada
 * mudança, no escopo de plataforma.
 */
#[ORM\Entity(repositoryClass: ProgramaDeRecompensaRepository::class)]
#[ORM\Table(name: 'programa_de_recompensa')]
#[ORM\Index(name: 'idx_programa_de_recompensa_alterado_por', columns: ['alterado_por_id'])]
class ProgramaDeRecompensa implements Auditavel
{
    public const ID_UNICO = 1;

    public const VALOR_PADRAO = 1000;
    public const MIN_PADRAO = 10;
    public const CARENCIA_PADRAO = 30;
    public const ALERTA_PADRAO = 5;

    public const VALOR_MAXIMO = 10000000;
    public const LIMITE_CONTAGEM = 100000;
    public const CARENCIA_MAXIMA = 3650;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = self::ID_UNICO;

    #[ORM\Column(options: ['default' => true])]
    private bool $ativo = true;

    #[ORM\Column(type: 'integer', options: ['default' => self::VALOR_PADRAO])]
    private int $valorCentavos = self::VALOR_PADRAO;

    #[ORM\Column(type: 'integer', options: ['default' => self::MIN_PADRAO])]
    private int $minEscritorios = self::MIN_PADRAO;

    #[ORM\Column(type: 'integer', options: ['default' => self::CARENCIA_PADRAO])]
    private int $carenciaDias = self::CARENCIA_PADRAO;

    #[ORM\Column(type: 'integer', options: ['default' => self::ALERTA_PADRAO])]
    private int $alertaQtd = self::ALERTA_PADRAO;

    #[ORM\Column(options: ['default' => true])]
    private bool $exigePasta = true;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'alterado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $alteradoPor = null;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $alteradoEm;

    public function __construct()
    {
        $this->alteradoEm = new \DateTimeImmutable();
    }

    /** Grava as seis regras de uma vez; qualquer valor fora do intervalo recusa e não muda nada. */
    public function alterarRegras(bool $ativo, int $valorCentavos, int $minEscritorios, int $carenciaDias, int $alertaQtd, bool $exigePasta): void
    {
        if ($valorCentavos < 0 || $valorCentavos > self::VALOR_MAXIMO) {
            throw new RecompensaRecusada('A recompensa por escritório deve ficar entre R$ 0,00 e R$ 100.000,00.');
        }
        if ($minEscritorios < 1 || $minEscritorios > self::LIMITE_CONTAGEM) {
            throw new RecompensaRecusada('O número de escritórios ativos para liberar deve ser de 1 a ' . self::LIMITE_CONTAGEM . '.');
        }
        if ($carenciaDias < 0 || $carenciaDias > self::CARENCIA_MAXIMA) {
            throw new RecompensaRecusada('A carência deve ser de 0 a ' . self::CARENCIA_MAXIMA . ' dias.');
        }
        if ($alertaQtd < 1 || $alertaQtd > self::LIMITE_CONTAGEM) {
            throw new RecompensaRecusada('O alerta antifraude deve ser de 1 a ' . self::LIMITE_CONTAGEM . ' contas.');
        }

        $this->ativo = $ativo;
        $this->valorCentavos = $valorCentavos;
        $this->minEscritorios = $minEscritorios;
        $this->carenciaDias = $carenciaDias;
        $this->alertaQtd = $alertaQtd;
        $this->exigePasta = $exigePasta;
    }

    public function registrarAlteracao(?User $por, \DateTimeImmutable $em): void
    {
        $this->alteradoPor = $por;
        $this->alteradoEm = $em;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function isAtivo(): bool
    {
        return $this->ativo;
    }

    public function getValorCentavos(): int
    {
        return $this->valorCentavos;
    }

    public function getMinEscritorios(): int
    {
        return $this->minEscritorios;
    }

    public function getCarenciaDias(): int
    {
        return $this->carenciaDias;
    }

    public function getAlertaQtd(): int
    {
        return $this->alertaQtd;
    }

    public function isExigePasta(): bool
    {
        return $this->exigePasta;
    }

    public function getAlteradoPor(): ?User
    {
        return $this->alteradoPor;
    }

    public function getAlteradoEm(): \DateTimeImmutable
    {
        return $this->alteradoEm;
    }
}
