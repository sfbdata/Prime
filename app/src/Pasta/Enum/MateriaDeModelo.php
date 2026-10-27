<?php

declare(strict_types=1);

namespace App\Pasta\Enum;

/**
 * As 16 matérias do banco de modelos de petições (desenho 02 de 27/10, `bj-modelos.js` `MAT0`), na
 * ordem do desenho. A lista é fixa no M1: o menu de botão direito que cria e renomeia matérias fica
 * fora (spec `modelos-pasta-desenho-02.md`). Cada matéria mora em exatamente um dos 5 blocos da
 * coluna esquerda.
 */
enum MateriaDeModelo: string
{
    case Civel = 'civel';
    case Consumidor = 'consumidor';
    case Condominial = 'condominial';
    case Familia = 'familia';
    case Sucessoes = 'sucessoes';
    case Trabalhista = 'trabalhista';
    case Previdenciario = 'previdenciario';
    case Tributario = 'tributario';
    case Empresarial = 'empresarial';
    case Criminal = 'criminal';
    case Administrativo = 'administrativo';
    case Imobiliario = 'imobiliario';
    case Bancario = 'bancario';
    case Saude = 'saude';
    case ExecucaoECobranca = 'execucao_cobranca';
    case Recursos = 'recursos';

    public function rotulo(): string
    {
        return match ($this) {
            self::Civel => 'Cível',
            self::Consumidor => 'Consumidor',
            self::Condominial => 'Condominial',
            self::Familia => 'Família',
            self::Sucessoes => 'Sucessões',
            self::Trabalhista => 'Trabalhista',
            self::Previdenciario => 'Previdenciário',
            self::Tributario => 'Tributário',
            self::Empresarial => 'Empresarial',
            self::Criminal => 'Criminal',
            self::Administrativo => 'Administrativo',
            self::Imobiliario => 'Imobiliário',
            self::Bancario => 'Bancário',
            self::Saude => 'Saúde',
            self::ExecucaoECobranca => 'Execução e cobrança',
            self::Recursos => 'Recursos',
        };
    }

    public function bloco(): BlocoDeMateria
    {
        return match ($this) {
            self::Civel, self::Consumidor, self::Condominial, self::Familia,
            self::Sucessoes, self::Imobiliario, self::Bancario, self::Saude => BlocoDeMateria::ContenciosoCivel,
            self::Trabalhista, self::Previdenciario => BlocoDeMateria::TrabalhoEPrevidencia,
            self::Tributario, self::Empresarial, self::Administrativo => BlocoDeMateria::EmpresarialETributario,
            self::Criminal => BlocoDeMateria::Penal,
            self::ExecucaoECobranca, self::Recursos => BlocoDeMateria::ExecucaoERecursos,
        };
    }
}
