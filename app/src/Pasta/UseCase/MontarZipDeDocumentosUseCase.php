<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\DTO\ZipDeDocumentosOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Exception\SelecaoAcimaDoTetoException;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Pasta\Service\NomesDeEntradaDoZip;
use App\Pasta\Service\SelecaoDeItensDaPasta;
use App\Shared\Armazenamento\AreaTemporariaPrivada;
use App\Shared\Armazenamento\ArquivoEmprestado;
use App\Shared\Armazenamento\ArquivoGeradoParaEntrega;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\FalhaNoTemporario;
use App\Shared\Armazenamento\MaterializadorDeArquivo;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Monta o .zip de uma seleção da aba Documentos — o "Baixar como .zip (N)" (D5, DOC-46).
 *
 * Quem dispara é um usuário que pode VER a pasta; a rota provou a posse de todos os ids numa
 * consulta (404 sem efeito parcial) e aqui cada item é reconferido ({@see SelecaoDeItensDaPasta}).
 * O que ele quer: os arquivos no computador, com a estrutura de subpastas que vê na tela.
 *
 * Regras:
 *  - subpasta selecionada leva a subárvore VIVA inteira: o `LixeiraFilter` deixa a lixeira de fora
 *    das duas consultas que carregam a árvore (uma de seções, uma de documentos), expandida em
 *    memória — zero consultas por nível;
 *  - os tetos (S-11: {@see TETO_DE_ARQUIVOS} e {@see TETO_DE_BYTES}, pela soma de `tamanho_bytes`)
 *    são conferidos ANTES de abrir qualquer arquivo — recusar é barato, montar 1 GB não é;
 *  - nomes de entrada sanitizados e únicos por diretório ({@see NomesDeEntradaDoZip}); subpasta
 *    sem arquivo entra como diretório vazio, para a estrutura não sumir;
 *  - o .zip nasce numa {@see AreaTemporariaPrivada} (qualquer parcial some com ela) e sai de lá
 *    como {@see ArquivoGeradoParaEntrega}: a entrega HTTP o apaga depois de enviar;
 *  - cada arquivo entra pelo caminho EMPRESTADO (`MaterializadorDeArquivo::paraLeitura`, cópia
 *    zero, nada é apagado nem reescrito) — a libzip só lê no `close()`, então os empréstimos vivem
 *    até lá;
 *  - documento cuja linha existe mas o arquivo não está no armazenamento NÃO aborta: fica fora do
 *    .zip e entra no `LEIA-ME.txt` como "não encontrado". Pane do storage
 *    (`FalhaDeArmazenamento`) sobe — não é ausência (D10);
 *  - entrada já comprimida por dentro (pdf, imagem, Office, zip, áudio/vídeo) vai em `CM_STORE`:
 *    deflate nela só gasta CPU no `close()`;
 *  - antes de montar, as sobras do diretório privado com mais de 1 h (processo que morreu,
 *    entrega que não aconteceu) são removidas por `ArquivoGeradoParaEntrega::limparSobras()` —
 *    cortesia que nunca derruba a montagem;
 *  - o download é registrado no `audit_log` à mão (ação {@see ACAO_AUDITORIA}, `Pasta` + id, ids
 *    da seleção, contagem e bytes): não há entidade mudando, então o `AuditLogSubscriber` não o
 *    veria. `zip` — e não `download_zip` — porque `audit_log.action` é `VARCHAR(10)`.
 */
final class MontarZipDeDocumentosUseCase
{
    /** Arquivos por .zip (S-11). */
    public const TETO_DE_ARQUIVOS = 500;

    /** Soma de `tamanho_bytes` por .zip: 1 GB (S-11). */
    public const TETO_DE_BYTES = 1024 * 1024 * 1024;

    /** A ação gravada no `audit_log` (coluna de 10 caracteres). */
    public const ACAO_AUDITORIA = 'zip';

    public const NOME_DO_LEIA_ME = 'LEIA-ME.txt';

    /** O diretório do processo onde o .zip nasce e espera a entrega: `jusprime-zip-<uid>`. */
    private const FINALIDADE_DA_AREA = 'zip';

    /**
     * Entradas que a libzip NÃO tenta comprimir (`CM_STORE`): formatos já comprimidos por dentro —
     * deflate neles custa CPU no `close()` e devolve bytes a mais. Decidido pela extensão do nome
     * da entrada (minúsculas); o resto segue o padrão (deflate).
     */
    private const EXTENSOES_JA_COMPRIMIDAS = [
        'pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic',
        'docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp',
        'zip', '7z', 'rar', 'gz',
        'mp3', 'm4a', 'ogg', 'opus', 'mp4', 'mov', 'webm',
    ];

    public function __construct(
        private readonly PastaDocumentoRepository $documentoRepository,
        private readonly PastaSecaoRepository $secaoRepository,
        private readonly MaterializadorDeArquivo $materializador,
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<PastaDocumento> $documentos
     * @param list<PastaSecao>     $secoes
     *
     * @throws SelecaoAcimaDoTetoException acima de um dos tetos (nada foi aberto)
     * @throws \InvalidArgumentException   seleção vazia, de outra pasta, ou sem arquivo nenhum
     */
    public function executar(Pasta $pasta, array $documentos, array $secoes, User $autor, Tenant $tenant): ZipDeDocumentosOutput
    {
        $selecao = SelecaoDeItensDaPasta::de($documentos, $secoes, $pasta, $tenant);
        $plano   = $this->planejar($pasta, $selecao, $tenant);

        if ($plano['arquivos'] === []) {
            throw new \InvalidArgumentException('A seleção não tem arquivos para baixar.');
        }
        if (count($plano['arquivos']) > self::TETO_DE_ARQUIVOS) {
            throw SelecaoAcimaDoTetoException::porArquivos(count($plano['arquivos']), self::TETO_DE_ARQUIVOS);
        }
        if ($plano['bytes'] > self::TETO_DE_BYTES) {
            throw SelecaoAcimaDoTetoException::porBytes($plano['bytes'], self::TETO_DE_BYTES);
        }

        $this->limparSobras();

        $incluidos      = [];
        $naoEncontrados = [];
        $area           = AreaTemporariaPrivada::criar(self::FINALIDADE_DA_AREA);

        try {
            $caminhoDoZip = $area->caminho() . '/documentos.zip';
            $zip          = new \ZipArchive();
            $aberto       = $zip->open($caminhoDoZip, \ZipArchive::CREATE | \ZipArchive::EXCL);
            if ($aberto !== true) {
                throw new FalhaNoTemporario(sprintf('Não foi possível criar o .zip em %s (código %s).', $caminhoDoZip, var_export($aberto, true)));
            }

            foreach ($plano['pastas'] as $diretorio) {
                $zip->addEmptyDir($diretorio);
            }

            /** @var list<ArquivoEmprestado> $emprestados até o close(): é lá que a libzip lê */
            $emprestados = [];
            foreach ($plano['arquivos'] as ['documento' => $documento, 'entrada' => $entrada]) {
                try {
                    $emprestado = $this->materializador->paraLeitura(ChavesDePasta::documento($documento));
                } catch (ArquivoNaoEncontrado $e) {
                    $naoEncontrados[] = $entrada;
                    $this->logger->warning('Documento sem arquivo no armazenamento ficou fora do .zip.', [
                        'documento_id' => $documento->getId(),
                        'pasta_id'     => $pasta->getId(),
                        'erro'         => $e->getMessage(),
                    ]);

                    continue;
                }

                if (!$zip->addFile($emprestado->caminho(), $entrada)) {
                    throw new FalhaNoTemporario(sprintf('Não foi possível acrescentar "%s" ao .zip.', $entrada));
                }
                if (self::jaComprimida($entrada)) {
                    $zip->setCompressionName($entrada, \ZipArchive::CM_STORE);
                }
                $emprestados[] = $emprestado;
                $incluidos[]   = ['entrada' => $entrada, 'bytes' => $documento->getTamanhoBytes()];
            }

            $zip->addFromString(self::NOME_DO_LEIA_ME, $this->leiaMe($pasta, $autor, $tenant, $incluidos, $naoEncontrados));

            if (!$zip->close()) {
                throw new FalhaNoTemporario('Não foi possível fechar o .zip: ' . $zip->getStatusString());
            }

            $arquivo = ArquivoGeradoParaEntrega::retirarDa($area, $caminhoDoZip, self::FINALIDADE_DA_AREA);
        } finally {
            $area->liberar();
        }

        $bytes = array_sum(array_column($incluidos, 'bytes'));

        try {
            $this->registrarAuditoria($pasta, $autor, $tenant, $selecao, count($incluidos), count($naoEncontrados), $bytes, $arquivo);
        } catch (\Throwable $e) {
            // Sem rastro não há download: o .zip gerado não pode ficar esperando ninguém.
            $arquivo->descartar();

            throw $e;
        }

        return new ZipDeDocumentosOutput(
            arquivo: $arquivo,
            nomeDoZip: self::nomeDoZip($pasta),
            arquivos: count($incluidos),
            naoEncontrados: count($naoEncontrados),
            bytes: $bytes,
        );
    }

    /**
     * O que vai entrar, com o nome de cada entrada já decidido — SEM abrir arquivo nenhum.
     *
     * @return array{arquivos: list<array{documento: PastaDocumento, entrada: string}>, pastas: list<string>, bytes: int}
     */
    private function planejar(Pasta $pasta, SelecaoDeItensDaPasta $selecao, Tenant $tenant): array
    {
        $nomes = new NomesDeEntradaDoZip();
        $nomes->reservar('', self::NOME_DO_LEIA_ME); // o LEIA-ME fica com o nome dele; o do usuário vira "(2)"

        $plano = ['arquivos' => [], 'pastas' => [], 'bytes' => 0];

        // Documentos selecionados por conta própria vão na raiz do .zip.
        foreach ($selecao->documentos as $documento) {
            $plano['arquivos'][] = ['documento' => $documento, 'entrada' => $nomes->reservar('', $documento->getNomeOriginal())];
            $plano['bytes']     += $documento->getTamanhoBytes();
        }

        if ($selecao->secoes === []) {
            return $plano;
        }

        // A árvore VIVA da pasta em duas consultas (o LixeiraFilter deixa a lixeira de fora),
        // expandida em memória — como o explorador faz para contar.
        $filhasPor = [];
        foreach ($this->secaoRepository->findByPasta($pasta, $tenant) as $secao) {
            $filhasPor[$secao->getPai()?->getId() ?? 0][] = $secao;
        }
        $documentosPor = [];
        foreach ($this->documentoRepository->findByPastaComSecao($pasta, $tenant) as $documento) {
            $documentosPor[$documento->getSecao()?->getId() ?? 0][] = $documento;
        }

        foreach ($selecao->secoes as $secao) {
            $this->percorrer($secao, '', $filhasPor, $documentosPor, $nomes, $plano, [(int) $secao->getId() => true]);
        }

        return $plano;
    }

    /**
     * Desce a subárvore de `$secao`, acumulando entradas em `$plano`. `$visitados` carrega o caminho
     * percorrido: um ciclo gravado no banco vira galho ignorado, não recursão infinita (o mesmo
     * guarda de `ExploradorDeDocumentosOutput::contarArvore()`).
     *
     * @param array<int, list<PastaSecao>>     $filhasPor
     * @param array<int, list<PastaDocumento>> $documentosPor
     * @param array{arquivos: list<array{documento: PastaDocumento, entrada: string}>, pastas: list<string>, bytes: int} $plano
     * @param array<int, true>                 $visitados
     */
    private function percorrer(PastaSecao $secao, string $diretorioPai, array $filhasPor, array $documentosPor, NomesDeEntradaDoZip $nomes, array &$plano, array $visitados): void
    {
        $nome      = $nomes->reservar($diretorioPai, $secao->getNome(), ehPasta: true);
        $diretorio = $diretorioPai === '' ? $nome : $diretorioPai . '/' . $nome;
        $id        = (int) $secao->getId();

        $documentos = $documentosPor[$id] ?? [];
        if ($documentos === []) {
            // Sem arquivo a pasta não teria entrada nenhuma e sumiria da estrutura.
            $plano['pastas'][] = $diretorio;
        }

        foreach ($documentos as $documento) {
            $plano['arquivos'][] = [
                'documento' => $documento,
                'entrada'   => $diretorio . '/' . $nomes->reservar($diretorio, $documento->getNomeOriginal()),
            ];
            $plano['bytes'] += $documento->getTamanhoBytes();
        }

        foreach ($filhasPor[$id] ?? [] as $filha) {
            $filhaId = (int) $filha->getId();
            if (isset($visitados[$filhaId]) || count($visitados) >= PastaSecao::LIMITE_SEGURANCA) {
                continue;
            }
            $this->percorrer($filha, $diretorio, $filhasPor, $documentosPor, $nomes, $plano, $visitados + [$filhaId => true]);
        }
    }

    /**
     * @param list<array{entrada: string, bytes: int}> $incluidos
     * @param list<string>                             $naoEncontrados
     */
    private function leiaMe(Pasta $pasta, User $autor, Tenant $tenant, array $incluidos, array $naoEncontrados): string
    {
        $bytes  = array_sum(array_column($incluidos, 'bytes'));
        $linhas = [
            'Documentos da pasta ' . ($pasta->getNup() ?? ('#' . $pasta->getId())),
        ];
        if ($pasta->getNomeCliente() !== null && $pasta->getNomeCliente() !== '') {
            $linhas[] = 'Pasta: ' . $pasta->getNomeCliente();
        }
        $linhas[] = 'Escritório: ' . ($tenant->getName() ?? '');
        $linhas[] = sprintf('Gerado em: %s por %s (%s)', $this->clock->now()->format('d/m/Y H:i'), $autor->getFullName(), $autor->getEmail());
        $linhas[] = sprintf('Arquivos incluídos: %d (%s)', count($incluidos), SelecaoAcimaDoTetoException::tamanhoLegivel((int) $bytes));
        $linhas[] = 'Não encontrados no armazenamento: ' . count($naoEncontrados);
        $linhas[] = '';
        $linhas[] = 'Arquivos:';
        foreach ($incluidos as ['entrada' => $entrada, 'bytes' => $tamanho]) {
            $linhas[] = sprintf('  - %s (%s)', $entrada, SelecaoAcimaDoTetoException::tamanhoLegivel($tamanho));
        }
        if ($naoEncontrados !== []) {
            $linhas[] = '';
            $linhas[] = 'Não encontrados (ficaram fora deste .zip):';
            foreach ($naoEncontrados as $entrada) {
                $linhas[] = '  - ' . $entrada;
            }
        }

        return implode("\r\n", $linhas) . "\r\n";
    }

    private function registrarAuditoria(Pasta $pasta, User $autor, Tenant $tenant, SelecaoDeItensDaPasta $selecao, int $arquivos, int $naoEncontrados, int $bytes, ArquivoGeradoParaEntrega $zip): void
    {
        $tamanhoDoZip = @filesize($zip->caminho());

        $log = (new AuditLog())
            ->setAction(self::ACAO_AUDITORIA)
            ->setEntityClass(Pasta::class)
            ->setEntityId((string) $pasta->getId())
            ->setTenantId($tenant->getId())
            ->setActorUserId($autor->getId())
            ->setActorEmail($autor->getEmail())
            ->setChanges([
                'documentos'      => array_map(static fn (PastaDocumento $d): int => (int) $d->getId(), $selecao->documentos),
                'secoes'          => array_map(static fn (PastaSecao $s): int => (int) $s->getId(), $selecao->secoes),
                'arquivos'        => $arquivos,
                'nao_encontrados' => $naoEncontrados,
                'bytes'           => $bytes,
                'zip_bytes'       => $tamanhoDoZip === false ? null : $tamanhoDoZip,
            ]);

        $this->em->persist($log);
        $this->em->flush();
    }

    /**
     * Cortesia antes de montar: sobras de montagens cujo processo morreu, ou de entregas que não
     * aconteceram, com mais de 1 h no diretório privado do processo. Nunca derruba a montagem.
     */
    private function limparSobras(): void
    {
        try {
            $removidas = ArquivoGeradoParaEntrega::limparSobras(self::FINALIDADE_DA_AREA);
        } catch (\Throwable $e) {
            $this->logger->warning('Limpeza das sobras de .zip falhou; a montagem segue.', ['erro' => $e->getMessage()]);

            return;
        }

        if ($removidas > 0) {
            $this->logger->info('Sobras antigas de .zip removidas.', ['removidas' => $removidas]);
        }
    }

    /** A extensão do nome da ENTRADA (não do nome no banco) decide; `.2/nome` de um diretório não conta. */
    private static function jaComprimida(string $entrada): bool
    {
        [, $extensao] = NomesDeEntradaDoZip::separarExtensao($entrada);

        return $extensao !== '' && in_array(strtolower(substr($extensao, 1)), self::EXTENSOES_JA_COMPRIMIDAS, true);
    }

    /** `documentos-<NUP só com [A-Za-z0-9._-]>.zip`; sem NUP utilizável, o id da pasta. */
    private static function nomeDoZip(Pasta $pasta): string
    {
        $slug = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $pasta->getNup()), '-.');
        if ($slug === '') {
            $slug = 'pasta-' . $pasta->getId();
        }

        return 'documentos-' . $slug . '.zip';
    }
}
