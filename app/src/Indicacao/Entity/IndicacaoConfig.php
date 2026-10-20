<?php

declare(strict_types=1);

namespace App\Indicacao\Entity;

use App\Entity\Auth\User;
use App\Indicacao\Repository\IndicacaoConfigRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Os limites das indicações (spec `indicar-bluejus.md`): uma linha só (id 1, criada pela migration com o padrão do
 * desenho: 20 por dia, 200 por mês, alerta em 30 por 24h). Só o Master altera.
 */
#[ORM\Entity(repositoryClass: IndicacaoConfigRepository::class)]
#[ORM\Table(name: 'indicacao_config')]
class IndicacaoConfig
{
    public const LIMITE_DIA_PADRAO = 20;
    public const LIMITE_MES_PADRAO = 200;
    public const ALERTA_24H_PADRAO = 30;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    private int $id = 1;

    #[ORM\Column(name: 'pausado_para_todos', type: 'boolean')]
    private bool $pausadoParaTodos = false;

    #[ORM\Column(name: 'limite_dia', type: 'integer')]
    private int $limiteDia = self::LIMITE_DIA_PADRAO;

    #[ORM\Column(name: 'limite_mes', type: 'integer')]
    private int $limiteMes = self::LIMITE_MES_PADRAO;

    #[ORM\Column(name: 'alerta_24h', type: 'integer')]
    private int $alerta24h = self::ALERTA_24H_PADRAO;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'alterado_por_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $alteradoPor = null;

    #[ORM\Column(name: 'alterado_em', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $alteradoEm = null;

    public function isPausadoParaTodos(): bool
    {
        return $this->pausadoParaTodos;
    }

    public function getLimiteDia(): int
    {
        return $this->limiteDia;
    }

    public function getLimiteMes(): int
    {
        return $this->limiteMes;
    }

    public function getAlerta24h(): int
    {
        return $this->alerta24h;
    }

    public function getAlteradoPor(): ?User
    {
        return $this->alteradoPor;
    }

    public function getAlteradoEm(): ?\DateTimeImmutable
    {
        return $this->alteradoEm;
    }

    public function alterarLimites(int $porDia, int $porMes, int $alertaEm24h, User $por, \DateTimeImmutable $agora): void
    {
        if ($porDia < 1 || $porMes < 1 || $alertaEm24h < 1) {
            throw new \InvalidArgumentException('Os limites precisam ser de pelo menos 1.');
        }
        $this->limiteDia = $porDia;
        $this->limiteMes = $porMes;
        $this->alerta24h = $alertaEm24h;
        $this->registrarAlteracao($por, $agora);
    }

    public function pausarParaTodos(bool $pausar, User $por, \DateTimeImmutable $agora): void
    {
        $this->pausadoParaTodos = $pausar;
        $this->registrarAlteracao($por, $agora);
    }

    private function registrarAlteracao(User $por, \DateTimeImmutable $agora): void
    {
        $this->alteradoPor = $por;
        $this->alteradoEm = $agora;
    }
}
