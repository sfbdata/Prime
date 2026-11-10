<?php

declare(strict_types=1);

namespace App\Ponto\DTO;

use App\Ponto\Entity\FolhaEmitidaDoPonto;

/**
 * A faixa de validação da folha em PDF (desenho 16 L698 a L702): o código BJF, o selo HMAC e quando a folha foi
 * emitida. Só existe com o segredo do HMAC (sem ele, nenhuma folha é emitida e o PDF sai sem esta faixa, decisão 16).
 * O endereço do QR é o da página pública de validação do próprio sistema (D20), montado por quem desenha.
 */
final readonly class ValidacaoDaFolha
{
    /** O rótulo do selo no PDF, igual ao do comprovante (decisão 21; o desenho diz "SHA-256", L700). */
    public const ROTULO_DO_SELO = 'HMAC-SHA256';
    public const TITULO = 'CÓDIGO DE VALIDAÇÃO';

    public function __construct(
        public string $codigo,
        public string $hmac,
        public \DateTimeImmutable $emitidaEm,
    ) {
    }

    public static function daFolha(FolhaEmitidaDoPonto $folha): self
    {
        return new self($folha->getCodigo(), $folha->getHmac(), $folha->getEmitidaEm());
    }

    /** "Emitida em dd/mm/aaaa, hh:mm:ss. Vale somente com registro correspondente no BlueJus." (L701). */
    public function textoDaEmissao(): string
    {
        return sprintf('Emitida em %s. Vale somente com registro correspondente no BlueJus.', $this->emitidaEm->format('d/m/Y, H:i:s'));
    }
}
