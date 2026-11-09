<?php

declare(strict_types=1);

namespace App\Ponto\Service;

/**
 * A porta do selo (HMAC) do comprovante da batida (spec ponto-desenho-0811, E15 e seção 10, "Chaves por finalidade").
 *
 * A chave sai do segredo `PONTO_HMAC_SECRET` com a finalidade `ponto-comprovante` (E9). Enquanto o segredo não existe
 * em produção, vale a implementação indisponível: a batida grava com o NSR, nenhum comprovante é emitido, a aba
 * Comprovantes e o "Validar código" não aparecem, e a validação pública responde "Código não localizado.".
 */
interface SeloDoComprovante
{
    /** O selo está pronto para selar e conferir (o segredo existe). */
    public function disponivel(): bool;

    /**
     * Sela os dados canônicos com a chave da versão atual.
     *
     * @return array{hmac: string, versao: int} o HMAC em hexadecimal (64 caracteres) e a versão da chave usada
     *
     * @throws \LogicException quando o selo não está disponível
     */
    public function selar(string $dadosCanonicos): array;

    /** Confere o HMAC com a chave da versão gravada na linha; indisponível ou versão desconhecida: false. */
    public function conferir(string $dadosCanonicos, string $hmac, int $versao): bool;
}
