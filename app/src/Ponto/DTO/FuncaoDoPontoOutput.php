<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

/**
 * Uma função do Ponto na aba Configurações (E7, desenho L238 a L243): título, ícone e ajuda do desenho e as duas
 * camadas. `escritorio` é o que o escritório gravou (ausente = ligada); com o Global desligado ele fica guardado,
 * mas a chave aparece travada e desligada (`travada`), como no desenho (`e: e0 && !bloq`).
 */
final class FuncaoDoPontoOutput
{
    public function __construct(
        public readonly string $chave,
        public readonly string $titulo,
        public readonly string $icone,
        public readonly string $ajuda,
        public readonly bool $global,
        public readonly bool $escritorio,
    ) {
    }

    /** O Master desligou para todos: a chave do escritório não muda (P67). */
    public function travada(): bool
    {
        return !$this->global;
    }

    /** O que a chave "Escritório" mostra: ligada só com as duas camadas ligadas. */
    public function escritorioEfetivo(): bool
    {
        return $this->global && $this->escritorio;
    }
}
