<?php

declare(strict_types=1);

namespace App\Comercial\UseCase;

use App\Comercial\Exception\DescadastroRecusado;
use App\Comercial\Service\EscritaManualDoDescadastro;
use App\Entity\Auth\User;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;

/**
 * "Excluir" na lista de Descadastrados (spec comercial-e-emails §10, item 9; desenho 05 VS 21.10.26, L694): um por vez,
 * depois do confirm de LGPD da tela, o contato volta a poder receber. Só reativa assunto da lista do Comercial
 * (`comercial_campanhas` ou `todos`, este pela regra (b) do contrato); origem `manual_master`, com quem reativou e a
 * linha no `audit_log`. "Limpar lista" (todos de uma vez) não existe: está com o dono.
 */
final class ReativarDescadastradoDoComercialUseCase
{
    public function __construct(
        private readonly DescadastroDeEmailInterface $descadastro,
        private readonly EscritaManualDoDescadastro $escrita,
    ) {
    }

    /** @throws DescadastroRecusado e-mail inválido ou assunto fora da lista do Comercial */
    public function executar(string $email, string $assunto, User $master): void
    {
        $email = $this->escrita->exigirEmail($email);
        if (!in_array($assunto, ResumirComercialUseCase::ASSUNTOS_DA_LISTA, true)) {
            throw new DescadastroRecusado('Este pedido não é da lista do Comercial.');
        }
        $this->descadastro->reativar(null, $email, $assunto, DescadastroDeEmailInterface::ORIGEM_MANUAL_MASTER, $master);
        $this->escrita->auditar(EscritaManualDoDescadastro::REATIVADO, $email, $assunto, $master);
    }
}
