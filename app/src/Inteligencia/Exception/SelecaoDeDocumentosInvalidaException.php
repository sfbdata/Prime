<?php

declare(strict_types=1);

namespace App\Inteligencia\Exception;

/**
 * A seleção da aba Documentos do drawer não cabe na regra (spec inteligencia-drawer-da-pasta, F4, e PR F
 * da spec ia-anthropic-ligar): nenhum documento marcado, um id que não está na lista que a aba mostra para
 * quem pede (de outra pasta, de outro escritório, oculto pelas abas ou fora dos mais recentes) ou, no "Ler
 * N documentos", um pedido fora dos limites da leitura (quantos, formato, tamanho, páginas). O controller
 * responde 422 com a mensagem; nada é persistido nem conta no limitador.
 *
 * A mensagem de "fora da lista" não diz qual id falhou nem por quê: não confirma que um documento de outra
 * pasta existe. As de limite citam o nome do arquivo, que já está na lista que a pessoa vê.
 */
final class SelecaoDeDocumentosInvalidaException extends \DomainException
{
    public const MOTIVO = 'selecao_invalida';
    public const MOTIVO_DO_LIMITE = 'limite_da_leitura';

    private string $codigo = self::MOTIVO;

    public static function vazia(): self
    {
        return new self('Marque ao menos um documento para montar o índice.');
    }

    public static function foraDaLista(): self
    {
        return new self('Um dos documentos marcados não está mais na lista desta pasta. Recarregue a página e marque de novo.');
    }

    public static function muitosParaLer(int $maximo): self
    {
        return self::doLimite(sprintf('A IA lê no máximo %d documentos por vez. Desmarque alguns e tente de novo.', $maximo));
    }

    public static function formatoNaoLido(string $nome): self
    {
        return self::doLimite(sprintf('A IA lê só PDF e imagem (JPG, PNG, GIF ou WEBP): "%s" não é um desses formatos.', $nome));
    }

    public static function grandeDemais(string $nome, int $limiteEmMb): self
    {
        return self::doLimite(sprintf('"%s" passa de %d MB, o limite para a IA ler este tipo de arquivo.', $nome, $limiteEmMb));
    }

    public static function paginasDemais(int $maximo): self
    {
        return self::doLimite(sprintf('Os PDFs marcados passam de %d páginas juntos, o limite para a IA ler de uma vez. Marque menos documentos e tente de novo.', $maximo));
    }

    public static function totalGrandeDemais(int $limiteEmMb): self
    {
        return self::doLimite(sprintf('Os documentos marcados passam de %d MB juntos. Marque menos documentos e tente de novo.', $limiteEmMb));
    }

    private static function doLimite(string $mensagem): self
    {
        $e = new self($mensagem);
        $e->codigo = self::MOTIVO_DO_LIMITE;

        return $e;
    }

    public function motivo(): string
    {
        return $this->codigo;
    }
}
