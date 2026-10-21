<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Campanha\ComposicaoDaCampanha;
use App\Comercial\Exception\DescadastroRecusado;
use App\Comercial\Service\EscritaManualDoDescadastro;
use App\Entity\Auth\User;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;

/**
 * "Registrar pedido" na lista de Descadastrados (spec comercial-e-emails §10, item 9; desenho 05 VS 21.10.26, L697):
 * o Master registra quem pediu, por telefone, WhatsApp etc., para não receber as campanhas. Vai para
 * `comercial_campanhas`, no escopo da plataforma, origem `manual_master`, com o motivo do desenho. Idempotente (o
 * contrato do descadastro não duplica o ativo); cada registro deixa a sua linha no `audit_log`.
 */
final class RegistrarDescadastroDoComercialUseCase
{
    public const MOTIVO = 'Pedido registrado pelo Master';

    public function __construct(
        private readonly DescadastroDeEmailInterface $descadastro,
        private readonly EscritaManualDoDescadastro $escrita,
    ) {
    }

    /** @throws DescadastroRecusado e-mail inválido */
    public function executar(string $email, User $master): void
    {
        $email = $this->escrita->exigirEmail($email);
        $this->descadastro->descadastrar(null, $email, ComposicaoDaCampanha::LISTA, DescadastroDeEmailInterface::ORIGEM_MANUAL_MASTER, self::MOTIVO);
        $this->escrita->auditar(EscritaManualDoDescadastro::REGISTRADO, $email, ComposicaoDaCampanha::LISTA, $master);
    }
}
