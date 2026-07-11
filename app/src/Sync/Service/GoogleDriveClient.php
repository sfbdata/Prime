<?php

declare(strict_types=1);

namespace App\Sync\Service;

use Google\Client;
use Google\Http\MediaFileUpload;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Psr\Http\Message\RequestInterface;

/**
 * Cliente fino sobre a API do Google Drive. Dois modos de autenticação:
 *  - OAuth (login de usuário — ex.: a conta do rclone): client_id + client_secret + refresh_token.
 *    É a ponte atual e o mesmo mecanismo do futuro "Conectar meu Drive" por escritório. Tem
 *    precedência quando o refresh_token está presente.
 *  - Service account (Shared Drive): caminho de um JSON.
 *
 * As listagens usam corpora=allDrives (sem driveId), o que cobre tanto pasta compartilhada no
 * My Drive quanto Shared Drive. A "raiz" da sincronização é um folder id comum, passado pelos
 * chamadores (não é necessariamente um Shared Drive).
 *
 * Fronteira de integração: validado manualmente contra o Drive real (não entra no CI). Os testes
 * do domínio usam GoogleDriveClientInterface via fake.
 */
final class GoogleDriveClient implements GoogleDriveClientInterface
{
    /**
     * Tamanho do bloco no upload resumável (8 MB). Deve ser múltiplo de 256 KB (exigência do
     * protocolo resumável do Drive). O pico de memória do envio é ~1 bloco, não o arquivo inteiro.
     */
    private const CHUNK_UPLOAD_BYTES = 8 * 1024 * 1024;

    private ?Client $client = null;
    private ?Drive $drive = null;

    public function __construct(
        private readonly ?string $googleDriveCredentials,
        private readonly string $googleDriveOauthClientId,
        private readonly string $googleDriveOauthClientSecret,
        private readonly string $googleDriveOauthRefreshToken,
    ) {}

    private function client(): Client
    {
        if ($this->client === null) {
            $client = new Client();

            if (trim($this->googleDriveOauthRefreshToken) !== '') {
                // Modo OAuth (conta de usuário). O access token é obtido sob demanda pelo refresh token.
                if (trim($this->googleDriveOauthClientId) === '' || trim($this->googleDriveOauthClientSecret) === '') {
                    throw new \RuntimeException('OAuth do Drive incompleto: faltam GOOGLE_DRIVE_OAUTH_CLIENT_ID/SECRET.');
                }
                $client->setClientId($this->googleDriveOauthClientId);
                $client->setClientSecret($this->googleDriveOauthClientSecret);
                $client->refreshToken($this->googleDriveOauthRefreshToken);
            } elseif ($this->googleDriveCredentials !== null && trim($this->googleDriveCredentials) !== '' && is_file($this->googleDriveCredentials)) {
                // Modo service account (Shared Drive).
                $client->setAuthConfig($this->googleDriveCredentials);
            } else {
                throw new \RuntimeException('Credenciais do Drive ausentes: configure OAuth (refresh token) ou service account (JSON).');
            }

            $client->addScope(Drive::DRIVE);
            $this->client = $client;
        }

        return $this->client;
    }

    private function drive(): Drive
    {
        if ($this->drive === null) {
            $this->drive = new Drive($this->client());
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
                'corpora'                   => 'allDrives',
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
        // Upload RESUMÁVEL em blocos (D8): o arquivo é lido do disco em pedaços e enviado bloco a
        // bloco, sem carregar o conteúdo inteiro em memória (evita OOM em arquivos grandes).
        $tamanho = filesize($caminhoLocal);
        if ($tamanho === false) {
            throw new \RuntimeException(sprintf('Não foi possível medir o arquivo local: %s', $caminhoLocal));
        }

        $metadata = new DriveFile(['name' => $nome, 'parents' => [$folderId]]);

        // Arquivo vazio: sem risco de OOM e o upload resumável em blocos não lida bem com 0 byte
        // (o MediaFileUpload trata chunk vazio como "usar $data", que aqui é null). Envia direto.
        if ($tamanho === 0) {
            $arquivo = $this->drive()->files->create($metadata, [
                'data'              => '',
                'mimeType'          => $mimeType,
                'uploadType'        => 'multipart',
                'fields'            => 'id',
                'supportsAllDrives' => true,
            ]);

            return $arquivo->getId();
        }

        $handle = fopen($caminhoLocal, 'rb');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Não foi possível abrir o arquivo local: %s', $caminhoLocal));
        }

        $client = $this->client();
        // O request é preparado em modo "defer" (não executa) para ser embrulhado pelo MediaFileUpload,
        // que negocia a sessão resumável e faz os PUTs de cada bloco.
        $client->setDefer(true);
        try {
            /** @var RequestInterface $request */
            $request = $this->drive()->files->create($metadata, [
                'fields'            => 'id',
                'supportsAllDrives' => true,
            ]);

            $upload = new MediaFileUpload($client, $request, $mimeType, null, true, self::CHUNK_UPLOAD_BYTES);
            $upload->setFileSize($tamanho);

            $resultado = false;
            while ($resultado === false && !feof($handle)) {
                $bloco = fread($handle, self::CHUNK_UPLOAD_BYTES);
                if ($bloco === false) {
                    throw new \RuntimeException(sprintf('Falha ao ler bloco do arquivo local: %s', $caminhoLocal));
                }
                $resultado = $upload->nextChunk($bloco);
            }
        } finally {
            fclose($handle);
            $client->setDefer(false);
        }

        if (!$resultado instanceof DriveFile) {
            throw new \RuntimeException(sprintf('Upload resumável não concluiu para: %s', $nome));
        }

        return $resultado->getId();
    }

    public function baixarArquivo(string $fileId, string $destinoLocal): void
    {
        // Download em STREAMING direto pro disco (sink do Guzzle): o corpo da resposta é escrito no
        // arquivo à medida que chega, sem passar inteiro pela memória (evita OOM em arquivos grandes).
        $http = $this->client()->authorize();
        $url  = sprintf('https://www.googleapis.com/drive/v3/files/%s?alt=media&supportsAllDrives=true', rawurlencode($fileId));

        $http->request('GET', $url, ['sink' => $destinoLocal]);
    }
}
