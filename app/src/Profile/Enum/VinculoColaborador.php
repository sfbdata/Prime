<?php

declare(strict_types=1);

namespace App\Profile\Enum;

/**
 * Como a pessoa se liga ao escritório (desenho "07 - Meu Perfil - VS 18.10.26", VINC e vincAj).
 * Vínculo ausente vale como CLT, o padrão do desenho. "Bate ponto" aqui é só rótulo: o módulo
 * Ponto continua decidindo pela jornada cadastrada.
 */
enum VinculoColaborador: string
{
    case Clt       = 'clt';
    case Associado = 'associado';
    case Pj        = 'pj';
    case Socio     = 'socio';
    case Estagio   = 'estagio';

    /** Rótulo do botão no modal "Editar informações". */
    public function rotuloBotao(): string
    {
        return match ($this) {
            self::Clt       => 'Empregado CLT',
            self::Associado => 'Associado',
            self::Pj        => 'Prestador / PJ',
            self::Socio     => 'Sócio',
            self::Estagio   => 'Estagiário',
        };
    }

    /** Rótulo na grade de dados do perfil. */
    public function rotulo(): string
    {
        return match ($this) {
            self::Clt       => 'Empregado CLT',
            self::Associado => 'Advogado associado',
            self::Pj        => 'Prestador (PJ / autônomo)',
            self::Socio     => 'Sócio',
            self::Estagio   => 'Estagiário',
        };
    }

    public function icone(): string
    {
        return match ($this) {
            self::Clt       => 'bi-person-badge',
            self::Associado => 'bi-people',
            self::Pj        => 'bi-briefcase',
            self::Socio     => 'bi-building',
            self::Estagio   => 'bi-mortarboard',
        };
    }

    public function batePonto(): bool
    {
        return $this === self::Clt || $this === self::Estagio;
    }

    public function ajuda(): string
    {
        return match ($this) {
            self::Clt       => 'Empregado com carteira assinada: tem CTPS e bate ponto (controle de jornada).',
            self::Associado => 'Contrato de associação com o escritório (art. 39 do Regulamento Geral da OAB). Não bate ponto.',
            self::Pj        => 'Presta serviço por contrato, como pessoa jurídica ou autônomo. Não bate ponto.',
            self::Socio     => 'Integra o contrato social da sociedade de advogados. Não bate ponto.',
            self::Estagio   => 'Contrato de estágio (Lei nº 11.788/2008): jornada controlada, bate ponto.',
        };
    }

    /** Nome da data em que o vínculo começou (CLT usa a admissão, que é outro campo). */
    public function rotuloInicio(): string
    {
        return match ($this) {
            self::Estagio => 'Início do estágio',
            self::Socio   => 'Entrada na sociedade',
            default       => 'Início do contrato',
        };
    }
}
