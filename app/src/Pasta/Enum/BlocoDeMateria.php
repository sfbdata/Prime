<?php

declare(strict_types=1);

namespace App\Pasta\Enum;

/** Os 5 blocos recolhíveis da coluna esquerda do banco de modelos (desenho 02 de 27/10), na ordem do desenho. */
enum BlocoDeMateria: string
{
    case ContenciosoCivel = 'contencioso_civel';
    case TrabalhoEPrevidencia = 'trabalho_previdencia';
    case EmpresarialETributario = 'empresarial_tributario';
    case Penal = 'penal';
    case ExecucaoERecursos = 'execucao_recursos';

    public function rotulo(): string
    {
        return match ($this) {
            self::ContenciosoCivel => 'Contencioso cível',
            self::TrabalhoEPrevidencia => 'Trabalho e previdência',
            self::EmpresarialETributario => 'Empresarial e tributário',
            self::Penal => 'Penal',
            self::ExecucaoERecursos => 'Execução e recursos',
        };
    }

    /** @return list<MateriaDeModelo> na ordem do desenho */
    public function materias(): array
    {
        return array_values(array_filter(
            MateriaDeModelo::cases(),
            fn (MateriaDeModelo $materia): bool => $materia->bloco() === $this,
        ));
    }
}
