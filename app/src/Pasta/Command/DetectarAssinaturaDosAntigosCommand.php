<?php

declare(strict_types=1);

namespace App\Pasta\Command;

use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Service\DetectorDeAssinaturaDigital;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\Exception\ArquivoNaoEncontrado;
use App\Shared\Armazenamento\Exception\ChaveDeArquivoInvalida;
use App\Shared\Armazenamento\Exception\FalhaDeArmazenamento;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Selo "Assinado" no acervo anterior à coluna: procura assinatura digital nos documentos de pasta que
 * ainda não têm o selo e marca os assinados com a origem `digital`, como o upload faz desde a
 * `Version20261020151000`. Roda uma vez depois da publicação (DEPLOY.md); é do dono.
 *
 * ## Regras
 *
 *  - **simulação por padrão**: sem `--aplicar` lê, conta e lista por escritório, e não grava nada;
 *  - **idempotente**: a fila é "sem selo e pode ser assinado" (PDF, `.p7s`, `.p7m`). Documento já
 *    marcado, digital ou manual, não entra na fila e é contado como pulado. Rodar de novo não muda nada;
 *  - **a marca manual nunca é sobrescrita**: a gravação é um UPDATE condicional
 *    (`PastaDocumentoRepository::marcarAssinaturaDigitalSeSemSelo`, `WHERE assinado_em IS NULL` com o id,
 *    o escritório e a pasta do documento), não a entidade carregada no lote. Se alguém marcou pela tela
 *    entre a carga do lote e a gravação, o UPDATE afeta 0 linhas e o documento conta como pulado;
 *  - **lê pelo armazenamento** (chave → `abrir()`), só as pontas do arquivo (`DetectorDeAssinaturaDigital`).
 *    `.p7s`/`.p7m` são assinados pela extensão, sem leitura;
 *  - **arquivo ausente ou ilegível não aborta**: conta como erro, vai ao log e o comando segue. O
 *    documento continua sem selo e volta à fila na próxima execução;
 *  - lotes de `--lote` documentos, com `clear()` entre eles (a entidade só é lida, nunca gravada).
 */
#[AsCommand(
    name: 'app:pasta:detectar-assinatura-antigos',
    description: 'Procura assinatura digital nos documentos de pasta ainda sem o selo "Assinado" e, com --aplicar, marca os assinados (origem digital)',
)]
final class DetectarAssinaturaDosAntigosCommand extends Command
{
    private const LOTE_PADRAO = 200;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PastaDocumentoRepository $documentos,
        private readonly ArmazenamentoDeArquivos $armazenamento,
        private readonly DetectorDeAssinaturaDigital $detector,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('aplicar', null, InputOption::VALUE_NONE, 'Grava o selo (origem digital). Sem a opção, só lista e conta')
            ->addOption('escritorio', null, InputOption::VALUE_REQUIRED, 'Só os documentos deste escritório (id do tenant)')
            ->addOption('limite', null, InputOption::VALUE_REQUIRED, 'Examinar no máximo N documentos nesta execução')
            ->addOption('lote', null, InputOption::VALUE_REQUIRED, 'Documentos por flush/clear', (string) self::LOTE_PADRAO);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $aplicar = (bool) $input->getOption('aplicar');

        $tenantId = $this->inteiroPositivoOuNull($input->getOption('escritorio'), 'escritorio', $io);
        $limite   = $this->inteiroPositivoOuNull($input->getOption('limite'), 'limite', $io);
        $lote     = $this->inteiroPositivoOuNull($input->getOption('lote'), 'lote', $io);
        if ($tenantId === false || $limite === false || $lote === false) {
            return Command::FAILURE;
        }

        $io->title('Selo "Assinado" nos documentos antigos');
        if (!$aplicar) {
            $io->note('Modo simulação: os arquivos são lidos, mas nada é gravado. Use --aplicar para gravar.');
        }

        $contadores    = ['lidos' => 0, 'assinados' => 0, 'pulados' => $this->documentos->contarJaAssinados($tenantId), 'erros' => 0];
        $porEscritorio = [];
        $ids           = $this->documentos->idsParaDetectarAssinatura($tenantId, $limite);
        $io->text(sprintf('%d documento(s) na fila, em lotes de %d.', \count($ids), $lote ?? self::LOTE_PADRAO));

        foreach (array_chunk($ids, $lote ?? self::LOTE_PADRAO) as $idsDoLote) {
            /** @var list<PastaDocumento> $docs */
            $docs = $this->documentos->findBy(['id' => $idsDoLote]);
            foreach ($docs as $doc) {
                $this->processar($doc, $aplicar, $contadores, $porEscritorio, $io);
            }

            $this->em->clear();
        }

        $this->relatar($io, $aplicar, $tenantId, $contadores, $porEscritorio);

        return Command::SUCCESS;
    }

    /**
     * @param array{lidos: int, assinados: int, pulados: int, erros: int}              $contadores
     * @param array<int, array{nome: string, lidos: int, assinados: int, erros: int}> $porEscritorio
     */
    private function relatar(SymfonyStyle $io, bool $aplicar, ?int $tenantId, array $contadores, array $porEscritorio): void
    {
        ksort($porEscritorio);
        $io->section($aplicar ? 'Por escritório' : 'Por escritório (simulado, nada gravado)');
        $io->table(
            ['Escritório', 'Nome', 'Lidos', $aplicar ? 'Assinados (gravados)' : 'Assinados (seriam gravados)', 'Erros'],
            array_map(
                static fn (int $id, array $linha): array => [$id, $linha['nome'], $linha['lidos'], $linha['assinados'], $linha['erros']],
                array_keys($porEscritorio),
                array_values($porEscritorio),
            ),
        );

        $io->section($aplicar ? 'Resumo' : 'Resumo (simulado, nada gravado)');
        // Uma linha legível por máquina (log), antes da tabela para humanos.
        $io->text(sprintf(
            'resumo: modo=%s escritorio=%s lidos=%d assinados=%d pulados=%d erros=%d',
            $aplicar ? 'aplicar' : 'simulacao',
            $tenantId === null ? 'todos' : (string) $tenantId,
            $contadores['lidos'],
            $contadores['assinados'],
            $contadores['pulados'],
            $contadores['erros'],
        ));
        $io->table(['Métrica', 'Total'], [
            ['Lidos (sem selo, PDF/.p7s/.p7m)', $contadores['lidos']],
            [$aplicar ? 'Assinados (selo gravado)' : 'Assinados (o selo seria gravado)', $contadores['assinados']],
            ['Pulados (já tinham o selo, digital ou manual)', $contadores['pulados']],
            ['Erros (arquivo ausente ou ilegível; ficam sem selo)', $contadores['erros']],
        ]);

        if ($contadores['erros'] > 0) {
            $io->warning(sprintf('%d documento(s) não puderam ser lidos (ids no log). Eles continuam sem selo e voltam à fila na próxima execução.', $contadores['erros']));
        }
    }

    /**
     * @param array{lidos: int, assinados: int, pulados: int, erros: int}                       $contadores
     * @param array<int, array{nome: string, lidos: int, assinados: int, erros: int}>          $porEscritorio
     */
    private function processar(PastaDocumento $doc, bool $aplicar, array &$contadores, array &$porEscritorio, SymfonyStyle $io): void
    {
        // Corrida com alguém marcando pela tela entre a listagem e aqui: a marca dele fica.
        if ($doc->estaAssinado()) {
            ++$contadores['pulados'];

            return;
        }

        $tenantId = (int) $doc->getTenant()?->getId();
        $porEscritorio[$tenantId] ??= ['nome' => (string) $doc->getTenant()?->getName(), 'lidos' => 0, 'assinados' => 0, 'erros' => 0];

        ++$contadores['lidos'];
        ++$porEscritorio[$tenantId]['lidos'];

        $assinado = $this->assinado($doc, $io);
        if ($assinado === null) {
            ++$contadores['erros'];
            ++$porEscritorio[$tenantId]['erros'];

            return;
        }

        if (!$assinado) {
            return;
        }

        // Gravação condicional: 0 linhas = alguém marcou (à mão) depois da carga do lote; a marca dele fica.
        if ($aplicar && !$this->documentos->marcarAssinaturaDigitalSeSemSelo($doc)) {
            ++$contadores['pulados'];

            return;
        }

        ++$contadores['assinados'];
        ++$porEscritorio[$tenantId]['assinados'];
        if ($io->isVerbose()) {
            $io->text(sprintf('assinado: documento #%d (%s)', (int) $doc->getId(), $doc->getNomeOriginal()));
        }
    }

    /** Null = não deu para ler (ausente, chave inválida, pane do armazenamento). */
    private function assinado(PastaDocumento $doc, SymfonyStyle $io): ?bool
    {
        if ($this->detector->extensaoAssinada($doc->getNomeOriginal())) {
            return true;
        }

        try {
            $chave  = ChavesDePasta::documento($doc);
            $stream = $this->armazenamento->abrir($chave);
        } catch (ArquivoNaoEncontrado | ChaveDeArquivoInvalida | FalhaDeArmazenamento $e) {
            $this->registrarErro($doc, $e->getMessage(), $io);

            return null;
        }

        try {
            $assinado = $this->detector->streamAssinado($stream);
        } finally {
            if (\is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($assinado === null) {
            $this->registrarErro($doc, 'a leitura do arquivo falhou no meio', $io);
        }

        return $assinado;
    }

    private function registrarErro(PastaDocumento $doc, string $motivo, SymfonyStyle $io): void
    {
        $io->text(sprintf('ERRO: documento #%d: %s', (int) $doc->getId(), $motivo));
        $this->logger->error('detectar-assinatura-antigos: não foi possível ler o arquivo do documento', [
            'documento' => $doc->getId(),
            'erro'      => $motivo,
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
}
