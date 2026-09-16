<?php

declare(strict_types=1);

namespace App\Sync\Service;

use App\Sync\Exception\DownloadDoDriveFalhouException;
use App\Sync\Exception\TokenDoDriveRecusadoException;
use Google\Client;
use Google\Http\MediaFileUpload;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

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
 * do domínio usam GoogleDriveClientInterface via fake. O download e a renovação do token (DT-8) têm
 * testes próprios contra um dublê da rede (`tests/Sync/Support/DriveHttpFalso`).
 */
final class GoogleDriveClient implements GoogleDriveClientInterface
{
    /**
     * Tamanho do bloco no upload resumável (8 MB). Deve ser múltiplo de 256 KB (exigência do
     * protocolo resumável do Drive). O pico de memória do envio é ~1 bloco, não o arquivo inteiro.
     */
    private const CHUNK_UPLOAD_BYTES = 8 * 1024 * 1024;

    /** Quanto do corpo de uma resposta de erro é lido para achar o motivo (o JSON do Google é pequeno). */
    private const LIMITE_DO_CORPO_DE_ERRO = 8192;
    private const LIMITE_DO_MOTIVO        = 200;
    private const LIMITE_DA_DESCRICAO     = 300;
    /** Forma de um `reason`/`error` do Google; o que fugir dela não entra no diagnóstico. */
    private const IDENTIFICADOR = '/^[A-Za-z0-9_.-]{1,64}$/';

    private ?Client $client = null;
    private ?Drive $drive = null;

    /** Costura de teste (DT-8): o cliente HTTP que o Google usa. Em produção fica nulo e o Google cria o dele. */
    private ?ClientInterface $clienteHttp = null;

    /**
     * Recusa de renovação já vista por esta instância. Uma instância vive uma rodada (ou uma mensagem):
     * pedir de novo um token recusado só gastaria cota; a próxima instância tenta outra vez.
     */
    private ?TokenDoDriveRecusadoException $recusa = null;

    public function __construct(
        private readonly ?string $googleDriveCredentials,
        private readonly string $googleDriveOauthClientId,
        private readonly string $googleDriveOauthClientSecret,
        private readonly string $googleDriveOauthRefreshToken,
    ) {}

    /**
     * Costura de teste (DT-8): o mesmo client, falando por `$clienteHttp` em vez do cliente HTTP que o
     * Google cria. Fica fora do construtor para o autowiring nunca injetar um cliente por engano.
     * `$clienteHttp` tem de ser exclusivo desta instância: o `authorize()` do Google troca o middleware
     * de autenticação na pilha do próprio cliente recebido.
     */
    public static function comClienteHttp(
        ?string $googleDriveCredentials,
        string $googleDriveOauthClientId,
        string $googleDriveOauthClientSecret,
        string $googleDriveOauthRefreshToken,
        ClientInterface $clienteHttp,
    ): self {
        $drive              = new self($googleDriveCredentials, $googleDriveOauthClientId, $googleDriveOauthClientSecret, $googleDriveOauthRefreshToken);
        $drive->clienteHttp = $clienteHttp;

        return $drive;
    }

    private function usaOAuth(): bool
    {
        return trim($this->googleDriveOauthRefreshToken) !== '';
    }

    private function client(): Client
    {
        if ($this->recusa !== null) {
            throw $this->recusa;
        }

        if ($this->client === null) {
            $client = new Client();
            if ($this->clienteHttp !== null) {
                // Antes da renovação do token, que já faz um POST por este cliente.
                $client->setHttpClient($this->clienteHttp);
            }

            if ($this->usaOAuth()) {
                // Modo OAuth (conta de usuário). O access token é obtido sob demanda pelo refresh token.
                if (trim($this->googleDriveOauthClientId) === '' || trim($this->googleDriveOauthClientSecret) === '') {
                    throw new \RuntimeException('OAuth do Drive incompleto: faltam GOOGLE_DRIVE_OAUTH_CLIENT_ID/SECRET.');
                }
                $client->setClientId($this->googleDriveOauthClientId);
                $client->setClientSecret($this->googleDriveOauthClientSecret);
                $this->renovarToken($client);
                $this->preservarRefreshToken($client);
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

    public function renomearPasta(string $folderId, string $novoNome): void
    {
        // No `files.update` o corpo carrega SÓ o que muda. Mandar `parents` aqui é erro da API
        // (mover pasta exige addParents/removeParents como parâmetro, não no corpo), então o
        // metadata tem apenas o nome.
        $this->drive()->files->update($folderId, new DriveFile(['name' => $novoNome]), [
            'fields'            => 'id',
            'supportsAllDrives' => true,
        ]);
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

    /**
     * Download em STREAMING direto pro disco (sink do Guzzle): o corpo da resposta é escrito no
     * arquivo à medida que chega, sem passar inteiro pela memória (evita OOM em arquivos grandes).
     *
     * DT-8: só vale a resposta final 200. Qualquer outra, ou uma falha antes dela, lança — e o
     * destino sai antes, porque o Guzzle já escreveu nele o corpo do erro (ou parte do arquivo).
     */
    public function baixarArquivo(string $fileId, string $destinoLocal): void
    {
        try {
            $this->transferir($fileId, $destinoLocal);
        } catch (\Throwable $e) {
            $this->descartar($destinoLocal);

            throw $e;
        }
    }

    private function transferir(string $fileId, string $destinoLocal): void
    {
        $url = sprintf('https://www.googleapis.com/drive/v3/files/%s?alt=media&supportsAllDrives=true', rawurlencode($fileId));

        try {
            $client = $this->client();
            if ($this->usaOAuth() && $client->isAccessTokenExpired()) {
                // Renova aqui, com a checagem da renovação inicial. Deixada ao middleware do Google, uma
                // renovação que volta sem access token faria o GET sair sem credencial.
                $this->renovarToken($client);
            }
            // `http_errors` forçado: o status é conferido aqui, qualquer que seja a configuração do cliente.
            $resposta = $client->authorize()->request('GET', $url, ['sink' => $destinoLocal, 'http_errors' => false]);
        } catch (TokenDoDriveRecusadoException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Contrato fechado: até um TypeError da biblioteca do Google (resposta de token que não é
            // objeto) vira falha do download. A exceção de origem não é encadeada: a do Guzzle carrega a
            // requisição, com o Authorization.
            $descricao = $this->textoSeguro($e->getMessage(), self::LIMITE_DA_DESCRICAO) ?? 'sem mensagem';
            if ($e instanceof \Error) {
                // Sem a exceção encadeada, um Error perderia onde aconteceu.
                $descricao .= sprintf(' em %s:%d', basename($e->getFile()), $e->getLine());
            }

            throw DownloadDoDriveFalhouException::semResposta($fileId, (new \ReflectionClass($e))->getShortName(), $descricao);
        }

        $status = $resposta->getStatusCode();
        if ($status !== 200) {
            $motivo = $this->motivoDoErro($resposta);
            $resposta->getBody()->close();

            throw DownloadDoDriveFalhouException::respostaInvalida($fileId, $status, $motivo);
        }
    }

    /**
     * Renova o access token pelo refresh token e exige que ele venha. Com o cliente HTTP padrão do
     * Google, a recusa (`invalid_grant`) volta como array, sem exceção, e o `authorize()` seguiria
     * SEM credencial (DT-8).
     */
    private function renovarToken(Client $client): void
    {
        $renovacao   = $client->refreshToken($this->googleDriveOauthRefreshToken);
        $accessToken = is_array($renovacao) ? ($renovacao['access_token'] ?? null) : null;
        if (is_string($accessToken) && $accessToken !== '') {
            if (!$client->isAccessTokenExpired()) {
                return;
            }
            // Token que o Google\Client já dá por vencido (sem `expires_in`, ou dentro da margem de 30 s):
            // o `authorize()` renovaria de novo pelo middleware, sem esta checagem.
            $this->recusa = TokenDoDriveRecusadoException::naRenovacao(null, 'o token renovado veio sem validade utilizável (expires_in)');

            throw $this->recusa;
        }

        $erro = is_array($renovacao) ? ($renovacao['error'] ?? null) : null;
        $erro = is_string($erro) && preg_match(self::IDENTIFICADOR, $erro) === 1 ? $erro : null;

        $this->recusa = TokenDoDriveRecusadoException::naRenovacao($erro, $this->motivoDaRecusa($renovacao, $erro));

        throw $this->recusa;
    }

    /**
     * Remove o destino de um download que não valeu; se não puder removê-lo, ao menos o esvazia. O
     * destino é o temporário do chamador, fora do armazenamento: esvaziar não é gravar conteúdo.
     */
    private function descartar(string $destinoLocal): void
    {
        if (@unlink($destinoLocal)) {
            return;
        }
        clearstatcache(true, $destinoLocal);
        if (!is_file($destinoLocal)) {
            return;
        }
        $arquivo = @fopen($destinoLocal, 'r+');
        if ($arquivo !== false) {
            ftruncate($arquivo, 0);
            fclose($arquivo);
        }
    }

    /** O `reason: message` do corpo de erro da API do Google; qualquer outro corpo não entra no diagnóstico. */
    private function motivoDoErro(ResponseInterface $resposta): ?string
    {
        try {
            $corpo = $resposta->getBody();
            if ($corpo->isSeekable()) {
                $corpo->rewind();
            }
            $dados = json_decode(Utils::copyToString($corpo, self::LIMITE_DO_CORPO_DE_ERRO), true);
        } catch (\RuntimeException) {
            return null;
        }

        $erro = is_array($dados) ? ($dados['error'] ?? null) : null;
        if (!is_array($erro)) {
            return null;
        }

        $partes = [];
        $reason = $erro['errors'][0]['reason'] ?? null;
        if (is_string($reason) && preg_match(self::IDENTIFICADOR, $reason) === 1) {
            $partes[] = $reason;
        }
        if (is_string($erro['message'] ?? null)) {
            $partes[] = $erro['message'];
        }

        return $this->textoSeguro(implode(': ', $partes));
    }

    /** O `error: error_description` que o Google devolve ao recusar a renovação (`$erro` já validado). */
    private function motivoDaRecusa(mixed $renovacao, ?string $erro): ?string
    {
        $partes = $erro !== null ? [$erro] : [];
        if (is_array($renovacao) && is_string($renovacao['error_description'] ?? null)) {
            $partes[] = $renovacao['error_description'];
        }

        return $this->textoSeguro(implode(': ', $partes));
    }

    /**
     * Texto vindo de fora (corpo de erro, mensagem de exceção) pronto para log: numa linha só, sem as
     * credenciais conhecidas e com tamanho limitado. Oculta ANTES de cortar, para não sobrar pedaço
     * de segredo. Devolve null quando não sobra nada.
     */
    private function textoSeguro(string $texto, int $limite = self::LIMITE_DO_MOTIVO): ?string
    {
        $texto = trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/u', ' ', $texto));
        foreach ($this->credenciaisConhecidas() as $credencial) {
            $texto = str_replace($credencial, '***', $texto);
        }
        if ($texto === '') {
            return null;
        }

        return mb_strlen($texto) > $limite ? mb_substr($texto, 0, $limite) . '…' : $texto;
    }

    /** @return list<string> da mais longa para a mais curta, para uma não esconder só parte da outra */
    private function credenciaisConhecidas(): array
    {
        $credenciais = [$this->googleDriveOauthRefreshToken, $this->googleDriveOauthClientSecret];
        $token       = $this->client?->getAccessToken();
        if (is_array($token) && is_string($token['access_token'] ?? null)) {
            $credenciais[] = $token['access_token'];
        }

        $credenciais = array_values(array_filter(
            array_map('trim', $credenciais),
            static fn (string $credencial): bool => $credencial !== '',
        ));
        usort($credenciais, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $credenciais;
    }

    /**
     * O callback padrão do Google regrava o token renovado pelo middleware SEM o refresh token; na
     * expiração seguinte o `authorize()` mandaria o token vencido, sem renovar (DT-8). Este mantém o
     * refresh token do escritório em cada renovação.
     */
    private function preservarRefreshToken(Client $client): void
    {
        $refreshToken = $this->googleDriveOauthRefreshToken;
        $client->setTokenCallback(static function (mixed $chaveDoCache, string $accessToken) use ($client, $refreshToken): void {
            $client->setAccessToken([
                'access_token'  => $accessToken,
                'expires_in'    => 3600, // o mesmo valor do callback padrão do Google
                'created'       => time(),
                'refresh_token' => $refreshToken,
            ]);
        });
    }
}
