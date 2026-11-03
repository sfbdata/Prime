<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Command;

use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\EspelhoRemoto\EspelhoRemotoNaoConfigurado;
use App\Shared\EspelhoRemoto\Liberacao\ArquivosDoOperador;
use App\Shared\EspelhoRemoto\Liberacao\LiberadorDeDisco;
use App\Shared\EspelhoRemoto\Liberacao\SelecaoParaLiberar;
use App\Shared\EspelhoRemoto\Outbox\OutboxDeReplicacao;
use App\Shared\EspelhoRemoto\PoliticaDeEspelhamento;
use App\Shared\EspelhoRemoto\ProvedorDoEspelhoRemoto;
use App\Shared\Scheduler\Repository\FalhaAgendadaRepository;
use Doctrine\DBAL\Exception as DbalException;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

/**
 * Libera o disco sem esvaziar (spec `leitura-pelo-r2-e-liberacao-de-disco.md` §5.1 a §5.3). Sem
 * `--executar`, só simula: conta os candidatos e o lote, sem baixar, sem abrir linha e sem mover.
 *
 * Com `--executar`, cada arquivo do lote é provado no R2 por download e sai do lugar para
 * `.liberados/<lote>/` (nada é apagado; esvaziar é outra etapa, bloqueada). No fim, as contagens e o
 * manifesto `var/liberacao/<lote>.tsv`.
 *
 * Recusas:
 *  - categoria fora de `pasta_documento` e `pasta_imagem_editor` (§5.6) ou não ligada em
 *    `ARMAZENAMENTO_ESPELHO_CATEGORIAS`;
 *  - com `--executar`: sem `--confirmo-reconciliacao-limpa` (o critério da P2, 7 dias de reconciliação
 *    limpa, não tem registro durável das rodadas boas: quem confere os logs do agendador é o operador),
 *    com falha da reconciliação agendada nos últimos 7 dias (`falha_agendada`), ou com envio da
 *    categoria em `falhou` na outbox.
 *
 * `--sem-acesso`: desliga o critério do `atime`. Com `relatime`, a fatia semanal do SHA da
 * reconciliação lê cada arquivo e renova o `atime`, então o critério deixa tudo de fora; o uso é
 * registrado na spec (§5.2).
 *
 * Uma execução por vez (trava {@see LiberadorDeDisco::TRAVA}, dividida com a restauração). A simulação
 * não trava. Se o manifesto não for gravado, o resumo sai mesmo assim e o comando termina com falha.
 *
 * A tela mostra só contagens e bytes: nome de arquivo e chave remota ficam no manifesto.
 */
#[AsCommand(
    name: 'armazenamento:liberar-disco',
    description: 'Tira do disco os arquivos frios já provados no R2, para .liberados/<lote>/ (sem apagar). Simula por padrão.',
)]
final class LiberarDiscoCommand extends Command
{
    public const COMANDO_DA_RECONCILIACAO = 'armazenamento:reconciliar-espelho';

    public const DIAS_DE_RECONCILIACAO_LIMPA = 7;

    private const LOTE_MAXIMO = 5000;

    public function __construct(
        private readonly LiberadorDeDisco $liberador,
        private readonly ArquivosDoOperador $arquivos,
        private readonly ProvedorDoEspelhoRemoto $fabrica,
        private readonly PoliticaDeEspelhamento $politica,
        private readonly OutboxDeReplicacao $outbox,
        private readonly FalhaAgendadaRepository $falhasAgendadas,
        private readonly ClockInterface $relogio,
        #[Autowire(service: 'lock.agendador.factory')]
        private readonly LockFactory $travas,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('categoria', null, InputOption::VALUE_REQUIRED, 'pasta_documento ou pasta_imagem_editor.')
            ->addOption('frio-dias', null, InputOption::VALUE_REQUIRED, 'Só arquivos com a data do banco (ou o mtime) mais velha que N dias.', '90')
            ->addOption('lote', null, InputOption::VALUE_REQUIRED, 'Quantos arquivos, no máximo, nesta execução.', '200')
            ->addOption('sem-acesso', null, InputOption::VALUE_NONE, 'Não usa o atime (último acesso) no critério de frio.')
            ->addOption('executar', null, InputOption::VALUE_NONE, 'Executa de verdade. Sem ela, só simula.')
            ->addOption('confirmo-reconciliacao-limpa', null, InputOption::VALUE_NONE, 'Confirma que a reconciliação ficou limpa nos últimos 7 dias, sem falhou (logs do agendador). Exigida com --executar.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $opcoes = $this->opcoes($io, $input);
        if (\is_int($opcoes)) {
            return $opcoes;
        }
        [$categoria, $frioDias, $lote] = $opcoes;

        $executar = (bool) $input->getOption('executar');
        if ($executar && !$this->podeExecutar($io, $input, $categoria)) {
            return Command::FAILURE;
        }

        $trava = $this->travas->createLock(LiberadorDeDisco::TRAVA, LiberadorDeDisco::DURACAO_DA_TRAVA_EM_SEGUNDOS);
        if ($executar && !$trava->acquire()) {
            $io->error('Outra liberação ou restauração do disco está em andamento. Espere ela terminar.');

            return Command::FAILURE;
        }

        try {
            return $this->selecionarEExecutar($io, $input, $categoria, $frioDias, $lote, $executar, $trava);
        } finally {
            if ($executar) {
                $trava->release();
            }
        }
    }

    private function selecionarEExecutar(SymfonyStyle $io, InputInterface $input, CategoriaDeArquivo $categoria, int $frioDias, int $lote, bool $executar, LockInterface $trava): int
    {
        $comAcesso = !$input->getOption('sem-acesso');
        $inicio    = hrtime(true);
        try {
            $selecao = $this->liberador->selecionar($categoria, $frioDias, $comAcesso, $lote);
        } catch (DbalException) {
            // A mensagem do DBAL traz os parâmetros, e a chave remota traz o nome do arquivo.
            $io->error('Falha no banco ao selecionar os candidatos. Nada foi baixado, registrado nem movido.');

            return Command::FAILURE;
        }
        $this->mostrarSelecao($io, $categoria, $frioDias, $comAcesso, $executar, $selecao);
        $io->writeln(sprintf('Tempo da seleção: %.1f s.', (hrtime(true) - $inicio) / 1e9));

        if (!$executar) {
            $io->note('Simulação: nada foi baixado, registrado nem movido. Use --executar para liberar.');

            return Command::SUCCESS;
        }

        return $this->executarLote($io, $selecao, $trava);
    }

    /** @return array{0: CategoriaDeArquivo, 1: int, 2: int}|int as opções válidas, ou o código de saída da recusa */
    private function opcoes(SymfonyStyle $io, InputInterface $input): array|int
    {
        $categoria = CategoriaDeArquivo::tryFrom((string) $input->getOption('categoria'));
        if ($categoria === null || !\in_array($categoria, LiberadorDeDisco::CATEGORIAS_LIBERAVEIS, true)) {
            $io->error('--categoria precisa ser pasta_documento ou pasta_imagem_editor: as outras somam pouco e ficam no disco (spec §5.6).');

            return Command::INVALID;
        }
        $frioDias = filter_var($input->getOption('frio-dias'), \FILTER_VALIDATE_INT);
        $lote     = filter_var($input->getOption('lote'), \FILTER_VALIDATE_INT);
        if ($frioDias === false || $frioDias < 1 || $lote === false || $lote < 1 || $lote > self::LOTE_MAXIMO) {
            $io->error(sprintf('--frio-dias precisa ser um inteiro de pelo menos 1, e --lote um inteiro de 1 a %d.', self::LOTE_MAXIMO));

            return Command::INVALID;
        }
        if (!$this->politica->espelha($categoria)) {
            $io->error(sprintf('A categoria %s não está ligada em ARMAZENAMENTO_ESPELHO_CATEGORIAS: sem a cópia no R2, nada sai do disco.', $categoria->value));

            return Command::FAILURE;
        }

        return [$categoria, $frioDias, $lote];
    }

    private function mostrarSelecao(SymfonyStyle $io, CategoriaDeArquivo $categoria, int $frioDias, bool $comAcesso, bool $executar, SelecaoParaLiberar $selecao): void
    {
        $io->title(sprintf('Liberação do disco: %s (%s)', $categoria->value, $executar ? 'execução' : 'simulação'));
        $io->writeln(sprintf('Critério de frio: %d dias pela data do banco (ou mtime)%s.', $frioDias, $comAcesso ? sprintf(', e sem acesso (atime) há %d dias', LiberadorDeDisco::DIAS_SEM_ACESSO) : '; atime ignorado (--sem-acesso)'));
        if ($categoria === CategoriaDeArquivo::PASTA_IMAGEM_EDITOR) {
            $io->note('As imagens do editor não têm referência no banco (moram no HTML da peça): nenhuma é candidata (spec §5.2, item 1).');
        }
        $io->writeln(sprintf('Candidatos: %d arquivo(s), %s.', $selecao->candidatos, self::bytes($selecao->bytesDosCandidatos)));
        $io->writeln(sprintf('Lote (SHA local conferido, do mais antigo para o mais novo): %d arquivo(s), %s.', \count($selecao->lote), self::bytes($selecao->bytesDoLote())));
        $this->tabela($io, 'fora dos candidatos', $selecao->fora);
    }

    private function executarLote(SymfonyStyle $io, SelecaoParaLiberar $selecao, LockInterface $trava): int
    {
        try {
            $espelho = $this->fabrica->criar();
        } catch (EspelhoRemotoNaoConfigurado $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $idDoLote = $this->relogio->now()->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('Ymd-His');
        $resumo   = $this->liberador->liberar($espelho, $selecao->lote, $idDoLote, static function () use ($trava): void {
            $trava->refresh();
        });

        $io->section('Lote ' . $idDoLote);
        $io->writeln(sprintf('Liberados: %d arquivo(s), %s.', \count($resumo->liberados()), self::bytes($resumo->bytesLiberados())));
        $this->tabela($io, 'pulados', $resumo->pulados());
        if ($resumo->comCopiaNosLiberados() !== []) {
            $io->warning(sprintf('%d arquivo(s) ficaram nos liberados com a linha aberta (ver a coluna situacao do manifesto). Confira à mão.', \count($resumo->comCopiaNosLiberados())));
        }
        try {
            $io->writeln('Manifesto: ' . $this->arquivos->gravarManifesto($idDoLote, $resumo));
        } catch (\Throwable $e) {
            $io->error(sprintf('O lote %s foi executado, mas o manifesto não foi gravado (%s). As linhas do lote estão em armazenamento_liberacao.', $idDoLote, $e->getMessage()));

            return Command::FAILURE;
        }
        $io->success(sprintf('Lote %s pronto. Voltar atrás: armazenamento:restaurar-lote --lote=%s --executar', $idDoLote, $idDoLote));

        return Command::SUCCESS;
    }

    /** As recusas do §5.1 que só valem para a execução. */
    private function podeExecutar(SymfonyStyle $io, InputInterface $input, CategoriaDeArquivo $categoria): bool
    {
        if (!$input->getOption('confirmo-reconciliacao-limpa')) {
            $io->error(sprintf(
                'Execução recusada: confirme com --confirmo-reconciliacao-limpa que a reconciliação ficou limpa nos últimos %d dias, sem falhou (logs do agendador). O sistema não guarda as rodadas boas.',
                self::DIAS_DE_RECONCILIACAO_LIMPA,
            ));

            return false;
        }

        $ultimaFalha = $this->falhasAgendadas->ultimaDoComando(self::COMANDO_DA_RECONCILIACAO);
        if ($ultimaFalha !== null && $ultimaFalha > $this->relogio->now()->modify(sprintf('-%d days', self::DIAS_DE_RECONCILIACAO_LIMPA))) {
            $io->error(sprintf('Execução recusada: a reconciliação agendada falhou em %s, há menos de %d dias.', $ultimaFalha->format('d/m/Y H:i'), self::DIAS_DE_RECONCILIACAO_LIMPA));

            return false;
        }

        $falhas = array_sum($this->outbox->contarFalhasPorCategoria([$categoria->value]));
        if ($falhas > 0) {
            $io->error(sprintf('Execução recusada: %d envio(s) de %s em falhou na outbox. Resolva antes (armazenamento:reenfileirar-falhas).', $falhas, $categoria->value));

            return false;
        }

        return true;
    }

    /** @param array<string, int> $contagem */
    private function tabela(SymfonyStyle $io, string $titulo, array $contagem): void
    {
        if ($contagem === []) {
            return;
        }
        $io->table(['motivo (' . $titulo . ')', 'arquivos'], array_map(null, array_keys($contagem), array_values($contagem)));
    }

    private static function bytes(int $bytes): string
    {
        return sprintf('%d bytes (%.2f GB)', $bytes, $bytes / 1024 ** 3);
    }
}
