<?php

declare(strict_types=1);

namespace App\Sync\UseCase;

use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Sync\DTO\DriveDoTenant;
use App\Sync\DTO\ResultadoReconhecimento;
use App\Sync\Entity\ReconhecimentoPendente;
use App\Sync\Repository\ReconhecimentoPendenteRepository;
use App\Sync\Service\GoogleDriveClientFactoryInterface;
use App\Sync\Service\LigadorDeArquivosDoDrive;
use App\Sync\Service\NormalizadorDeNomeDoDrive;
use App\Sync\Service\ReconciliadorDePasta;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reconhecimento do Drive (spec `docs/specs/drive-reconhecimento.md`), antes de qualquer envio: o escritório que
 * conecta um Drive que já tem o acervo não pode ganhar pastas em dobro.
 *
 * Lista as subpastas DIRETAS da pasta-raiz que ainda não estão ligadas a pasta nenhuma e casa cada uma com as pastas
 * do escritório sem vínculo, pelo nome que o envio usaria ("NUP - CLIENTE - AÇÃO") ou pelo nome do cliente sozinho
 * (o "Importar em lote" nomeia a pasta pelo cliente), sempre normalizados ({@see NormalizadorDeNomeDoDrive}).
 *
 * - Casamento único (uma candidata, que só casou com esta subpasta): liga a pasta e os documentos dela.
 * - Dúvida (nenhuma ou várias candidatas, ou candidata disputada): grava uma {@see ReconhecimentoPendente}.
 *
 * Idempotente: subpasta com registro (pendente ou decidido) é pulada. Só lê o Drive: nada é criado, apagado ou
 * renomeado lá.
 */
final class ReconhecerPastasDoDriveUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GoogleDriveClientFactoryInterface $drives,
        private readonly ReconhecimentoPendenteRepository $pendencias,
        private readonly LigadorDeArquivosDoDrive $ligadorDeArquivos,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @throws \App\Sync\Exception\TenantSemDriveException */
    public function executar(Tenant $tenant): ResultadoReconhecimento
    {
        $drive = $this->drives->paraTenant($tenant);
        $subpastas = $this->subpastasSemVinculo($drive);
        $candidatas = $this->casar($subpastas, $this->pastasSemVinculo((int) $tenant->getId()));
        $registrados = $this->pendencias->driveFolderIdsRegistrados($tenant);
        $disputa = $this->subpastasPorPasta($candidatas, $registrados, $this->pendencias->driveFolderIdsPendentes($tenant));

        $resultado = new ResultadoReconhecimento();
        foreach ($subpastas as $sub) {
            $ids = $candidatas[$sub['id']];
            if (isset($registrados[$sub['id']])) {
                ++$resultado->jaRegistradas;
            } elseif (count($ids) === 1 && ($disputa[$ids[0]] ?? 0) === 1) {
                $this->ligarSozinho($ids[0], $sub['id'], $drive, $resultado);
            } else {
                $this->pendencias->salvar(new ReconhecimentoPendente($tenant, $sub['id'], $sub['nome'], $ids));
                ++$resultado->pendenciasNovas;
            }
        }
        $this->em->flush();
        $this->registrarNoLog((int) $tenant->getId(), $resultado);

        return $resultado;
    }

    /**
     * Subpastas diretas da raiz que nenhuma pasta do sistema usa (o vínculo é único no sistema inteiro).
     *
     * @return list<array{id: string, nome: string}>
     */
    private function subpastasSemVinculo(DriveDoTenant $drive): array
    {
        $subpastas = $drive->client->listarSubpastas($drive->rootFolderId);
        if ($subpastas === []) {
            return [];
        }
        $ligadas = array_flip(array_map('strval', $this->em->getConnection()->fetchFirstColumn(
            'SELECT drive_folder_id FROM pasta WHERE drive_folder_id IN (:ids)',
            ['ids' => array_column($subpastas, 'id')],
            ['ids' => ArrayParameterType::STRING],
        )));

        return array_values(array_filter($subpastas, static fn (array $s): bool => !isset($ligadas[$s['id']])));
    }

    /** @return list<array<string, mixed>> pastas do escritório sem vínculo com o Drive (fora da lixeira) */
    private function pastasSemVinculo(int $tenantId): array
    {
        return $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, nup, nome_cliente, nome_acao FROM pasta WHERE tenant_id = :t AND drive_folder_id IS NULL AND excluida_em IS NULL ORDER BY id ASC',
            ['t' => $tenantId],
        );
    }

    /**
     * @param list<array{id: string, nome: string}> $subpastas
     * @param list<array<string, mixed>>            $pastas
     *
     * @return array<string, list<int>> id da subpasta => ids das pastas candidatas
     */
    private function casar(array $subpastas, array $pastas): array
    {
        [$porNome, $porCliente] = $this->indexarPastas($pastas);
        $candidatas = [];
        foreach ($subpastas as $sub) {
            $ids = array_merge(
                $porNome[NormalizadorDeNomeDoDrive::normalizar($sub['nome'])] ?? [],
                $porCliente[NormalizadorDeNomeDoDrive::normalizarCliente($sub['nome'])] ?? [],
            );
            $candidatas[$sub['id']] = array_values(array_unique($ids));
        }

        return $candidatas;
    }

    /**
     * @param list<array<string, mixed>> $pastas
     *
     * @return array{0: array<string, list<int>>, 1: array<string, list<int>>} [por nome do envio, por cliente]
     */
    private function indexarPastas(array $pastas): array
    {
        $porNome = [];
        $porCliente = [];
        foreach ($pastas as $p) {
            $cliente = $p['nome_cliente'] !== null ? (string) $p['nome_cliente'] : null;
            $nome = NormalizadorDeNomeDoDrive::normalizar(ReconciliadorDePasta::nomeEsperado(
                $p['nup'] !== null ? (string) $p['nup'] : null,
                $cliente,
                $p['nome_acao'] !== null ? (string) $p['nome_acao'] : null,
            ));
            $chaveCliente = $cliente !== null ? NormalizadorDeNomeDoDrive::normalizarCliente($cliente) : '';
            if ($nome !== '') {
                $porNome[$nome][] = (int) $p['id'];
            }
            if ($chaveCliente !== '') {
                $porCliente[$chaveCliente][] = (int) $p['id'];
            }
        }

        return [$porNome, $porCliente];
    }

    /**
     * Em quantas subpastas cada pasta é candidata. A subpasta já decidida (ignorada) não disputa mais nada; a que
     * ainda espera decisão continua disputando.
     *
     * @param array<string, list<int>> $candidatas
     * @param array<string, true>      $registrados
     * @param array<string, true>      $pendentes
     *
     * @return array<int, int> id da pasta => em quantas subpastas ela é candidata
     */
    private function subpastasPorPasta(array $candidatas, array $registrados, array $pendentes): array
    {
        $contagem = [];
        foreach ($candidatas as $subId => $ids) {
            if (isset($registrados[$subId]) && !isset($pendentes[$subId])) {
                continue;
            }
            foreach ($ids as $id) {
                $contagem[$id] = ($contagem[$id] ?? 0) + 1;
            }
        }

        return $contagem;
    }

    private function ligarSozinho(int $pastaId, string $driveFolderId, DriveDoTenant $drive, ResultadoReconhecimento $resultado): void
    {
        $pasta = $this->em->find(Pasta::class, $pastaId);
        if ($pasta === null) {
            return;
        }
        $pasta->setDriveFolderId($driveFolderId);
        $resultado->arquivosLigados += $this->ligadorDeArquivos->ligarDocumentosDaPasta($pastaId, $driveFolderId, $drive->client);
        $this->em->flush();
        ++$resultado->pastasLigadas;
    }

    private function registrarNoLog(int $tenantId, ResultadoReconhecimento $r): void
    {
        $this->logger->info('Reconhecimento do Drive do tenant {tenant}: {pastas} pasta(s) e {arquivos} arquivo(s) ligados, {pendencias} dúvida(s) nova(s), {registradas} já registrada(s).', [
            'tenant' => $tenantId,
            'pastas' => $r->pastasLigadas,
            'arquivos' => $r->arquivosLigados,
            'pendencias' => $r->pendenciasNovas,
            'registradas' => $r->jaRegistradas,
        ]);
    }
}
