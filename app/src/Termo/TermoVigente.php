<?php

declare(strict_types=1);

namespace App\Termo;

/**
 * Único ponto de verdade da versão vigente dos Termos de Uso.
 *
 * Ao publicar nova versão: troque a constante e, no mesmo commit, atualize o texto
 * (templates/termo/_texto.html.twig) e o PDF (public/termos/termos-de-uso.pdf) para
 * baterem com a versão gravada no aceite. Trocar a constante força todos a reaceitar.
 */
final class TermoVigente
{
    public const VERSAO = '2026-06-23';

    public function getVersao(): string
    {
        return self::VERSAO;
    }
}
