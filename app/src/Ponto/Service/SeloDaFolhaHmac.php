<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * O selo da folha emitida e do aceite da folha (E16, decisão 14): HMAC-SHA256 dos dados canônicos com a chave da
 * finalidade `ponto-folha`, derivada do segredo do Ponto pela E9 ({@see ChavesDoHmacDoPonto}). A versão da chave que
 * selou fica gravada na linha, e a conferência recalcula com a chave dessa versão: uma troca de chave futura não
 * invalida o que já foi emitido ou assinado.
 *
 * Sem o segredo, o selo fica indisponível: nenhuma folha emitida é gravada, o "Assinar folha" não aparece e nada
 * confere.
 */
#[AsAlias(SeloDaFolha::class)]
final class SeloDaFolhaHmac implements SeloDaFolha
{
    /** A finalidade da chave da folha (spec ponto-desenho-0811, seção 10, "Chaves por finalidade"). */
    public const FINALIDADE = 'ponto-folha';

    public function __construct(
        private readonly ChavesDoHmacDoPonto $chaves,
    ) {
    }

    public function disponivel(): bool
    {
        return $this->chaves->disponivel();
    }

    public function selar(string $dadosCanonicos): array
    {
        $versao = $this->chaves->versaoAtual();
        $chave = $versao === null ? null : $this->chaves->chave(self::FINALIDADE, $versao);
        if ($versao === null || $chave === null) {
            throw new \LogicException('O selo da folha do ponto não está disponível: falta o segredo do HMAC.');
        }

        return ['hmac' => hash_hmac('sha256', $dadosCanonicos, $chave), 'versao' => $versao];
    }

    public function conferir(string $dadosCanonicos, string $hmac, int $versao): bool
    {
        $chave = $this->chaves->chave(self::FINALIDADE, $versao);   // sem segredo ou versão desconhecida: null

        return $chave !== null && hash_equals(hash_hmac('sha256', $dadosCanonicos, $chave), $hmac);
    }
}
