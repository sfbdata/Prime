<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto;

/**
 * O destino remoto recusou, não respondeu, ou o que ficou gravado não confere.
 *
 * A mensagem nunca carrega credencial, cabeçalho assinado nem corpo da resposta — só o que ajuda a
 * diagnosticar (status HTTP, código do S3, chave remota). É o texto que a outbox da P2.3 vai guardar
 * em `ultimo_erro`.
 */
final class FalhaNoEspelhoRemoto extends \RuntimeException
{
}
