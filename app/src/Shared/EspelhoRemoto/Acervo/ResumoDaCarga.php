<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Acervo;

/** O que uma carga do acervo fez. Só contagens: nada de nome de arquivo nem chave remota. */
final class ResumoDaCarga
{
    public int $vistos = 0;
    public int $enfileirados = 0;
    public int $jaNaFilaOuVerificados = 0;
    public int $locaisAusentes = 0;
    public int $chavesInvalidas = 0;
    public int $erros = 0;

    /** @return array<string, int> */
    public function comoLista(): array
    {
        return [
            'referências vistas'           => $this->vistos,
            'enfileiradas'                 => $this->enfileirados,
            'já na fila ou verificadas'    => $this->jaNaFilaOuVerificados,
            'ausentes no disco local'      => $this->locaisAusentes,
            'chave inválida'               => $this->chavesInvalidas,
            'erro de leitura'              => $this->erros,
        ];
    }
}
