<?php

declare(strict_types=1);

namespace App\Comercial\DTO;

use App\Comercial\Campanha\ListaInformada;
use App\Comercial\Campanha\PublicosDaCampanha;
use App\Comercial\Exception\CampanhaRecusada;

/**
 * O público que a tela escolheu (dc L.237-239): a chave e, no CSV e no Individual, a lista informada já lida no
 * servidor (o texto do arquivo e o do campo, com os limites da spec §8.5).
 *
 * `modo` é a Central de onde veio o pedido (F3h, dc 05 VS 21.10.26 L627-645): `com` (seção Comercial, tudo) ou
 * `venda` (seção Indicações: só a Base de contatos, o CSV e o Individual, e o filtro só com a busca e o Estado). O
 * servidor recusa o que a tela da venda não mostra, para a tela não ser a única barreira.
 *
 * `modelo` é a chave do modelo em edição (spec §11): com `vagas`, a Base de contatos inclui quem autorizou os alertas
 * de vagas. Na contagem vem do campo `modelo` do JSON; no "Enviar campanha", da mensagem ({@see PedidoDeCampanha}).
 */
final class PedidoDePublico
{
    public const MODO_COMERCIAL = 'com';
    public const MODO_VENDA = 'venda';
    public const PUBLICOS_DA_VENDA = ['leads', 'csv', 'indiv'];
    /** As chaves do filtro (F2) que o modo venda mostra: a busca e o Estado (dc `cmNaoVenda` esconde o resto). */
    public const FILTROS_DA_VENDA = ['q', 'uf'];

    /** @param list<DestinatarioDaCampanha> $informados */
    public function __construct(
        public readonly string $publico,
        public readonly array $informados = [],
        public readonly FiltroDoPublico $filtro = new FiltroDoPublico(),
        public readonly string $modo = self::MODO_COMERCIAL,
        public readonly string $modelo = '',
    ) {
        if (!\array_key_exists($publico, PublicosDaCampanha::ROTULOS)) {
            throw new CampanhaRecusada('Público inválido.');
        }
        if ($modo !== self::MODO_COMERCIAL && $modo !== self::MODO_VENDA) {
            throw new CampanhaRecusada('Pedido inválido.');
        }
        if ($modo === self::MODO_VENDA && !\in_array($publico, self::PUBLICOS_DA_VENDA, true)) {
            throw new CampanhaRecusada('Público inválido para a seção Indicações.');
        }
        if ($modo === self::MODO_VENDA && array_diff(array_keys($filtro->paraArray()), self::FILTROS_DA_VENDA) !== []) {
            throw new CampanhaRecusada('Na seção Indicações, o filtro aceita só a busca e o Estado.');
        }
    }

    public function ehVenda(): bool
    {
        return $this->modo === self::MODO_VENDA;
    }

    /**
     * @param array<mixed> $dados
     * @param ?string      $modelo a chave do modelo, quando quem chama já a tem (o `modelo` do envio é o objeto inteiro)
     */
    public static function deArray(array $dados, ?string $modelo = null): self
    {
        $publico = \is_string($dados['publico'] ?? null) ? $dados['publico'] : 'leads';

        $filtro = $dados['filtro'] ?? [];
        if (!\is_array($filtro)) {
            throw new CampanhaRecusada('Filtro inválido.');
        }

        $modo = $dados['modo'] ?? self::MODO_COMERCIAL;

        return new self($publico, match ($publico) {
            'csv' => ListaInformada::doCsv(self::texto($dados['csv'] ?? '')),
            'indiv' => ListaInformada::doIndividual(self::texto($dados['individual'] ?? '')),
            default => [],
        }, FiltroDoPublico::deArray($filtro), \is_string($modo) ? $modo : throw new CampanhaRecusada('Pedido inválido.'), $modelo ?? self::modelo($dados['modelo'] ?? ''));
    }

    /** A chave do modelo na contagem: texto curto; outro tipo vale como nenhum modelo. */
    private static function modelo(mixed $valor): string
    {
        return \is_string($valor) ? mb_substr($valor, 0, 40) : '';
    }

    private static function texto(mixed $valor): string
    {
        return \is_string($valor) ? $valor : throw new CampanhaRecusada('Pedido inválido.');
    }
}
