<?php

declare(strict_types=1);

namespace App\AssinaturaEletronica\Service;

use App\AssinaturaEletronica\Exception\AcaoRecusada;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * O segredo próprio do selo (`ASSINATURA_SELO_SEGREDO`, nunca o `APP_SECRET` nem o `ASSINATURA_SEGREDO`) e o código
 * de validação que sai dele (contrato da etapa 3, seção 2, item 3; decisão E3-6).
 *
 * O código `BJA-XXXX-XXXX-XXXX` é gerado no envio: `HMAC-SHA256(segredo, "v1|{id}|{hash enviado}|{tentativa}")`, os 12
 * primeiros bytes, cada um `% 32` no alfabeto do desenho (sem I, O, 0 e 1), em três grupos de quatro. O segredo impede
 * que quem tem o conteúdo do documento adivinhe o código. A tentativa só sobe numa colisão (o índice único da coluna
 * garante). O código gravado não depende mais do segredo: trocar o segredo não muda os códigos já impressos.
 *
 * Segredo vazio ou curto: o envio recusa com "Código de validação sem chave configurada." Runbook:
 * docs/runbooks/assinatura-selo-segredo-em-producao.md.
 */
final readonly class ChaveDoSelo
{
    public const VERSAO = 1;
    public const TAMANHO_MINIMO = 32;
    public const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    public const PREFIXO = 'BJA-';

    private const BYTES_DO_CODIGO = 12;
    private const TAMANHO_DO_GRUPO = 4;

    public function __construct(
        #[Autowire(param: 'assinatura.selo_segredo')]
        #[\SensitiveParameter]
        private string $segredo,
    ) {
    }

    /** Ao menos 32 bytes sem contar espaço e quebra de linha das pontas, a mesma regra da {@see ChaveDaAssinatura}. */
    public function configurada(): bool
    {
        return \strlen(trim($this->segredo)) >= self::TAMANHO_MINIMO;
    }

    /** @throws AcaoRecusada sem segredo configurado */
    public function exigirConfigurada(): void
    {
        if (!$this->configurada()) {
            throw new AcaoRecusada('assinatura_eletronica.erro.selo_sem_chave');
        }
    }

    /**
     * O código de validação do documento enviado. Determinístico: o mesmo documento, hash e tentativa dão o mesmo código.
     *
     * @throws AcaoRecusada sem segredo configurado
     */
    public function codigo(int $documentoId, string $hashEnviado, int $tentativa = 0): string
    {
        $this->exigirConfigurada();
        $mensagem = sprintf('v%d|%d|%s|%d', self::VERSAO, $documentoId, $hashEnviado, $tentativa);
        $bytes = substr(hash_hmac('sha256', $mensagem, $this->segredo, true), 0, self::BYTES_DO_CODIGO);

        $simbolos = '';
        foreach (str_split($bytes) as $byte) {
            $simbolos .= self::ALFABETO[\ord($byte) % \strlen(self::ALFABETO)];
        }

        return self::PREFIXO . implode('-', str_split($simbolos, self::TAMANHO_DO_GRUPO));
    }
}
