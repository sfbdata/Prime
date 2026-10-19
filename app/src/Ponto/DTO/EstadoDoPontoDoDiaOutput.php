<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

/**
 * O ponto de hoje da própria pessoa, para o atalho do cabeçalho (spec `presenca-e-ponto-no-cabecalho.md` §3). Só
 * leitura: nada aqui decide se a batida vale, isso é do servidor na batida.
 */
final class EstadoDoPontoDoDiaOutput
{
    public const BATER_ENTRADA = 'bater_entrada';
    public const EM_INTERVALO = 'em_intervalo';
    public const EM_JORNADA = 'em_jornada';
    public const JORNADA_CONCLUIDA = 'jornada_concluida';

    /**
     * @param array{entrada: ?string, repouso: ?string, retorno: ?string, saida: ?string} $hoje horários H:i da escolha da folha
     * @param list<array{nome: string, latitude: float, longitude: float, raio: int}> $sedes
     */
    public function __construct(
        public readonly string $dia,
        public readonly string $estado,
        public readonly array $hoje,
        public readonly bool $homeOffice,
        public readonly array $sedes,
        public readonly bool $interjornadaLigada,
    ) {
    }
}
