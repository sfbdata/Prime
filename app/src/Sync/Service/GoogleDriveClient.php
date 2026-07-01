<?php

declare(strict_types=1);

namespace App\Sync\Service;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

/**
 * Cliente fino sobre a API do Google Drive (Shared Drive + service account).
 *
 * Fronteira de integração: validado manualmente contra um Shared Drive de teste
 * (não entra no CI). Os testes do domínio usam GoogleDriveClientInterface via fake.
 */
final class GoogleDriveClient implements GoogleDriveClientInterface
{
    private ?Drive $drive = null;

    public function __construct(
        private readonly string $googleDriveCredentials,
        private readonly string $googleDriveSharedDriveId,
    ) {}

    private function drive(): Drive
    {
        if ($this->drive === null) {
            if (trim($this->googleDriveCredentials) === '' || !is_file($this->googleDriveCredentials)) {
                throw new \RuntimeException('GOOGLE_DRIVE_CREDENTIALS não configurada ou arquivo inexistente.');
            }

            $client = new Client();
            $client->setAuthConfig($this->googleDriveCredentials);
            $client->addScope(Drive::DRIVE);
            $this->drive = new Drive($client);
        }

        return $this->drive;
    }

    public function criarPasta(string $nome, string $parentId): string
    {
        $metadata = new DriveFile([
            'name'     => $nome,
            'mimeType' => 'application/vnd.google-apps.folder',
            'parents'  => [$parentId],
        ]);

        $pasta = $this->drive()->files->create($metadata, [
            'fields'            => 'id',
            'supportsAllDrives' => true,
        ]);

        return $pasta->getId();
    }

    public function listarSubpastas(string $parentId): array
    {
        return $this->listar(
            $parentId,
            "mimeType = 'application/vnd.google-apps.folder'",
            'files(id,name),nextPageToken',
            static fn (DriveFile $f): array => ['id' => $f->getId(), 'nome' => $f->getName()],
        );
    }

    public function listarArquivos(string $folderId): array
    {
        return $this->listar(
            $folderId,
            "mimeType != 'application/vnd.google-apps.folder'",
            'files(id,name,size,mimeType),nextPageToken',
            static fn (DriveFile $f): array => [
                'id'       => $f->getId(),
                'nome'     => $f->getName(),
                'tamanho'  => (int) $f->getSize(),
                'mimeType' => (string) $f->getMimeType(),
            ],
        );
    }

    /**
     * @param callable(DriveFile): array<string, mixed> $mapear
     * @return list<array<string, mixed>>
     */
    private function listar(string $parentId, string $filtroMime, string $fields, callable $mapear): array
    {
        $itens     = [];
        $pageToken = null;

        do {
            $params = [
                'q'                         => sprintf("'%s' in parents and %s and trashed = false", $parentId, $filtroMime),
                'fields'                    => $fields,
                'supportsAllDrives'         => true,
                'includeItemsFromAllDrives' => true,
                'corpora'                   => 'drive',
                'driveId'                   => $this->googleDriveSharedDriveId,
                'pageSize'                  => 1000,
            ];
            if ($pageToken !== null) {
                $params['pageToken'] = $pageToken;
            }

            $resposta = $this->drive()->files->listFiles($params);
            foreach ($resposta->getFiles() as $arquivo) {
                $itens[] = $mapear($arquivo);
            }
            $pageToken = $resposta->getNextPageToken();
        } while ($pageToken !== null);

        return $itens;
    }

    public function enviarArquivo(string $folderId, string $nome, string $caminhoLocal, string $mimeType): string
    {
        $conteudo = file_get_contents($caminhoLocal);
        if ($conteudo === false) {
            throw new \RuntimeException(sprintf('Não foi possível ler o arquivo local: %s', $caminhoLocal));
        }

        $metadata = new DriveFile(['name' => $nome, 'parents' => [$folderId]]);

        $arquivo = $this->drive()->files->create($metadata, [
            'data'              => $conteudo,
            'mimeType'          => $mimeType,
            'uploadType'        => 'multipart',
            'fields'            => 'id',
            'supportsAllDrives' => true,
        ]);

        return $arquivo->getId();
    }

    public function baixarArquivo(string $fileId, string $destinoLocal): void
    {
        $resposta = $this->drive()->files->get($fileId, [
            'alt'               => 'media',
            'supportsAllDrives' => true,
        ]);

        $conteudo = $resposta->getBody()->getContents();
        if (file_put_contents($destinoLocal, $conteudo) === false) {
            throw new \RuntimeException(sprintf('Não foi possível gravar o arquivo em: %s', $destinoLocal));
        }
    }
}
