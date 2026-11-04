<?php

declare(strict_types=1);

namespace App\Master\Entity;

use App\Master\Repository\MetricaDiariaDoSistemaRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A foto diária de uma métrica do gráfico "Crescimento" do Painel do Master (desenho 05 - Perfis 1.2,
 * VS 03.11.26, visão "Diário"): quantas contas, usuários e pastas o sistema tinha no fim do dia, e quantos
 * bytes de arquivo. Uma linha por dia e métrica, gravada pela tarefa agendada `app:master:retratar-metricas`
 * (a mesma tarefa no mesmo dia regrava o valor). Os dias antes da primeira foto são reconstruídos pela data de
 * criação ({@see \App\Master\Service\CrescimentoDiario}).
 *
 * GLOBAL, sem tenant e sem dado pessoal: só números da plataforma, lidos só pelo Master.
 */
#[ORM\Entity(repositoryClass: MetricaDiariaDoSistemaRepository::class)]
#[ORM\Table(name: 'master_metrica_diaria')]
#[ORM\UniqueConstraint(name: 'uniq_master_metrica_diaria_dia', columns: ['dia', 'metrica'])]
class MetricaDiariaDoSistema
{
    public const CONTAS   = 'contas';
    public const USUARIOS = 'usuarios';
    public const PASTAS   = 'pastas';
    public const BYTES    = 'bytes';

    /** @var list<string> */
    public const METRICAS = [self::CONTAS, self::USUARIOS, self::PASTAS, self::BYTES];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    public function __construct(
        #[ORM\Column(type: 'date_immutable')]
        private \DateTimeImmutable $dia,
        #[ORM\Column(length: 20)]
        private string $metrica,
        #[ORM\Column(type: 'bigint')]
        private int $valor,
        #[ORM\Column(name: 'gravado_em', type: 'datetime_immutable')]
        private \DateTimeImmutable $gravadoEm,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDia(): \DateTimeImmutable
    {
        return $this->dia;
    }

    public function getMetrica(): string
    {
        return $this->metrica;
    }

    public function getValor(): int
    {
        return $this->valor;
    }

    public function getGravadoEm(): \DateTimeImmutable
    {
        return $this->gravadoEm;
    }
}
