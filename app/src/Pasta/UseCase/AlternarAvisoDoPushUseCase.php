<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\AvisosDoPushOutput;
use App\Pasta\DTO\ChavesDoAvisoDoPush;
use App\Pasta\Entity\AvisoDoPushDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Enum\ChaveDoAvisoDoPush;
use App\Pasta\Exception\ChaveDoAvisoTravadaException;
use App\Pasta\Exception\PastaDeOutroEscritorioException;
use App\Pasta\Repository\AvisoDoPushDaPastaRepository;
use App\Pasta\Service\DisponibilidadeDaIaNoAvisoDoPush;
use Psr\Clock\ClockInterface;

/**
 * Uma chave do cartão "Avisar quando chegar" (spec push-avisar-quando-chegar §2).
 *
 * História: quem edita a pasta liga ou desliga um aviso (responsável no sino, por e-mail, análise
 * da BlueJus IA) e vê o cartão inteiro de volta, porque uma chave muda a outra ("Por e-mail" trava
 * quando o aviso ao responsável desliga). A primeira alteração cria a linha a partir dos padrões.
 *
 * Recusa (422, com a dica): chave travada pelo motivo dela, e-mail sem o aviso ao responsável ou IA
 * indisponível no escritório. A permissão (EDITAR) e o CSRF são do controller; a lápide, do
 * PastaSomenteLeituraListener.
 */
final class AlternarAvisoDoPushUseCase
{
    public function __construct(
        private readonly AvisoDoPushDaPastaRepository $avisos,
        private readonly DisponibilidadeDaIaNoAvisoDoPush $ia,
        private readonly ClockInterface $relogio,
    ) {
    }

    /**
     * @throws PastaDeOutroEscritorioException pasta de outro escritório
     * @throws ChaveDoAvisoTravadaException    a chave não pode mudar agora
     */
    public function executar(Pasta $pasta, ChaveDoAvisoDoPush $chave, User $usuario, Tenant $tenant): AvisosDoPushOutput
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }

        $linha = $this->avisos->daPastaDoTenant($pasta, $tenant);
        $disponibilidade = $this->ia->doEscritorio($tenant);
        $motivo = AvisosDoPushOutput::motivoDaTrava($chave, ChavesDoAvisoDoPush::da($linha), $disponibilidade);
        if ($motivo !== null) {
            throw new ChaveDoAvisoTravadaException($motivo);
        }

        $agora = $this->relogio->now();
        $linha ??= new AvisoDoPushDaPasta($tenant, $pasta, $usuario, $agora);
        $linha->alternar($chave, $usuario, $agora);
        $this->avisos->salvar($linha, true);

        return AvisosDoPushOutput::montar(ChavesDoAvisoDoPush::da($linha), $disponibilidade);
    }
}
