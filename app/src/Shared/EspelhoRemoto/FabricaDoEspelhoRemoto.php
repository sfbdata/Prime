<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto;

use App\Shared\EspelhoRemoto\R2\ClienteR2;
use App\Shared\EspelhoRemoto\R2\EspelhoRemotoR2;
use Psr\Log\NullLogger;

/**
 * Monta o espelho R2 só quando alguém pede: o worker, a reconciliação e, na requisição HTTP, só a
 * leitura de recuperação, quando o disco não tem o arquivo (`ArmazenamentoEspelhado`).
 *
 * Os quatro valores vêm de variáveis de ambiente de produção (`ESPELHO_R2_*`, vazias por padrão).
 * Sem eles, {@see criar()} recusa com {@see EspelhoRemotoNaoConfigurado}: a aplicação sobe normal, e
 * só quem tentar replicar sabe que falta configuração.
 *
 * O cliente S3 recebe `NullLogger`: o log do `async-aws` carrega a URL, e a URL carrega a chave
 * remota — que, nos legados de texto livre, contém nome de pessoa.
 */
final class FabricaDoEspelhoRemoto implements ProvedorDoEspelhoRemoto
{
    public function __construct(
        private readonly string $endpoint = '',
        private readonly string $bucket = '',
        private readonly string $chaveDeAcesso = '',
        #[\SensitiveParameter]
        private readonly string $segredo = '',
    ) {
    }

    public function estaConfigurado(): bool
    {
        return trim($this->endpoint) !== '' && trim($this->bucket) !== '' && trim($this->chaveDeAcesso) !== '' && trim($this->segredo) !== '';
    }

    public function criar(): EspelhoRemoto
    {
        if (!$this->estaConfigurado()) {
            throw new EspelhoRemotoNaoConfigurado(
                'O espelho remoto não está configurado (ESPELHO_R2_ENDPOINT, ESPELHO_R2_BUCKET, ESPELHO_R2_CHAVE e ESPELHO_R2_SEGREDO).',
            );
        }

        return new EspelhoRemotoR2(
            ClienteR2::criar($this->endpoint, $this->chaveDeAcesso, $this->segredo, null, new NullLogger()),
            $this->bucket,
        );
    }
}
