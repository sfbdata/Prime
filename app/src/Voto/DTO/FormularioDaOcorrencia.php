<?php

declare(strict_types=1);

namespace App\Voto\DTO;

/** O que a página "Registrar ocorrência" do eleitor mostra: a qualificação dele (só leitura), as pautas e quantas ainda pode registrar. */
final readonly class FormularioDaOcorrencia
{
    /** @param array<int, string> $pautas título por id da pauta que a unidade vê */
    public function __construct(
        public string $assembleia,
        public string $nome,
        public string $unidade,
        public array $pautas,
        public int $restantes,
        public int $maximoDoTexto,
        public int $maximoPorUnidade,
    ) {
    }
}
