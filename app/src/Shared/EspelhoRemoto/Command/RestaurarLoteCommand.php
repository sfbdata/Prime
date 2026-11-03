<?php

declare(strict_types=1);

namespace App\Shared\EspelhoRemoto\Command;

use App\Shared\EspelhoRemoto\EspelhoRemoto;
use App\Shared\EspelhoRemoto\EspelhoRemotoNaoConfigurado;
use App\Shared\EspelhoRemoto\FalhaNoEspelhoRemoto;
use App\Shared\EspelhoRemoto\Liberacao\ArquivosDoOperador;
use App\Shared\EspelhoRemoto\Liberacao\LiberacaoAberta;
use App\Shared\EspelhoRemoto\Liberacao\RegistroDeLiberacao;
use App\Shared\EspelhoRemoto\Liberacao\RestauradorDeLote;
use App\Shared\EspelhoRemoto\Liberacao\ResumoDaRestauracao;
use App\Shared\EspelhoRemoto\ProvedorDoEspelhoRemoto;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Volta atrás numa liberação do disco (spec `leitura-pelo-r2-e-liberacao-de-disco.md` §5.4): o lote
 * inteiro (`--lote`) ou um arquivo (`--chave`, a chave remota do manifesto). Sem `--executar`, só
 * simula. Nunca sobrescreve: original já presente é pulado e avisado.
 *
 * `--baixar-para=PASTA` (só com `--chave`): baixa do R2 para a pasta do operador e compara o SHA-256
 * com o da linha. Não toca no disco do sistema, nos liberados nem na linha: é a conferência de que o
 * R2 devolve o que foi provado. Imprime OK ou DIVERGE.
 *
 * A tela mostra só contagens: nome de arquivo e chave remota não aparecem.
 */
#[AsCommand(
    name: 'armazenamento:restaurar-lote',
    description: 'Devolve ao disco os arquivos de um lote liberado (ou de uma chave). Simula por padrão.',
)]
final class RestaurarLoteCommand extends Command
{
    public function __construct(
        private readonly RestauradorDeLote $restaurador,
        private readonly RegistroDeLiberacao $liberacoes,
        private readonly ArquivosDoOperador $arquivos,
        private readonly ProvedorDoEspelhoRemoto $fabrica,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('lote', null, InputOption::VALUE_REQUIRED, 'O lote a restaurar (o id impresso pela liberação).')
            ->addOption('chave', null, InputOption::VALUE_REQUIRED, 'Só esta chave remota (a primeira coluna do manifesto).')
            ->addOption('baixar-para', null, InputOption::VALUE_REQUIRED, 'Com --chave: baixa do R2 para esta pasta e confere o SHA, sem restaurar.')
            ->addOption('executar', null, InputOption::VALUE_NONE, 'Executa de verdade. Sem ela, só simula.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $lote   = trim((string) $input->getOption('lote'));
        $chave  = trim((string) $input->getOption('chave'));
        $baixar = trim((string) $input->getOption('baixar-para'));

        if (($lote === '') === ($chave === '')) {
            $io->error('Informe --lote ou --chave (um dos dois).');

            return Command::INVALID;
        }
        if ($baixar !== '' && $chave === '') {
            $io->error('--baixar-para só vale com --chave.');

            return Command::INVALID;
        }

        return $this->restaurarOuConferir($io, $input, $lote, $chave, $baixar);
    }

    private function restaurarOuConferir(SymfonyStyle $io, InputInterface $input, string $lote, string $chave, string $baixar): int
    {

        $linhas = $chave !== '' ? array_filter([$this->liberacoes->abertaDe($chave)]) : $this->liberacoes->abertasDoLote($lote);
        if ($linhas === []) {
            $io->error($chave !== '' ? 'Essa chave não tem liberação aberta.' : 'Esse lote não tem liberação aberta.');

            return Command::FAILURE;
        }

        if ($baixar !== '') {
            return $this->conferir($io, $linhas[0], $baixar);
        }

        $executar = (bool) $input->getOption('executar');
        try {
            $resumo = $this->restaurador->restaurar($linhas, $executar, fn (): EspelhoRemoto => $this->fabrica->criar());
        } catch (EspelhoRemotoNaoConfigurado $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $this->mostrar($io, $resumo, $executar, \count($linhas));

        return Command::SUCCESS;
    }

    private function mostrar(SymfonyStyle $io, ResumoDaRestauracao $resumo, bool $executar, int $linhas): void
    {
        $io->title(sprintf('Restauração (%s): %d linha(s) aberta(s)', $executar ? 'execução' : 'simulação', $linhas));
        $io->table(['resultado', 'arquivos'], array_map(null, array_keys($resumo->contagem()), array_values($resumo->contagem())));
        if ($resumo->quantos(RestauradorDeLote::ORIGINAL_EXISTE) > 0) {
            $io->warning(sprintf('%d arquivo(s) já estão no lugar original: nada foi sobrescrito, e a linha continua aberta. Confira à mão.', $resumo->quantos(RestauradorDeLote::ORIGINAL_EXISTE)));
        }
        if (!$executar) {
            $io->note('Simulação: nada foi movido, baixado nem fechado. Use --executar para restaurar.');
        }
    }

    private function conferir(SymfonyStyle $io, LiberacaoAberta $linha, string $pasta): int
    {
        if ($linha->chave === null) {
            $io->error('A linha de liberação não vira uma chave válida.');

            return Command::FAILURE;
        }

        try {
            $espelho  = $this->fabrica->criar();
            $chave    = $linha->chave;
            $baixado  = $this->arquivos->baixarPara($pasta, $chave->nome, static function ($destino) use ($espelho, $chave, $linha): void {
                $espelho->baixar($chave, $destino, $linha->tamanhoBytes, $linha->sha256);
            });
        } catch (FalhaNoEspelhoRemoto) {
            $io->error('DIVERGE: o R2 não devolveu o conteúdo provado (ausente, com outro tamanho ou outro SHA-256).');

            return Command::FAILURE;
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($baixado['tamanhoBytes'] !== $linha->tamanhoBytes || !hash_equals($linha->sha256, $baixado['sha256'])) {
            $io->error('DIVERGE: o arquivo baixado não tem o SHA-256 da liberação. A cópia ficou na pasta de --baixar-para.');

            return Command::FAILURE;
        }
        $io->success('OK: o R2 devolveu o mesmo SHA-256 da liberação. A cópia está na pasta de --baixar-para.');

        return Command::SUCCESS;
    }
}
