<?php

declare(strict_types=1);

namespace App\TrabalhoExterno\Service;

use App\TrabalhoExterno\DTO\FiltroDaCampanha;
use Psr\Log\LoggerInterface;

/**
 * O rastro de cada exportação da campanha por e-mail (spec VS 18.10.26 §4.10), no log da aplicação: quem,
 * quando, o que (CSV, baixar ou copiar o HTML), o público, os filtros e quantos destinatários. Nunca a
 * lista: os e-mails são dado pessoal de terceiros. Nada é gravado no banco.
 */
final class RegistroDaCampanha
{
    public const CSV = 'csv';
    public const BAIXAR = 'baixar';
    public const COPIAR = 'copiar';

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function registrar(Contexto $ctx, FiltroDaCampanha $filtro, string $acao, int $destinatarios): void
    {
        $this->logger->info('Trabalho Externo: campanha por e-mail exportada', [
            'acao' => $acao,
            'usuario' => $ctx->usuario->getId(),
            'em' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'publico' => $filtro->pub,
            'filtros' => ['uf' => $filtro->uf, 'area' => $filtro->area],
            'destinatarios' => $destinatarios,
        ]);
    }
}
