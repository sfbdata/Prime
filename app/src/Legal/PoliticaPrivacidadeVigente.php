<?php

declare(strict_types=1);

namespace App\Legal;

/**
 * Único ponto de verdade da versão vigente da Política de Privacidade.
 *
 * Ao publicar nova versão: troque as duas constantes e, no mesmo commit, atualize o
 * texto (templates/legal/_politica_privacidade_texto.html.twig) e acrescente a linha
 * correspondente no Anexo III, que fica no fim daquele mesmo arquivo.
 *
 * Diferente dos Termos de Uso, a Política **não** entra no fluxo de aceite: trocar estas
 * constantes não para ninguém numa tela de reaceite. Quem faz isso é
 * {@see \App\Termo\TermoVigente}, e é de propósito que sejam separados.
 *
 * O texto veio de `docs/legal/politica-de-privacidade.docx`. Duas coisas que estavam
 * naquele arquivo e NÃO estão aqui, por decisão registrada:
 *  - os campos `[inserir]` do Anexo I foram preenchidos com os suboperadores medidos;
 *  - a "Nota de revisão" final era recado do advogado ao cliente, não texto da política.
 */
final class PoliticaPrivacidadeVigente
{
    public const VERSAO = '1.0.2026';

    /**
     * Data em que a Política entra no ar. Formato ISO para não depender de locale.
     *
     * ⚠️ Trocar para o dia do deploy antes de publicar — o documento diz
     * "Vigência: a partir da publicação", então esta data é a que vale.
     */
    public const DATA_PUBLICACAO = '2026-08-20';

    public function getVersao(): string
    {
        return self::VERSAO;
    }

    public function getDataPublicacao(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::DATA_PUBLICACAO);
    }
}
