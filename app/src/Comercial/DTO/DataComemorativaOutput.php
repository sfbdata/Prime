<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

use App\Comercial\Campanha\CalendarioDasDatasComemorativas as Calendario;

/**
 * Uma linha das "Datas comemorativas" (desenho 05 VS 21.10.26 L337 e L680): o estado e as contas do dc. As datas vão
 * em `Y-m-d` e o dia da semana em número (0 = domingo); a tela escreve "qui · 25/12" e "em N dias · envio dd/mm às 9h".
 */
final class DataComemorativaOutput
{
    public function __construct(
        public readonly string $chave,
        public readonly string $nome,
        public readonly string $icone,
        public readonly bool $ligada,
        public readonly int $antecedencia,
        public readonly string $publico,
        public readonly string $data,
        public readonly int $diaDaSemana,
        public readonly int $diasQueFaltam,
        public readonly string $envio,
        /** A 7 dias ou menos: o "em N dias" sai em laranja (dc L680, `falta <= 7`). */
        public readonly bool $perto,
    ) {
    }

    /**
     * A linha da data a partir de hoje: a próxima ocorrência (hoje incluído), os dias que faltam e o dia do envio.
     *
     * @param array{ligada: bool, antecedencia: int, publico: string} $estado
     */
    public static function deEstado(string $chave, array $estado, \DateTimeImmutable $hoje): self
    {
        $dia = Calendario::diaLocal($hoje);
        $data = Calendario::proxima($chave, $dia);
        $falta = (int) $dia->diff($data)->days;
        $info = Calendario::data($chave);

        return new self(
            $chave,
            $info['nome'],
            $info['icone'],
            $estado['ligada'],
            $estado['antecedencia'],
            $estado['publico'],
            $data->format('Y-m-d'),
            (int) $data->format('w'),
            $falta,
            Calendario::envio($data, $estado['antecedencia'])->format('Y-m-d'),
            $falta <= 7,
        );
    }

    /** @return array<string, string|int|bool> */
    public function paraArray(): array
    {
        return get_object_vars($this);
    }
}
