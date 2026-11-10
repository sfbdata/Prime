<?php

declare(strict_types=1);

namespace App\Ponto\Service;

/**
 * A porta do selo (HMAC) da folha emitida e do aceite da folha (spec ponto-desenho-0811, E16, decisão 14, e seção 10,
 * "Chaves por finalidade"). O mesmo selo serve para os dois, cada um com os próprios dados canônicos.
 *
 * A chave sai do segredo `PONTO_HMAC_SECRET` com a finalidade `ponto-folha` (E9); a implementação é
 * {@see SeloDaFolhaHmac}. Sem o segredo, o selo fica indisponível: o PDF sai sem código BJF e sem QR, nenhuma folha
 * emitida é gravada e o "Assinar folha" não aparece.
 */
interface SeloDaFolha
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
