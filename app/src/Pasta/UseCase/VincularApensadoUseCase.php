<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\TipoDeApenso;
use App\Pasta\Exception\ApensadoInvalidoException;
use App\Pasta\Service\RegistroDoSistemaNaPasta;
use App\Processo\Entity\Processo;
use App\Twig\NumeroProcessoExtension;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Vincula um processo como APENSADO da pasta (P4f, botão "Vincular apensado" da aba Processo).
 *
 * Quem: quem pode editar a pasta (o controller confere). O quê: juntar à pasta um processo que depende
 * do principal (embargos, agravo, execução...), com o tipo escolhido, para que ele apareça em Processo,
 * Dados da pasta e Push processual. Pré-condição: processo do mesmo escritório da pasta (o controller
 * já resolve o processo pelo escritório; aqui reforçamos).
 *
 * Fluxo: confere o escritório, vincula como apensado (a entidade recusa o principal e o repetido com o
 * texto do desenho, {@see ApensadoInvalidoException}), escreve o cartão do sistema na timeline de Dados
 * (`SYS.proc`, N:4555) e dá um flush só. Sem principal a pasta aceita o apensado (N:4555) e o texto do
 * registro fica sem o número, como no desenho.
 */
final class VincularApensadoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RegistroDoSistemaNaPasta $registro,
        private readonly NumeroProcessoExtension $numero,
    ) {
    }

    public function executar(Pasta $pasta, Processo $processo, TipoDeApenso $tipo, User $usuario): void
    {
        $this->garantirMesmoTenant($pasta, $processo);

        $pasta->vincularApensado($processo, $tipo, $usuario);
        $this->registro->registrar($pasta, $usuario, 'proc', $this->textoDoRegistro($pasta, $processo, $tipo));
        $this->em->flush();
    }

    /** "apensado X (Embargos) vinculado ao processo principal Y." (desenho `procSalvarVinc`, N:4555). */
    private function textoDoRegistro(Pasta $pasta, Processo $processo, TipoDeApenso $tipo): string
    {
        $principal = $pasta->getProcessoPrincipal();

        return 'apensado ' . $this->numero->numeroCnj($processo->getNumeroProcesso())
            . ' (' . $tipo->rotulo() . ') vinculado ao processo principal'
            . ($principal !== null ? ' ' . $this->numero->numeroCnj($principal->getNumeroProcesso()) : '')
            . '.';
    }

    private function garantirMesmoTenant(Pasta $pasta, Processo $processo): void
    {
        $tenantPasta    = $pasta->getTenant();
        $tenantProcesso = $processo->getTenant();

        $mesmoTenant = $tenantPasta !== null
            && $tenantProcesso !== null
            && ($tenantPasta === $tenantProcesso
                || ($tenantPasta->getId() !== null && $tenantPasta->getId() === $tenantProcesso->getId()));

        if (!$mesmoTenant) {
            throw new AccessDeniedException('O processo não pertence ao mesmo escritório da pasta.');
        }
    }
}
