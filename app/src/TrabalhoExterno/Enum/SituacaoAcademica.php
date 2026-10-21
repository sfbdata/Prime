<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Enum;

/**
 * "SITUAÇÃO ACADÊMICA E NA OAB" do currículo (`sit` do desenho 06). O desenho dá um rótulo por
 * lugar, e cada um está aqui com o seu: filtro e CSV (l.1148, 1150), detalhe (`cvItem`, l.999),
 * documento (`cvHTML`, l.921) e os botões do formulário (`tfSitOps`, l.1173).
 */
enum SituacaoAcademica: string
{
    case Adv = 'adv';
    case EstOab = 'estOab';
    case Est = 'est';
    case Bach = 'bach';

    /** Filtro "Situação OAB" e coluna "Situação" do CSV. */
    public function rotuloCurto(): string
    {
        return match ($this) {
            self::Adv => 'Advogado(a)',
            self::EstOab => 'Estagiário(a) com OAB',
            self::Est => 'Estudante',
            self::Bach => 'Bacharel(a)',
        };
    }

    /** Detalhe do currículo (`sitTxt`). */
    public function rotuloLongo(): string
    {
        return match ($this) {
            self::Adv => 'Advogado(a)',
            self::EstOab => 'Estagiário(a) com OAB',
            self::Est => 'Estudante de Direito',
            self::Bach => 'Bacharel(a) sem OAB',
        };
    }

    /** Cabeçalho do currículo formatado (PDF). */
    public function rotuloDoDocumento(): string
    {
        return match ($this) {
            self::Adv => 'Advogado(a)',
            self::EstOab => 'Estagiário(a) com OAB',
            self::Est => 'Estudante de Direito',
            self::Bach => 'Bacharel(a) em Direito',
        };
    }

    /** Os quatro botões do formulário. */
    public function rotuloDoFormulario(): string
    {
        return match ($this) {
            self::Adv => 'Advogado(a) inscrito(a)',
            self::EstOab => 'Estagiário(a) com OAB',
            self::Est => 'Estudante de Direito',
            self::Bach => 'Bacharel(a) sem OAB',
        };
    }

    /** O bloco da OAB aparece para advogado(a) e estagiário(a) com OAB (`tfTemOab`, l.1174). */
    public function temOab(): bool
    {
        return $this === self::Adv || $this === self::EstOab;
    }

    /** O bloco do curso aparece para estudante, estagiário(a) e bacharel(a) (`tfTemCurso`, l.1174). */
    public function temCurso(): bool
    {
        return $this !== self::Adv;
    }

    /** A instituição é obrigatória para estudante e estagiário(a) (validação, l.1006). */
    public function exigeInstituicao(): bool
    {
        return $this === self::Est || $this === self::EstOab;
    }
}
