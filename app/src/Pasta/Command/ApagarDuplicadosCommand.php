<?php

declare(strict_types=1);

namespace App\Pasta\Command;

use App\Pasta\UseCase\DecisaoSobreGrupoDeDuplicados;
use App\Pasta\UseCase\EnviarDuplicadosParaALixeiraUseCase;
use App\Pasta\UseCase\ResultadoDaLimpezaDeDuplicados;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Manda para a LIXEIRA as cópias repetidas (mesmo sha256) de documentos dentro da MESMA pasta.
 * Não apaga arquivo: a lixeira guarda 30 dias, a purga agendada apaga depois, e restaurar desfaz.
 *
 * Simulação por padrão: só grava com `--confirmar`. Em fatias, como o `calcular-hash`: `--limite=N`
 * (grupos por rodada, a última pasta vem inteira) e `--a-partir-de=<id da pasta>` com o cursor que o
 * resumo imprime. Um COMMIT por pasta. A regra de qual cópia fica mora em
 * {@see EnviarDuplicadosParaALixeiraUseCase}. Não roda sozinho em produção: é do dono.
 */
#[AsCommand(
    name: 'app:documentos:apagar-duplicados',
    description: 'Manda para a lixeira as cópias repetidas (mesmo conteúdo) de documentos dentro da mesma pasta; simulação por padrão',
)]
final class ApagarDuplicadosCommand extends Command
{
    public function __construct(private readonly EnviarDuplicadosParaALixeiraUseCase $limpeza)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('confirmar', null, InputOption::VALUE_NONE, 'Grava de verdade (sem esta opção é só simulação)')
            ->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Só este escritório (id do tenant)')
            ->addOption('pasta', null, InputOption::VALUE_REQUIRED, 'Só esta pasta (id)')
            ->addOption('limite', null, InputOption::VALUE_REQUIRED, 'Grupos repetidos por rodada (a última pasta vem inteira)')
            ->addOption('a-partir-de', null, InputOption::VALUE_REQUIRED, 'Só pastas com id MAIOR que este (passe a última pasta da fatia anterior)')
            ->addOption('saida', null, InputOption::VALUE_REQUIRED, 'Grava o detalhe em CSV (só ids e tamanhos, sem nome de cliente nem de arquivo)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $gravar = (bool) $input->getOption('confirmar');

        $tenant    = $this->inteiroOuNull($input->getOption('tenant'), 'tenant', $io);
        $pasta     = $this->inteiroOuNull($input->getOption('pasta'), 'pasta', $io);
        $limite    = $this->inteiroOuNull($input->getOption('limite'), 'limite', $io);
        $aPartirDe = $this->inteiroOuNull($input->getOption('a-partir-de'), 'a-partir-de', $io);
        if ($tenant === false || $pasta === false || $limite === false || $aPartirDe === false) {
            return Command::FAILURE;
        }

        $io->title('Limpeza de documentos duplicados (cópias na mesma pasta)');
        $io->note($gravar ? 'GRAVANDO: as cópias repetidas vão para a lixeira (30 dias, dá para restaurar).' : 'Modo simulação: nada é gravado. Use --confirmar para gravar.');

        $resultado = $this->limpeza->executar($gravar, $tenant, $pasta, $limite, $aPartirDe);

        $saida = $input->getOption('saida');
        if (\is_string($saida) && $saida !== '' && !$this->gravarCsv($saida, $resultado)) {
            $io->error(sprintf('Não consegui gravar o CSV em %s.', $saida));

            return Command::FAILURE;
        }

        $this->imprimirResumo($io, $resultado);

        return Command::SUCCESS;
    }

    private function imprimirResumo(SymfonyStyle $io, ResultadoDaLimpezaDeDuplicados $r): void
    {
        $io->text(sprintf(
            'resumo: modo=%s grupos=%d copias_enviadas=%d bytes_a_liberar=%d pastas=%d pulados=%d ultima_pasta=%s a_frente=%d',
            $r->gravou ? 'gravacao' : 'simulacao',
            $r->gruposVistos(),
            $r->copiasEnviadas(),
            $r->bytesALiberar(),
            $r->pastasTocadas,
            \count($r->pulados()),
            $r->ultimaPasta ?? '-',
            $r->gruposAFrente,
        ));
        $io->table(['Métrica', 'Total'], [
            ['Grupos de cópias repetidas vistos', $r->gruposVistos()],
            [$r->gravou ? 'Cópias enviadas à lixeira' : 'Cópias que iriam à lixeira (simulação)', $r->copiasEnviadas()],
            ['Bytes a liberar (só quando a purga de 30 dias rodar)', $r->bytesALiberar()],
            ['Pastas com cópias enviadas', $r->pastasTocadas],
            ['Grupos pulados', \count($r->pulados())],
            ['Última pasta desta fatia', $r->ultimaPasta ?? '-'],
            ['Grupos em pastas depois dela', $r->gruposAFrente],
        ]);

        foreach ($r->pulados() as $d) {
            $io->text(sprintf('pulado: pasta %d, motivo %s', $d->pastaId, $d->motivo));
        }

        if ($r->gruposAFrente > 0 && $r->ultimaPasta !== null) {
            $io->text(sprintf('Próxima fatia: repita o comando com --a-partir-de=%d', $r->ultimaPasta));
        }
    }

    /** Uma linha por grupo: só ids e tamanhos. */
    private function gravarCsv(string $caminho, ResultadoDaLimpezaDeDuplicados $r): bool
    {
        $arquivo = @fopen($caminho, 'wb');
        if ($arquivo === false) {
            return false;
        }

        fputcsv($arquivo, ['tenant_id', 'pasta_id', 'documento_mantido', 'documentos_enviados', 'bytes_enviados', 'motivo', 'modo'], ',', '"', '');
        foreach ($r->decisoes as $d) {
            fputcsv($arquivo, $this->linhaDoCsv($d, $r->gravou), ',', '"', '');
        }

        return fclose($arquivo);
    }

    /** @return list<int|string> */
    private function linhaDoCsv(DecisaoSobreGrupoDeDuplicados $d, bool $gravou): array
    {
        return [$d->tenantId, $d->pastaId, $d->mantidoId ?? '', implode(' ', $d->enviadosIds), $d->bytesEnviados, $d->motivo, $gravou ? 'gravacao' : 'simulacao'];
    }

    /** @return int|null|false null quando a opção não veio; false quando veio inválida (já reportada) */
    private function inteiroOuNull(mixed $valor, string $nome, SymfonyStyle $io): int|null|false
    {
        if ($valor === null) {
            return null;
        }
        if (!\is_string($valor) || preg_match('/^[1-9]\d*$/', $valor) !== 1) {
            $io->error(sprintf('--%s precisa ser um inteiro positivo.', $nome));

            return false;
        }

        return (int) $valor;
    }
}
