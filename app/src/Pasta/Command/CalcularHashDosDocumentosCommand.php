<?php

declare(strict_types=1);

namespace App\Pasta\Command;

use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Service\ContadorDePaginasDePdf;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\ArquivoEmprestado;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use App\Shared\Armazenamento\MaterializadorDeArquivo;
use App\Shared\Armazenamento\Sha256DeArquivo;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Preenche `pasta_documento.sha256` no acervo anterior à coluna, lendo cada arquivo PELO
 * ARMAZENAMENTO (chave → `abrir()`), em streaming, em lotes. Com `--paginas`, preenche
 * `pasta_documento.paginas` nos PDFs ainda sem contagem (D1), pelo mesmo caminho.
 *
 * ## Regras
 *
 *  - **idempotente**: só toca linha com `sha256 IS NULL` (ou `paginas IS NULL`, no modo
 *    `--paginas`); o que já tem valor não é relido nem regravado. Rodar de novo custa só a fila
 *    que sobrou;
 *  - **nunca carrega um arquivo inteiro na memória**: o hash é de `hash_update_stream`, e cada
 *    lote hidrata no máximo `--lote` documentos antes do `flush()` + `clear()`;
 *  - **arquivo ausente não é pane**: conta como "ausente", fica NULL e o comando segue — é a
 *    inconsistência banco×arquivo que a E1 já conhece, não cabe a este comando decidir sobre ela;
 *  - **pane de leitura** (`FalhaDeArmazenamento`) conta, vai ao log, deixa NULL e segue; no fim
 *    o código de saída é FAILURE para o operador olhar;
 *  - `--dry-run` LÊ os arquivos (é assim que se mede o custo real) mas não grava nada.
 *
 * ## Arquivo liberado do disco (R2, spec `leitura-pelo-r2-e-liberacao-de-disco.md`)
 *
 * O hash MEDE o disco: `$armazenamento` é o `ArmazenamentoLocal` (§5.0, condição 3), e um arquivo
 * que saiu do disco nunca é baixado para ter o hash calculado. Em vez disso, o SHA vem da PROVA que
 * o espelho já guarda (`$espelhado->metadados()`: o envio `verificado` da outbox ou a linha aberta
 * de liberação, os dois conferidos contra o conteúdo do R2). Sem prova, é "ausente", como sempre.
 *
 * O `--paginas` é a exceção: o Ghostscript precisa do PDF inteiro, e o liberado só existe no R2.
 * Por isso ele lê pelo `$espelhado` (o `ArmazenamentoEspelhado`): disco primeiro e, sem o disco,
 * download para o cache de leitura, com o teto, o piso de espaço livre e a trava por SHA dele.
 * Falha do R2 ou piso atingido é "falha" (fica NULL, código de saída FAILURE), nunca "ausente".
 *
 * ## `--paginas`
 *
 * A fila é "PDF com `paginas IS NULL`". O Ghostscript precisa de um caminho local, então o
 * arquivo é EMPRESTADO ao contador (`MaterializadorDeArquivo::paraLeitura`, cópia zero no disco
 * local — nada é apagado). Um PDF que o gs não consegue contar (corrompido, sem página) fica NULL
 * e conta como "sem contagem": não é pane, e volta à fila na próxima execução — é o preço de não
 * gravar zero num arquivo real.
 *
 * ## Fatias com `--a-partir-de`
 *
 * A fila é por id crescente. Quem fica NULL (ausente, falha, sem contagem) continua no começo da
 * fila, e uma nova execução com `--limite` repetiria os mesmos ids. O resumo diz o `ultimo_id`
 * da fatia; a próxima recebe `--a-partir-de=<ultimo_id>` e começa no id seguinte. `a_frente` é
 * quanto ainda falta depois do cursor: chegou a zero, a passada terminou.
 *
 * ## Custo
 *
 * Em produção (P0 do R2, 24/09): ~22.800 documentos / ~26 GB. O custo é I/O de leitura sequencial
 * mais SHA-256 (CPU); o banco recebe um UPDATE por linha, em lotes. Use `--limite` para medir
 * uma amostra (`--dry-run --limite=200`) antes da carga completa, e fatie a carga completa
 * em várias execuções com `--limite` e `--a-partir-de` (acima). Não roda sozinho em produção: é
 * do dono.
 */
#[AsCommand(
    name: 'app:documentos:calcular-hash',
    description: 'Preenche pasta_documento.sha256 (ou, com --paginas, as páginas dos PDFs) nos documentos ainda sem valor, lendo cada arquivo pelo armazenamento',
)]
final class CalcularHashDosDocumentosCommand extends Command
{
    private const LOTE_PADRAO = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaDocumentoRepository $documentos,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly LoggerInterface $logger,
        // O decorador do espelho: dá o SHA dos liberados sem baixar (`metadados`) e empresta o PDF
        // ao `--paginas` (`paraLeitura`), do disco ou do cache do R2.
        private readonly ArmazenamentoDeArquivos&MaterializadorDeArquivo $espelhado,
        private readonly ContadorDePaginasDePdf $contadorDePaginas,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Lê e calcula, mas não grava nada no banco')
            ->addOption('paginas', null, InputOption::VALUE_NONE, 'Em vez do hash, conta as páginas dos PDFs ainda sem contagem (coluna paginas)')
            ->addOption('limite', null, InputOption::VALUE_REQUIRED, 'Processar no máximo N documentos nesta execução')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Só os documentos deste escritório (id do tenant)')
            ->addOption('a-partir-de', null, InputOption::VALUE_REQUIRED, 'Só os documentos com id MAIOR que este (passe o ultimo_id da fatia anterior)')
            ->addOption('lote', null, InputOption::VALUE_REQUIRED, 'Documentos por flush/clear', (string) self::LOTE_PADRAO);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $dryRun  = (bool) $input->getOption('dry-run');
        $paginas = (bool) $input->getOption('paginas');

        $limite    = $this->inteiroPositivoOuNull($input->getOption('limite'), 'limite', $io);
        $tenantId  = $this->inteiroPositivoOuNull($input->getOption('tenant'), 'tenant', $io);
        $lote      = $this->inteiroPositivoOuNull($input->getOption('lote'), 'lote', $io);
        $aPartirDe = $this->inteiroPositivoOuNull($input->getOption('a-partir-de'), 'a-partir-de', $io);

        if ($limite === false || $tenantId === false || $lote === false || $aPartirDe === false) {
            return Command::FAILURE;
        }

        $lote ??= self::LOTE_PADRAO;

        $io->title($paginas ? 'Páginas dos PDFs de pasta' : 'Hash (SHA-256) dos documentos de pasta');
        if ($dryRun) {
            $io->note($paginas
                ? 'Modo simulação: os PDFs são lidos e as páginas contadas, mas nada é gravado.'
                : 'Modo simulação: os arquivos são lidos e o hash calculado, mas nada é gravado.');
        }

        $ids = $paginas
            ? $this->documentos->idsSemPaginas($tenantId, $limite, $aPartirDe)
            : $this->documentos->idsSemSha256($tenantId, $limite, $aPartirDe);
        if ($ids === []) {
            $io->success(($paginas ? 'Nenhum PDF sem contagem de páginas' : 'Nenhum documento sem hash')
                . ($tenantId !== null ? sprintf(' no escritório %d', $tenantId) : '')
                . ($aPartirDe !== null ? sprintf(' depois do id %d', $aPartirDe) : '') . '.');

            return Command::SUCCESS;
        }

        $contadores = [
            'candidatos'  => \count($ids),
            'calculados'  => 0,
            'jaTinham'    => 0,
            'ausentes'    => 0,
            'invalidos'   => 0,
            'falhas'      => 0,
            'semContagem' => 0,
            'doEspelho'   => 0,
            'bytes'       => 0,
            'ultimoId'    => $ids[\array_key_last($ids)],
        ];
        $inicio = hrtime(true);

        $io->text(sprintf('%d documento(s) na fila, em lotes de %d.', $contadores['candidatos'], $lote));
        $io->progressStart($contadores['candidatos']);

        foreach (array_chunk($ids, $lote) as $idsDoLote) {
            /** @var list<PastaDocumento> $docs */
            $docs = $this->documentos->findBy(['id' => $idsDoLote]);

            foreach ($docs as $doc) {
                if ($paginas) {
                    $this->contarPaginas($doc, $dryRun, $contadores, $io);
                } else {
                    $this->processar($doc, $dryRun, $contadores, $io);
                }
                $io->progressAdvance();
            }

            if (!$dryRun) {
                $this->em->flush();
            }

            $this->em->clear();
        }

        $io->progressFinish();

        $contadores['restantes'] = $paginas
            ? $this->documentos->contarSemPaginas($tenantId)
            : $this->documentos->contarSemSha256($tenantId);
        $contadores['aFrente'] = $paginas
            ? $this->documentos->contarSemPaginas($tenantId, $contadores['ultimoId'])
            : $this->documentos->contarSemSha256($tenantId, $contadores['ultimoId']);

        $this->imprimirResumo($io, $contadores, (hrtime(true) - $inicio) / 1e9, $dryRun, $paginas, $tenantId);

        if ($contadores['falhas'] > 0) {
            $io->warning(sprintf('%d documento(s) com falha de leitura (ver o log). Eles continuam NULL e entram numa próxima passada.', $contadores['falhas']));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /** @param array<string, int> $contadores */
    private function imprimirResumo(SymfonyStyle $io, array $contadores, float $segundos, bool $dryRun, bool $paginas, ?int $tenantId): void
    {
        $io->section($dryRun ? 'Resumo (simulado, nada gravado)' : 'Resumo');
        // Uma linha legível por máquina (cron/log), antes da tabela para humanos. Campo novo entra
        // no FIM da linha: quem já lia os campos de sempre continua lendo na mesma posição.
        $io->text($this->linhaDoResumo($contadores, $segundos, $dryRun, $paginas));
        $alvo = $paginas ? 'contagem' : 'hash';
        $io->table(['Métrica', 'Total'], [
            [sprintf('Candidatos (%s NULL) nesta execução', $paginas ? 'paginas' : 'sha256'), $contadores['candidatos']],
            [$dryRun ? ucfirst($alvo) . ' calculado (não gravado)' : ucfirst($alvo) . ' calculado e gravado', $contadores['calculados']],
            [$paginas ? 'PDFs lidos do R2, pelo cache' : 'Hash da prova do espelho, sem baixar (já contados acima)', $contadores['doEspelho']],
            ['Já tinham ' . $alvo . ' (pulados)', $contadores['jaTinham']],
            ['Arquivo ausente no armazenamento (ficam NULL)', $contadores['ausentes']],
            ['Chave inválida (ficam NULL)', $contadores['invalidos']],
            ['Falhas de leitura (ficam NULL)', $contadores['falhas']],
            ['PDF que o Ghostscript não contou (ficam NULL)', $contadores['semContagem']],
            ['Bytes lidos', $this->formatarBytes($contadores['bytes'])],
            ['Duração', sprintf('%.1f s', $segundos)],
            ['Restantes sem ' . $alvo . ($tenantId !== null ? ' (no escritório)' : ''), $contadores['restantes']],
            ['Último id desta fatia', $contadores['ultimoId']],
            ['Sem ' . $alvo . ' depois deste id', $contadores['aFrente']],
        ]);

        if ($contadores['aFrente'] > 0) {
            $io->text(sprintf('Próxima fatia: repita o comando com --a-partir-de=%d', $contadores['ultimoId']));
        }
    }

    /** @param array<string, int> $contadores */
    private function linhaDoResumo(array $contadores, float $segundos, bool $dryRun, bool $paginas): string
    {
        return sprintf(
            'resumo: modo=%s candidatos=%d calculados=%d ja_tinham=%d ausentes=%d invalidos=%d falhas=%d bytes=%d segundos=%.1f restantes=%d alvo=%s sem_contagem=%d ultimo_id=%d a_frente=%d do_espelho=%d',
            $dryRun ? 'simulacao' : 'gravacao',
            $contadores['candidatos'],
            $contadores['calculados'],
            $contadores['jaTinham'],
            $contadores['ausentes'],
            $contadores['invalidos'],
            $contadores['falhas'],
            $contadores['bytes'],
            $segundos,
            $contadores['restantes'],
            $paginas ? 'paginas' : 'sha256',
            $contadores['semContagem'],
            $contadores['ultimoId'],
            $contadores['aFrente'],
            $contadores['doEspelho'],
        );
    }

    /** @param array<string, int> $contadores */
    private function processar(PastaDocumento $doc, bool $dryRun, array &$contadores, SymfonyStyle $io): void
    {
        // Corrida com um upload/edição que preencheu entre a listagem e aqui: não sobrescreve.
        if ($doc->getSha256() !== null) {
            ++$contadores['jaTinham'];

            return;
        }

        $chave = $this->chaveOuNull($doc, $contadores, $io);
        if ($chave === null) {
            return;
        }

        try {
            $sha256 = Sha256DeArquivo::deChave($this->armazenamento, $chave);
            $contadores['bytes'] += $doc->getTamanhoBytes();
        } catch (ArquivoNaoEncontrado) {
            $sha256 = $this->shaDaProvaDoEspelho($doc, $chave, $contadores, $io);
        } catch (FalhaDeArmazenamento $e) {
            $this->registrarFalha($doc, $chave->comoTexto(), $e, $contadores, $io);

            return;
        }

        if ($sha256 === null) {
            return;
        }

        ++$contadores['calculados'];

        if (!$dryRun) {
            $doc->setSha256($sha256);
        }
    }

    /**
     * O arquivo não está no disco: o SHA vem da prova que o espelho já guarda (envio `verificado` da
     * outbox ou linha aberta de liberação), sem baixar nada. Sem prova, é "ausente", como antes.
     *
     * @param array<string, int> $contadores
     */
    private function shaDaProvaDoEspelho(PastaDocumento $doc, ChaveDeArquivo $chave, array &$contadores, SymfonyStyle $io): ?string
    {
        try {
            $sha256 = $this->espelhado->metadados($chave)?->checksum;
        } catch (FalhaDeArmazenamento $e) {
            $this->registrarFalha($doc, $chave->comoTexto(), $e, $contadores, $io);

            return null;
        }

        // O disco não guarda checksum (null): só a prova do espelho traz um SHA-256 em hexadecimal.
        if ($sha256 === null || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            $this->registrarAusente($doc, $chave->comoTexto(), $contadores, $io);

            return null;
        }

        ++$contadores['doEspelho'];

        return $sha256;
    }

    /**
     * O modo `--paginas`: empresta o arquivo ao contador (caminho local, só leitura) e grava o
     * que ele devolver. NULL do contador não é pane — é PDF que não se conta; fica NULL.
     *
     * @param array<string, int> $contadores
     */
    private function contarPaginas(PastaDocumento $doc, bool $dryRun, array &$contadores, SymfonyStyle $io): void
    {
        if ($doc->getPaginas() !== null) {
            ++$contadores['jaTinham'];

            return;
        }

        $chave = $this->chaveOuNull($doc, $contadores, $io);
        if ($chave === null) {
            return;
        }

        try {
            $emprestado = $this->emprestar($chave, $contadores);
        } catch (ArquivoNaoEncontrado) {
            $this->registrarAusente($doc, $chave->comoTexto(), $contadores, $io);

            return;
        } catch (FalhaDeArmazenamento $e) {
            $this->registrarFalha($doc, $chave->comoTexto(), $e, $contadores, $io);

            return;
        }

        $paginas = $this->contadorDePaginas->contar($emprestado->caminho());
        $contadores['bytes'] += $doc->getTamanhoBytes();

        if ($paginas === null) {
            ++$contadores['semContagem'];
            if ($io->isVerbose()) {
                $io->newLine();
                $io->text(sprintf('sem contagem: documento #%d (%s)', (int) $doc->getId(), $chave->comoTexto()));
            }

            return;
        }

        ++$contadores['calculados'];

        if (!$dryRun) {
            $doc->setPaginas($paginas);
        }
    }

    /**
     * Empresta o PDF pelo decorador: do disco quando ele tem; sem o disco, do R2 pelo cache de
     * leitura (teto, piso de espaço livre e trava por SHA do próprio cache). Conta quem veio do R2.
     *
     * @param array<string, int> $contadores
     *
     * @throws ArquivoNaoEncontrado sem o disco e sem prova no espelho
     * @throws FalhaDeArmazenamento R2 fora do ar, piso de espaço atingido, conteúdo que não confere
     */
    private function emprestar(ChaveDeArquivo $chave, array &$contadores): ArquivoEmprestado
    {
        $doR2       = !$this->armazenamento->existe($chave);
        $emprestado = $this->espelhado->paraLeitura($chave);
        $contadores['doEspelho'] += (int) $doR2;

        return $emprestado;
    }

    /** @param array<string, int> $contadores */
    private function chaveOuNull(PastaDocumento $doc, array &$contadores, SymfonyStyle $io): ?ChaveDeArquivo
    {
        try {
            return ChavesDePasta::documento($doc);
        } catch (ChaveDeArquivoInvalida $e) {
            ++$contadores['invalidos'];
            $io->newLine();
            $io->text(sprintf('ERRO: documento #%d: %s', (int) $doc->getId(), $e->getMessage()));

            return null;
        }
    }

    /** @param array<string, int> $contadores */
    private function registrarAusente(PastaDocumento $doc, string $chave, array &$contadores, SymfonyStyle $io): void
    {
        ++$contadores['ausentes'];
        if ($io->isVerbose()) {
            $io->newLine();
            $io->text(sprintf('ausente: documento #%d (%s)', (int) $doc->getId(), $chave));
        }
    }

    /** @param array<string, int> $contadores */
    private function registrarFalha(PastaDocumento $doc, string $chave, FalhaDeArmazenamento $e, array &$contadores, SymfonyStyle $io): void
    {
        ++$contadores['falhas'];
        $io->newLine();
        $io->text(sprintf('ERRO: documento #%d: %s', (int) $doc->getId(), $e->getMessage()));
        $this->logger->error('calcular-hash: falha ao ler o arquivo do documento', [
            'documento' => $doc->getId(),
            'chave'     => $chave,
            'erro'      => $e->getMessage(),
        ]);
    }

    /**
     * @return int|null|false null quando a opção não veio; false quando veio inválida (já reportada)
     */
    private function inteiroPositivoOuNull(mixed $valor, string $nome, SymfonyStyle $io): int|null|false
    {
        if ($valor === null) {
            return null;
        }

        if (!is_string($valor) || preg_match('/^[1-9]\d*$/', $valor) !== 1) {
            $io->error(sprintf('--%s precisa ser um inteiro positivo.', $nome));

            return false;
        }

        return (int) $valor;
    }

    private function formatarBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $unidades = ['B', 'KB', 'MB', 'GB'];
        $i        = min((int) floor(log($bytes) / log(1024)), \count($unidades) - 1);

        return sprintf('%.1f %s', $bytes / (1024 ** $i), $unidades[$i]);
    }
}
