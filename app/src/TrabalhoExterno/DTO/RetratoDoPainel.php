<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\DTO;

use App\TrabalhoExterno\Entity\Curriculo;
use App\TrabalhoExterno\Entity\Vaga;

/**
 * Tudo de que o painel do Master precisa (spec VS 18.10.26 §4.9), já lido do banco pelo
 * `MontarPainelDeCurriculosUseCase`. A conta dos KPIs, das barras e do crescimento fica em
 * `PainelDoBancoDeTalentos`, sem banco.
 */
final readonly class RetratoDoPainel
{
    /**
     * @param list<Curriculo>                                                  $curriculos    o banco inteiro, arquivados inclusive
     * @param list<Vaga>                                                       $vagas         todas as vagas da Central
     * @param list<array{status: string, enviadaEm: \DateTimeImmutable}>      $candidaturas  status e envio de cada candidatura
     * @param array<int, int>                                                  $aceitesPorVaga id da oferta → aceites da rodada atual
     * @param float|null                                                       $minutosAteOAceite média do início da rodada ao primeiro aceite
     * @param array<int, string>                                               $termos        id do currículo → estado do termo mais recente (`enviado` ou `aceito`)
     */
    public function __construct(
        public \DateTimeImmutable $agora,
        public array $curriculos = [],
        public array $vagas = [],
        public array $candidaturas = [],
        public array $aceitesPorVaga = [],
        public ?float $minutosAteOAceite = null,
        public int $inscritos = 0,
        public int $visitas = 0,
        public int $cliques = 0,
        public array $termos = [],
    ) {
    }
}
