<?php

declare(strict_types=1);

namespace App\Auth\UseCase;

use App\Auth\DTO\DefinirDestinatarioSuporteInput;
use App\Auth\Entity\AjusteEntrada;
use App\Auth\Repository\AjusteEntradaRepository;
use App\Auth\Service\PaginaDeEntrada;

/**
 * "QUEM RECEBE AS MENSAGENS DE SUPORTE · SÓ MASTER VÊ" (desenho 10). O Master escolhe uma pessoa entre as opções
 * do PaginaDeEntrada (os Masters e a equipe do escritório atual dele); a escolha vale para as próximas mensagens.
 *
 * Quem é Master é conferido no controller; aqui só a regra: a pessoa tem de estar entre as opções.
 *
 * @throws \DomainException 'Pessoa inválida.' quando a pessoa não está entre as opções
 */
final class DefinirDestinatarioSuporteUseCase
{
    public function __construct(
        private readonly PaginaDeEntrada $paginaDeEntrada,
        private readonly AjusteEntradaRepository $ajustes,
    ) {
    }

    /** @return string o texto do desenho: "A partir de agora, <primeiro nome> recebe as mensagens no Chat I.A." */
    public function executar(DefinirDestinatarioSuporteInput $input): string
    {
        $opcoes = $this->paginaDeEntrada->opcoesDeDestinatario();
        if (!isset($opcoes[$input->userId])) {
            throw new \DomainException('Pessoa inválida.');
        }

        $ajuste = $this->ajustes->daChave(AjusteEntrada::SUPORTE_DESTINATARIO)
            ?? new AjusteEntrada(AjusteEntrada::SUPORTE_DESTINATARIO);
        $ajuste->definirValor((string) $input->userId, $input->alteradoPor, new \DateTimeImmutable());
        $this->ajustes->salvar($ajuste, flush: true);

        return PaginaDeEntrada::textoDoDestinatario([
            ['nome' => $opcoes[$input->userId]['nome'], 'escolhido' => true],
        ]);
    }
}
