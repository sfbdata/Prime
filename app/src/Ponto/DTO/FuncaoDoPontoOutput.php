<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Ponto\Service\FuncoesDoPonto;

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

    /** A chave do título no catálogo: a tela traduz por ela; `titulo` fica como o português de referência. */
    public function chaveDoTitulo(): string
    {
        return FuncoesDoPonto::chaveDoTitulo($this->chave);
    }

    /** A chave da ajuda no catálogo. */
    public function chaveDaAjuda(): string
    {
        return FuncoesDoPonto::chaveDaAjuda($this->chave);
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
