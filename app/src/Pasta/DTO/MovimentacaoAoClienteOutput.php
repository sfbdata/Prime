<?php

declare(strict_types=1);

namespace App\Pasta\DTO;

use App\Djen\DTO\PublicacaoDjenListaItem;
use App\Djen\Entity\PublicacaoDjen;
use App\Pasta\Service\FiltroDeAudienciasEIntimacoes;
use App\Twig\NumeroProcessoExtension;

/**
 * Uma movimentação no e-mail automático ao cliente (spec push-envio-automatico §3): tipo, data, órgão,
 * o número do processo e o texto oficial em texto puro (o teor sem HTML), cortado no limite pedido
 * (`cortado` liga o "texto completo disponível com o escritório").
 *
 * `sigilosa`: o processo está sob segredo de justiça (`nivelSigilo > 0`). Aí o e-mail não leva nada
 * da movimentação, só {@see self::TEXTO_SIGILOSA} (D4, a mesma regra do P4b).
 */
final readonly class MovimentacaoAoClienteOutput
{
    /** Novas movimentações: o texto oficial até 4.000 caracteres (spec §3). */
    public const LIMITE_DO_TEXTO = 4000;
    public const TEXTO_SIGILOSA = 'Há movimentação nova no seu processo sob segredo de justiça. Fale com o escritório.';

    public function __construct(
        public int $publicacaoId,
        public string $tipo,
        public ?\DateTimeImmutable $data,
        public ?string $orgao,
        public string $numeroDoProcesso,
        public ?string $texto,
        public bool $cortado,
        public bool $sigilosa,
        public ?string $natureza,
    ) {
    }

    public static function dePublicacao(PublicacaoDjen $publicacao, int $limite = self::LIMITE_DO_TEXTO): self
    {
        $tipo = trim((string) $publicacao->getTipoComunicacao());
        $orgao = trim((string) $publicacao->getNomeOrgao());
        $inteiro = PublicacaoDjenListaItem::resumirTexto($publicacao->getTexto(), \PHP_INT_MAX);
        $cortado = $inteiro !== null && mb_strlen($inteiro) > $limite;
        $numero = (string) $publicacao->getProcesso()?->getNumeroProcesso();

        return new self(
            (int) $publicacao->getId(),
            $tipo !== '' ? $tipo : 'Movimentação',
            $publicacao->getDataDisponibilizacao() ?? $publicacao->getCriadoEm(),
            $orgao !== '' ? $orgao : ($publicacao->getSiglaTribunal() !== '' ? $publicacao->getSiglaTribunal() : null),
            $numero !== '' ? (new NumeroProcessoExtension())->numeroCnj($numero) : '',
            $cortado ? PublicacaoDjenListaItem::resumirTexto($publicacao->getTexto(), $limite) : $inteiro,
            $cortado,
            ($publicacao->getProcesso()?->getNivelSigilo() ?? 0) > 0,
            FiltroDeAudienciasEIntimacoes::natureza($publicacao->getTipoComunicacao(), $inteiro),
        );
    }

    /** "intimação de 02/10", "audiência de 02/10" ou "movimentação de 02/10": a descrição do histórico. */
    public function descricao(): string
    {
        return ($this->natureza ?? 'movimentação') . ' de ' . ($this->data?->format('d/m') ?? 'data não informada');
    }
}
