<?php

declare(strict_types=1);

namespace App\Ponto\Service;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * O selo do comprovante da batida (E15, fase B): HMAC-SHA256 dos dados canônicos com a chave da finalidade
 * `ponto-comprovante`, derivada do segredo do Ponto pela E9 ({@see ChavesDoHmacDoPonto}). A versão da chave que selou
 * fica gravada no comprovante, e a conferência recalcula com a chave dessa versão: uma troca de chave futura não
 * invalida o que já foi emitido.
 *
 * Sem o segredo, o selo fica indisponível: a batida grava com o NSR, nada é emitido, a aba Comprovantes e o "Validar
 * código" não aparecem, e a validação pública responde "Código não localizado.".
 */
#[AsAlias(SeloDoComprovante::class)]
final class SeloDoComprovanteHmac implements SeloDoComprovante
{
    /** A finalidade da chave do comprovante (spec ponto-desenho-0811, seção 10, "Chaves por finalidade"). */
    public const FINALIDADE = 'ponto-comprovante';

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
            throw new \LogicException('O selo do comprovante do ponto não está disponível: falta o segredo do HMAC.');
        }

        return ['hmac' => hash_hmac('sha256', $dadosCanonicos, $chave), 'versao' => $versao];
    }

    public function conferir(string $dadosCanonicos, string $hmac, int $versao): bool
    {
        $chave = $this->chaves->chave(self::FINALIDADE, $versao);   // sem segredo ou versão desconhecida: null

        return $chave !== null && hash_equals(hash_hmac('sha256', $dadosCanonicos, $chave), $hmac);
    }
}
