<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\DTO;

/**
 * Os números de que o banner, o lembrete e as boas-vindas precisam (spec VS 18.10.26 §4.7), já
 * recortados pelo que a pessoa vê. Montado pelo `MontarEngajamentoUseCase`; a regra dos textos fica em
 * `EngajamentoDoTrabalhoExterno`, sem banco.
 */
final readonly class RetratoDoEngajamento
{
    /**
     * @param list<\DateTimeImmutable>                    $ofertasAoVivo o fim de cada oferta aberta, da que termina antes
     * @param array{pct: int, faltas: list<string>}|null $percentual    null quando a pessoa não tem currículo
     */
    public function __construct(
        public string $nome,
        public bool $interno,
        public \DateTimeImmutable $agora,
        public int $totalVagas = 0,
        public int $abertas = 0,
        public int $pendentes = 0,
        public bool $talentos = false,
        public bool $podeCriar = false,
        public array $ofertasAoVivo = [],
        public int $curriculosNaSemana = 0,
        public int $candidaturasNaSemana = 0,
        public ?float $minutosAteOAceite = null,
        public int $novasDesdeAVisita = 0,
        public ?array $percentual = null,
        public ?\DateTimeImmutable $atualizadoEm = null,
        public bool $candidatou = false,
        public bool $alertaLigado = false,
        public bool $termoParaAceitar = false,
    ) {
    }
}
