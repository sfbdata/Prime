<?php

declare(strict_types=1);

namespace App\Sync\Service;

use App\Sync\DTO\DriveDoTenant;
use App\Sync\Exception\ImportacaoDoDriveRecusadaException;

/**
 * Lado do servidor do "Importar do Google Drive" (spec importar-do-drive.md). Entrega ao modal a árvore de
 * uma pasta do Drive no mesmo formato de caminho que o navegador dá no lote do computador
 * (`Pasta/Subpasta/arquivo.pdf`), e baixa um arquivo de cada vez para a rota de upload. Toda a regra do
 * lote (pergunta, níveis, número, IA, recusados) fica no JS; aqui só se lê o Drive.
 *
 * Segurança: o token é do escritório e enxerga o Drive inteiro dele. Só vale item que esteja DENTRO da
 * pasta-raiz conectada (`rootFolderId`), conferido pelos `parents` na árvore e de novo em cada arquivo.
 */
final class LoteDoDrive
{
    /** O mesmo teto de caminhos da sugestão da IA. */
    public const LIMITE_DE_ARQUIVOS = 2000;

    /** O maior limite do upload (vídeo e zip, 50 MB): acima disso nem vale baixar. */
    public const TAMANHO_MAXIMO = 50 * 1024 * 1024;

    /** Guarda contra ciclo ou árvore absurda; o JS leva o que passa de 10 níveis para a descrição. */
    private const PROFUNDIDADE_MAXIMA = 40;

    private const PASTA = 'application/vnd.google-apps.folder';

    /** Nativo do Google => [tipo exportado, extensão]. */
    public const EXPORTAVEIS = [
        'application/vnd.google-apps.document'     => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', '.docx'],
        'application/vnd.google-apps.spreadsheet'  => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', '.xlsx'],
        'application/vnd.google-apps.presentation' => ['application/pdf', '.pdf'],
    ];

    public function __construct(private readonly ExtratorDeFolderId $extrator)
    {
    }

    /**
     * `pastas` traz o id de cada pasta pelo caminho, para o lote ligar a pasta criada à de origem.
     *
     * @return array{itens: list<array{id: string, caminho: string, tamanho: int, recusa: ?string}>, pastas: list<array{id: string, caminho: string}>, truncado: bool}
     *
     * @throws ImportacaoDoDriveRecusadaException
     * @throws \InvalidArgumentException quando o texto não traz um id de pasta
     * @throws \App\Sync\Exception\TokenDoDriveRecusadoException|\App\Sync\Exception\DownloadDoDriveFalhouException|\Google\Service\Exception falha do Drive
     */
    public function arvore(DriveDoTenant $drive, string $linkOuId): array
    {
        $pastaId = $this->extrator->extrair($linkOuId);
        $pasta   = $drive->client->metadados($pastaId);
        if ($pasta === null) {
            throw ImportacaoDoDriveRecusadaException::naoEncontrado();
        }
        if ($pasta['mimeType'] !== self::PASTA) {
            throw ImportacaoDoDriveRecusadaException::naoEPasta();
        }
        $this->exigirDentroDaRaiz($drive, $pastaId);

        $itens    = [];
        $pastas   = [];
        $truncado = false;
        $this->percorrer($drive, $pastaId, self::parte($pasta['nome']), 0, $itens, $pastas, $truncado);

        return ['itens' => $itens, 'pastas' => $pastas, 'truncado' => $truncado];
    }

    /**
     * Confere que $pastaId é uma pasta dentro da pasta-raiz do escritório (para ligar a pasta importada a ela).
     *
     * @throws ImportacaoDoDriveRecusadaException
     */
    public function conferirPasta(DriveDoTenant $drive, string $pastaId): void
    {
        $pasta = preg_match('#^[A-Za-z0-9_-]{10,}$#', $pastaId) === 1 ? $drive->client->metadados($pastaId) : null;
        if ($pasta === null) {
            throw ImportacaoDoDriveRecusadaException::naoEncontrado();
        }
        if ($pasta['mimeType'] !== self::PASTA) {
            throw ImportacaoDoDriveRecusadaException::naoEPasta();
        }
        $this->exigirDentroDaRaiz($drive, $pastaId);
    }

    /**
     * Baixa (ou exporta) o arquivo para $destino e devolve o nome com que ele entra na pasta.
     *
     * @throws ImportacaoDoDriveRecusadaException
     * @throws \App\Sync\Exception\TokenDoDriveRecusadoException|\App\Sync\Exception\DownloadDoDriveFalhouException|\Google\Service\Exception falha do Drive
     */
    public function baixar(DriveDoTenant $drive, string $fileId, string $destino): string
    {
        $arquivo = preg_match('#^[A-Za-z0-9_-]{10,}$#', $fileId) === 1 ? $drive->client->metadados($fileId) : null;
        if ($arquivo === null || $arquivo['mimeType'] === self::PASTA) {
            throw ImportacaoDoDriveRecusadaException::naoEncontrado();
        }
        $this->exigirDentroDaRaiz($drive, $fileId);

        $recusa = self::recusa($arquivo['mimeType'], $arquivo['tamanho']);
        if ($recusa !== null) {
            throw ImportacaoDoDriveRecusadaException::tipoNaoAceito($recusa);
        }

        $exportacao = self::EXPORTAVEIS[$arquivo['mimeType']] ?? null;
        if ($exportacao !== null) {
            $drive->client->exportarArquivo($fileId, $exportacao[0], $destino);
        } else {
            $drive->client->baixarArquivo($fileId, $destino);
        }

        return self::nomeFinal($arquivo['nome'], $arquivo['mimeType']);
    }

    /** Por que o arquivo não entra, ou null. Os limites por tipo continuam no upload. */
    public static function recusa(string $mimeType, int $tamanho): ?string
    {
        if (isset(self::EXPORTAVEIS[$mimeType])) {
            return null;
        }
        if ($mimeType === 'application/vnd.google-apps.shortcut') {
            return 'atalho do Drive: importe a pasta ou o arquivo original';
        }
        if (str_starts_with($mimeType, 'application/vnd.google-apps.')) {
            return 'item do Google que não dá para baixar como arquivo';
        }
        if ($tamanho > self::TAMANHO_MAXIMO) {
            return sprintf('excede o limite de %d MB', intdiv(self::TAMANHO_MAXIMO, 1024 * 1024));
        }

        return null;
    }

    /** Nome com a extensão do formato exportado ("Contrato" vira "Contrato.docx"). */
    public static function nomeFinal(string $nome, string $mimeType): string
    {
        $nome      = self::parte($nome);
        $extensao  = self::EXPORTAVEIS[$mimeType][1] ?? '';
        if ($extensao === '' || str_ends_with(mb_strtolower($nome), $extensao)) {
            return $nome;
        }

        return $nome . $extensao;
    }

    /**
     * @param list<array{id: string, caminho: string, tamanho: int, recusa: ?string}> $itens
     * @param list<array{id: string, caminho: string}>                                $pastas
     */
    private function percorrer(DriveDoTenant $drive, string $pastaId, string $caminho, int $nivel, array &$itens, array &$pastas, bool &$truncado): void
    {
        if ($truncado || $nivel > self::PROFUNDIDADE_MAXIMA) {
            return;
        }
        $pastas[] = ['id' => $pastaId, 'caminho' => $caminho];

        foreach ($drive->client->listarArquivos($pastaId) as $a) {
            if (\count($itens) >= self::LIMITE_DE_ARQUIVOS) {
                $truncado = true;

                return;
            }
            $itens[] = [
                'id'      => $a['id'],
                'caminho' => $caminho . '/' . self::nomeFinal($a['nome'], $a['mimeType']),
                'tamanho' => $a['tamanho'],
                'recusa'  => self::recusa($a['mimeType'], $a['tamanho']),
            ];
        }

        foreach ($drive->client->listarSubpastas($pastaId) as $sub) {
            $this->percorrer($drive, $sub['id'], $caminho . '/' . self::parte($sub['nome']), $nivel + 1, $itens, $pastas, $truncado);
        }
    }

    /** Sobe pelos `parents` até a pasta-raiz do escritório; fora dela, recusa. */
    private function exigirDentroDaRaiz(DriveDoTenant $drive, string $id): void
    {
        $raiz     = $drive->rootFolderId;
        $fila     = [$id];
        $vistos   = [];
        while ($fila !== [] && \count($vistos) < self::PROFUNDIDADE_MAXIMA * 4) {
            $atual = array_shift($fila);
            if ($atual === $raiz) {
                return;
            }
            if (isset($vistos[$atual])) {
                continue;
            }
            $vistos[$atual] = true;
            foreach ($drive->client->metadados($atual)['pais'] ?? [] as $pai) {
                $fila[] = $pai;
            }
        }

        throw ImportacaoDoDriveRecusadaException::foraDaRaiz();
    }

    /** Nome de pasta ou arquivo como uma parte só do caminho: a barra viraria um nível a mais no lote. */
    private static function parte(string $nome): string
    {
        $nome = trim(str_replace(['/', '\\'], '-', $nome));

        return $nome !== '' ? $nome : 'sem nome';
    }
}
