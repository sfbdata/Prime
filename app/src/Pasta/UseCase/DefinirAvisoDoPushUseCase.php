<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\AvisosDoPushOutput;
use App\Pasta\DTO\ChavesDoAvisoDoPush;
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
 * quando o aviso ao responsável desliga).
 *
 * Recebe o ESTADO DESEJADO, não "inverter": o clique repetido (ou a segunda aba) não desfaz o
 * primeiro. Pedir o estado que já vale só devolve o cartão. A primeira gravação cria a linha dos
 * padrões por INSERT idempotente (sem 500 na corrida).
 *
 * Recusa (422, com a dica): LIGAR uma chave travada pelo motivo dela (e-mail sem o aviso ao
 * responsável, IA indisponível no escritório ou sem acesso do responsável). Desligar é sempre
 * aceito. A permissão (EDITAR) e o CSRF são do controller; a lápide, do PastaSomenteLeituraListener.
 */
final class DefinirAvisoDoPushUseCase
{
    public function __construct(
        private readonly AvisoDoPushDaPastaRepository $avisos,
        private readonly DisponibilidadeDaIaNoAvisoDoPush $ia,
        private readonly ClockInterface $relogio,
    ) {
    }

    /**
     * @throws PastaDeOutroEscritorioException pasta de outro escritório
     * @throws ChaveDoAvisoTravadaException    ligar uma chave travada
     */
    public function executar(Pasta $pasta, ChaveDoAvisoDoPush $chave, bool $ligada, User $usuario, Tenant $tenant): AvisosDoPushOutput
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new PastaDeOutroEscritorioException('Pasta não encontrada.');
        }

        $motivoDaIa = $this->ia->motivoDaTravaNaPasta($pasta, $tenant);
        $atual = ChavesDoAvisoDoPush::da($this->avisos->daPastaDoTenant($pasta, $tenant));
        if ($atual->ligada($chave) === $ligada) {
            return AvisosDoPushOutput::montar($atual, $motivoDaIa);
        }

        $motivo = AvisosDoPushOutput::motivoDaTrava($chave, $atual, $motivoDaIa);
        if ($ligada && $motivo !== null) {
            throw new ChaveDoAvisoTravadaException($motivo);
        }

        $agora = $this->relogio->now();
        $linha = $this->avisos->garantirDaPasta($pasta, $tenant, $usuario, $agora);
        $linha->definir($chave, $ligada, $usuario, $agora);
        $this->avisos->salvar($linha, true);

        return AvisosDoPushOutput::montar(ChavesDoAvisoDoPush::da($linha), $motivoDaIa);
    }
}
