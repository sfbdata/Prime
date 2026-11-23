<?php

declare(strict_types=1);

namespace App\Backup\Service;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\NomesDeEntradaDoZip;
use App\Shared\Armazenamento\ArquivoEmprestado;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\Exception\FalhaNoTemporario;
use App\Shared\Armazenamento\MaterializadorDeArquivo;

/**
 * O .zip do backup (spec backup-e-exportacao-de-dados, seções 4 e 5): embrulha o `ZipArchive` e cuida do que todo
 * coletor precisa igual.
 *
 *  - tudo entra sob a raiz `backup-bluejus-AAAA-MM-DD/`; os nomes vêm de {@see NomesDeEntradaDoZip} (sanitizados e
 *    únicos por diretório), então o coletor reserva o diretório com {@see reservarDiretorio()} e nunca monta caminho
 *    com texto do usuário;
 *  - arquivo entra pelo caminho EMPRESTADO (`paraLeitura`, cópia zero): a libzip só lê no `close()`, então os
 *    empréstimos vivem até {@see finalizar()}. Formato já comprimido vai em `CM_STORE`;
 *  - teto de {@see TETO_DE_BYTES} de arquivos por pedido: o que passa fica de fora e vai ao LEIA-ME;
 *  - arquivo que não está no armazenamento, ou linha legada cujo caminho não vira chave, NÃO derruba: vai ao LEIA-ME
 *    como "fora" (pane do storage, `FalhaDeArmazenamento`, sobe: não é ausência);
 *  - cada entrada fica com tamanho e sha256 para o `manifesto.json`.
 *
 * Um objeto por montagem; não é serviço.
 */
final class ZipDoBackup
{
    /** Teto de bytes de arquivos por pedido: 5 GB. */
    public const TETO_DE_BYTES = 5 * 1024 * 1024 * 1024;

    public const NOME_DO_LEIA_ME = 'LEIA-ME.txt';
    public const NOME_DO_MANIFESTO = 'manifesto.json';
    public const VERSAO_DO_FORMATO = 1;

    public const MOTIVO_NAO_ENCONTRADO = 'não foi encontrado no armazenamento';
    public const MOTIVO_CAMINHO_INVALIDO = 'o caminho antigo do arquivo não é válido';
    public const MOTIVO_TETO = 'passou do limite de 5 GB de arquivos por backup';

    private const EXTENSOES_JA_COMPRIMIDAS = [
        'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic',
        'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp',
        'zip', '7z', 'rar', 'gz',
        'mp3', 'm4a', 'ogg', 'opus', 'mp4', 'mov', 'webm',
    ];

    private readonly NomesDeEntradaDoZip $nomes;

    /** @var list<ArquivoEmprestado> vivos até o close(): é lá que a libzip lê */
    private array $emprestados = [];

    /** @var list<array{caminho: string, tipo: string, tamanho_bytes: int, sha256: string}> */
    private array $entradas = [];

    /** @var list<array{item: string, motivo: string}> */
    private array $fora = [];

    /** @var array<string, int> */
    private array $contagens = [];

    private int $bytesDeArquivos = 0;
    private int $arquivos = 0;
    private bool $fechado = false;

    private function __construct(
        private readonly \ZipArchive $zip,
        private readonly MaterializadorDeArquivo $materializador,
        private readonly string $raiz,
        private readonly int $tetoDeBytes,
    ) {
        $this->nomes = new NomesDeEntradaDoZip();
        $this->nomes->reservar('', self::NOME_DO_LEIA_ME);
        $this->nomes->reservar('', self::NOME_DO_MANIFESTO);
    }

    /**
     * @param string $raiz nome do diretório raiz do ZIP, sem barra (ex.: `backup-bluejus-2026-11-23`)
     *
     * @throws FalhaNoTemporario quando o .zip não pode ser criado
     */
    public static function criar(string $caminhoDoZip, MaterializadorDeArquivo $materializador, string $raiz, int $tetoDeBytes = self::TETO_DE_BYTES): self
    {
        $zip    = new \ZipArchive();
        $aberto = $zip->open($caminhoDoZip, \ZipArchive::CREATE | \ZipArchive::EXCL);
        if ($aberto !== true) {
            throw new FalhaNoTemporario(sprintf('Não foi possível criar o .zip em %s (código %s).', $caminhoDoZip, var_export($aberto, true)));
        }

        return new self($zip, $materializador, NomesDeEntradaDoZip::sanitizar($raiz), $tetoDeBytes);
    }

    public function raiz(): string
    {
        return $this->raiz;
    }

    /**
     * Um subdiretório único dentro de `$pai` ('' = raiz do backup), com o nome sanitizado. Devolve o caminho relativo
     * à raiz, para usar em {@see adicionarArquivo()}, {@see adicionarJson()} e como `$pai` dos filhos.
     */
    public function reservarDiretorio(string $pai, string $nome): string
    {
        $nomeFinal = $this->nomes->reservar($pai, $nome, ehPasta: true);

        return $pai === '' ? $nomeFinal : $pai . '/' . $nomeFinal;
    }

    /**
     * Põe um arquivo do armazenamento em `$diretorio` com nome único derivado de `$nome`. Devolve o caminho relativo
     * à raiz, ou NULL quando o arquivo ficou de fora (e foi ao LEIA-ME com o motivo).
     *
     * @param ChaveDeArquivo|\Closure(): ChaveDeArquivo $chave a Closure deixa a chave legada inválida cair aqui, no LEIA-ME
     *
     * @throws FalhaNoTemporario quando a libzip não aceita a entrada
     */
    public function adicionarArquivo(string $diretorio, string $nome, ChaveDeArquivo|\Closure $chave, ?int $tamanhoDeclarado = null): ?string
    {
        $caminho = ($diretorio === '' ? '' : $diretorio . '/') . $this->nomes->reservar($diretorio, $nome);

        try {
            $emprestado = $this->materializador->paraLeitura($chave instanceof \Closure ? $chave() : $chave);
        } catch (ArquivoNaoEncontrado) {
            return $this->deixarFora($caminho, self::MOTIVO_NAO_ENCONTRADO);
        } catch (ChaveDeArquivoInvalida) {
            return $this->deixarFora($caminho, self::MOTIVO_CAMINHO_INVALIDO);
        }

        $tamanho = @filesize($emprestado->caminho());
        $tamanho = $tamanho === false ? (int) $tamanhoDeclarado : $tamanho;
        if ($this->bytesDeArquivos + $tamanho > $this->tetoDeBytes) {
            return $this->deixarFora($caminho, self::MOTIVO_TETO);
        }

        $sha256 = @hash_file('sha256', $emprestado->caminho());
        if ($sha256 === false) {
            throw new FalhaNoTemporario(sprintf('Não foi possível ler "%s" para o backup.', $caminho));
        }

        $this->acrescentar($caminho, $emprestado->caminho());
        $this->emprestados[]    = $emprestado;
        $this->bytesDeArquivos += $tamanho;
        ++$this->arquivos;
        $this->entradas[] = ['caminho' => $caminho, 'tipo' => 'arquivo', 'tamanho_bytes' => $tamanho, 'sha256' => $sha256];

        return $caminho;
    }

    /**
     * Um JSON de dados em `$caminho` (relativo à raiz; o coletor monta com {@see reservarDiretorio()} + nome fixo).
     *
     * @param array<mixed> $dados
     */
    public function adicionarJson(string $caminho, array $dados): void
    {
        $this->adicionarTexto($caminho, json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", 'dados');
    }

    /** Um item que o coletor não pôde pôr (ex.: um registro que a pessoa não vê não entra, mas um erro de dados sim). */
    public function registrarFora(string $item, string $motivo): void
    {
        $this->fora[] = ['item' => $item, 'motivo' => $motivo];
    }

    /** Uma contagem para o LEIA-ME (ex.: "Pastas": 3). Soma. */
    public function contar(string $rotulo, int $quantidade = 1): void
    {
        $this->contagens[$rotulo] = ($this->contagens[$rotulo] ?? 0) + $quantidade;
    }

    public function arquivosIncluidos(): int
    {
        return $this->arquivos;
    }

    /** Quantos arquivos emprestados estão vivos: só até o `finalizar()`, quando a libzip lê e o empréstimo termina. */
    public function emprestimosAbertos(): int
    {
        return count($this->emprestados);
    }

    public function arquivosFora(): int
    {
        return count($this->fora);
    }

    /**
     * Escreve o LEIA-ME e o manifesto e fecha o .zip (é aqui que a libzip lê os arquivos emprestados).
     *
     * @throws FalhaNoTemporario quando o .zip não fecha
     */
    public function finalizar(User $quemPediu, Tenant $tenant, \DateTimeImmutable $em): void
    {
        $this->adicionarTexto(self::NOME_DO_LEIA_ME, $this->leiaMe($quemPediu, $tenant, $em), 'texto', registrar: false);
        $manifesto = json_encode([
            'versao_do_formato' => self::VERSAO_DO_FORMATO,
            'gerado_em'         => $em->format(\DATE_ATOM),
            'escritorio'        => $tenant->getName(),
            'arquivos'          => $this->entradas,
            'fora'              => $this->fora,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $this->adicionarTexto(self::NOME_DO_MANIFESTO, $manifesto, 'manifesto', registrar: false);

        $this->fechado = true;
        if (!$this->zip->close()) {
            throw new FalhaNoTemporario('Não foi possível fechar o .zip do backup: ' . $this->zip->getStatusString());
        }
        $this->emprestados = [];
    }

    /** Desiste da montagem (erro no meio): solta o .zip aberto; o arquivo some com a área temporária. */
    public function descartar(): void
    {
        if (!$this->fechado) {
            $this->fechado = true;
            $this->zip->unchangeAll();
            @$this->zip->close();
        }
        $this->emprestados = [];
    }

    private function adicionarTexto(string $caminho, string $conteudo, string $tipo, bool $registrar = true): void
    {
        $completo = $this->raiz . '/' . $caminho;
        if (!$this->zip->addFromString($completo, $conteudo)) {
            throw new FalhaNoTemporario(sprintf('Não foi possível acrescentar "%s" ao .zip.', $caminho));
        }
        if ($registrar) {
            $this->entradas[] = ['caminho' => $caminho, 'tipo' => $tipo, 'tamanho_bytes' => strlen($conteudo), 'sha256' => hash('sha256', $conteudo)];
        }
    }

    private function acrescentar(string $caminho, string $origem): void
    {
        $completo = $this->raiz . '/' . $caminho;
        if (!$this->zip->addFile($origem, $completo)) {
            throw new FalhaNoTemporario(sprintf('Não foi possível acrescentar "%s" ao .zip.', $caminho));
        }
        [, $extensao] = NomesDeEntradaDoZip::separarExtensao($caminho);
        if ($extensao !== '' && in_array(strtolower(substr($extensao, 1)), self::EXTENSOES_JA_COMPRIMIDAS, true)) {
            $this->zip->setCompressionName($completo, \ZipArchive::CM_STORE);
        }
    }

    private function deixarFora(string $caminho, string $motivo): null
    {
        $this->fora[] = ['item' => $caminho, 'motivo' => $motivo];

        return null;
    }

    private function leiaMe(User $quemPediu, Tenant $tenant, \DateTimeImmutable $em): string
    {
        $linhas = [
            'Backup do BlueJus',
            'Escritório: ' . ($tenant->getName() ?? ''),
            sprintf('Pedido por: %s (%s)', $quemPediu->getFullName(), $quemPediu->getEmail()),
            'Gerado em: ' . $em->format('d/m/Y H:i'),
            '',
            'Este backup traz só o que a pessoa que o pediu pode ver no escritório, no momento em que foi montado.',
            'Os dados estão em arquivos JSON (dados.json) e os documentos nas pastas "documentos".',
            'O arquivo manifesto.json lista cada arquivo com tamanho e sha256, para conferir a integridade.',
            '',
            'Contagens:',
            sprintf('  - Arquivos incluídos: %d (%s)', $this->arquivos, self::tamanhoLegivel($this->bytesDeArquivos)),
        ];
        foreach ($this->contagens as $rotulo => $quantidade) {
            $linhas[] = sprintf('  - %s: %d', $rotulo, $quantidade);
        }
        $linhas[] = sprintf('  - Arquivos que ficaram de fora: %d', count($this->fora));

        if ($this->fora !== []) {
            $linhas[] = '';
            $linhas[] = 'O que ficou de fora e por quê:';
            foreach ($this->fora as ['item' => $item, 'motivo' => $motivo]) {
                $linhas[] = sprintf('  - %s: %s', $item, $motivo);
            }
        }

        $linhas[] = '';
        $linhas[] = 'Nunca entram neste backup: senhas, tokens e segredos, registros internos de auditoria, dados de outros escritórios e fotos de assinatura dos signatários.';

        return implode("\r\n", $linhas) . "\r\n";
    }

    private static function tamanhoLegivel(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 ** 3 => number_format($bytes / 1024 ** 3, 2, ',', '.') . ' GB',
            $bytes >= 1024 ** 2 => number_format($bytes / 1024 ** 2, 1, ',', '.') . ' MB',
            $bytes >= 1024      => number_format($bytes / 1024, 0, ',', '.') . ' KB',
            default             => $bytes . ' B',
        };
    }
}
