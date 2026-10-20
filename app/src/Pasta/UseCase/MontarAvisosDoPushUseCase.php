<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Tenant\Tenant;
use App\Pasta\DTO\AvisosDoPushOutput;
use App\Pasta\DTO\ChavesDoAvisoDoPush;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\AvisoDoPushDaPastaRepository;
use App\Pasta\Service\DisponibilidadeDaIaNoAvisoDoPush;

/**
 * Monta o cartão "Avisar quando chegar" da aba Push (spec push-avisar-quando-chegar §2).
 *
 * História: quem abre a pasta quer ver, e o editor quer mudar, quem é avisado quando chega uma
 * movimentação nova: o responsável no sino, também por e-mail, e se a BlueJus IA já analisa. Lê a
 * linha da pasta (sem linha, os padrões) e se a IA pode valer nesta pasta (escritório e responsável).
 */
final class MontarAvisosDoPushUseCase
{
    public function __construct(
        private readonly AvisoDoPushDaPastaRepository $avisos,
        private readonly DisponibilidadeDaIaNoAvisoDoPush $ia,
    ) {
    }

    public function executar(Pasta $pasta, Tenant $tenant): AvisosDoPushOutput
    {
        $linha = $pasta->getTenant() === $tenant ? $this->avisos->daPastaDoTenant($pasta, $tenant) : null;

        return AvisosDoPushOutput::montar(ChavesDoAvisoDoPush::da($linha), $this->ia->motivoDaTravaNaPasta($pasta, $tenant));
    }
}
