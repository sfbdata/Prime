<?php

declare(strict_types=1);

namespace App\Sync\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Sync\Entity\ReconhecimentoPendente;
use App\Sync\Exception\TenantSemDriveException;
use App\Sync\Repository\ReconhecimentoPendenteRepository;
use App\Sync\Service\GoogleDriveClientFactoryInterface;
use App\Sync\Service\LigadorDeArquivosDoDrive;
use Doctrine\ORM\EntityManagerInterface;

/**
 * O administrador decide uma dúvida do reconhecimento do Drive (spec `docs/specs/drive-reconhecimento.md`):
 *
 * - {@see ligar()}: liga a pasta do Drive a uma das candidatas, ou à pasta cujo número ele digitou (quando não houve
 *   candidata). A pasta tem de ser do escritório, estar fora da lixeira e sem vínculo. Os documentos dela são
 *   ligados aos arquivos que já estão no Drive (nome e tamanho), como no casamento automático.
 * - {@see ignorar()}: as candidatas voltam ao envio normal (o envio cria pasta nova para elas).
 *
 * Erros de regra saem como \DomainException com a mensagem para a tela.
 */
final class DecidirReconhecimentoUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReconhecimentoPendenteRepository $pendencias,
        private readonly PastaRepository $pastas,
        private readonly GoogleDriveClientFactoryInterface $drives,
        private readonly LigadorDeArquivosDoDrive $ligadorDeArquivos,
    ) {
    }

    public function ligar(Tenant $tenant, User $usuario, int $pendenciaId, ?int $pastaId, ?string $nup): void
    {
        $pendencia = $this->carregarPendente($tenant, $pendenciaId);
        $pasta = $this->escolherPasta($tenant, $pendencia, $pastaId, $nup);
        $this->exigirLivre($pasta, $pendencia->getDriveFolderId());

        try {
            $client = $this->drives->paraTenant($tenant)->client;
        } catch (TenantSemDriveException) {
            throw new \DomainException('O Drive deste escritório não está conectado.');
        }

        $pasta->setDriveFolderId($pendencia->getDriveFolderId());
        $this->ligadorDeArquivos->ligarDocumentosDaPasta((int) $pasta->getId(), $pendencia->getDriveFolderId(), $client);
        $pendencia->confirmar((int) $pasta->getId(), $usuario);
        $this->em->flush();
    }

    public function ignorar(Tenant $tenant, User $usuario, int $pendenciaId): void
    {
        $this->carregarPendente($tenant, $pendenciaId)->ignorar($usuario);
        $this->em->flush();
    }

    private function carregarPendente(Tenant $tenant, int $pendenciaId): ReconhecimentoPendente
    {
        $pendencia = $this->pendencias->buscarDoTenant($tenant, $pendenciaId);
        if ($pendencia === null) {
            throw new \DomainException('Pasta do Drive não encontrada neste escritório.');
        }
        if (!$pendencia->estaPendente()) {
            throw new \DomainException('Esta pasta do Drive já foi decidida.');
        }

        return $pendencia;
    }

    /**
     * A pasta escolhida na lista tem de ser uma das candidatas. Sem escolha na lista (não houve candidata, ou as
     * sugeridas já não servem), vale o número digitado.
     */
    private function escolherPasta(Tenant $tenant, ReconhecimentoPendente $pendencia, ?int $pastaId, ?string $nup): Pasta
    {
        if ($pastaId !== null) {
            if (!$pendencia->citaComoCandidata($pastaId)) {
                throw new \DomainException('Escolha uma das pastas sugeridas.');
            }
            $pasta = $this->pastas->find($pastaId);
        } else {
            $numero = trim((string) $nup);
            if ($numero === '') {
                throw new \DomainException('Informe o número da pasta.');
            }
            $pasta = $this->pastas->findDoEscritorioPorNup($numero, $tenant);
        }

        if (!$pasta instanceof Pasta || $pasta->getTenant()?->getId() !== $tenant->getId() || $pasta->estaExcluida()) {
            throw new \DomainException('Pasta não encontrada neste escritório.');
        }

        return $pasta;
    }

    private function exigirLivre(Pasta $pasta, string $driveFolderId): void
    {
        if ($pasta->getDriveFolderId() !== null && $pasta->getDriveFolderId() !== '') {
            throw new \DomainException('Esta pasta já está ligada a outra pasta do Drive.');
        }
        if ($this->pastas->count(['driveFolderId' => $driveFolderId]) > 0) {
            throw new \DomainException('Esta pasta do Drive já está ligada a outra pasta do sistema.');
        }
    }
}
