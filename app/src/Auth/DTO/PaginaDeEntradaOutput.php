<?php

declare(strict_types=1);

namespace App\Auth\DTO;

/**
 * O que a tela de entrada (desenho 10) precisa saber dos ajustes do Master. Montado pelo PaginaDeEntrada.
 *
 * `links`: na ordem do rodapé (termos, priv, linkedin, instagram). Para quem não é Master, só os visíveis (não
 * ocultos e com endereço); para o Master, todos.
 * Cada link: {chave, rotulo, icone, url, oculto, padrao}. `icone` vazio = link de texto.
 * `destinatarios`: só preenchido para o Master. Cada um: {id, nome, cargo, escolhido}.
 */
final class PaginaDeEntradaOutput
{
    /**
     * @param list<array{chave: string, rotulo: string, icone: string, url: string, oculto: bool, padrao: string}> $links
     * @param list<array{id: int, nome: string, cargo: string, escolhido: bool}>                                 $destinatarios
     */
    public function __construct(
        public readonly bool $ehMaster,
        public readonly array $links,
        public readonly string $termosUrl,
        public readonly string $privUrl,
        public readonly array $destinatarios,
        public readonly string $textoDestinatario,
    ) {
    }
}
