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
 * Manda para a LIXEIRA as cópias repetidas (mesmo sha256) de documentos dentro da MESMA pasta e da MESMA seção.
 * Não apaga arquivo: a lixeira guarda 30 dias, a purga agendada apaga depois, e restaurar desfaz.
 *
 * Simulação por padrão: só grava com `--confirmar`, que exige `--saida` (CSV novo, gravado pasta a pasta). Em fatias, como o `calcular-hash`: `--limite=N`
 * (grupos por rodada, a última pasta vem inteira) e `--a-partir-de=<id da pasta>` com o cursor que o
 * resumo imprime. Um COMMIT por pasta. A regra de qual cópia fica mora em
 * {@see EnviarDuplicadosParaALixeiraUseCase}. Não roda sozinho em produção: é do dono.
 */
#[AsCommand(
    name: 'app:documentos:apagar-duplicados',
    description: 'Manda para a lixeira as cópias repetidas (mesmo conteúdo) de documentos dentro da mesma pasta e seção; simulação por padrão',
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
            ->addOption('saida', null, InputOption::VALUE_REQUIRED, 'CSV de detalhe, gravado pasta a pasta (só ids e tamanhos). Obrigatório com --confirmar; recusa arquivo que já existe');
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

        $arquivo = $this->abrirSaida($input, $gravar, $io);
        if ($arquivo === false) {
            return Command::FAILURE;
        }

        $io->title('Limpeza de documentos duplicados (cópias na mesma pasta e na mesma seção)');
        $io->note($gravar ? 'GRAVANDO: as cópias repetidas vão para a lixeira (30 dias, dá para restaurar).' : 'Modo simulação: nada é gravado. Use --confirmar para gravar.');

        try {
            $resultado = $this->limpeza->executar(
                $gravar,
                $tenant,
                $pasta,
                $limite,
                $aPartirDe,
                $arquivo === null ? null : fn (array $decisoes) => $this->gravarLinhas($arquivo, $decisoes, $gravar),
            );
        } finally {
            if ($arquivo !== null) {
                fclose($arquivo);
            }
        }

        $this->imprimirResumo($io, $resultado);

        return Command::SUCCESS;
    }

    /**
     * @return resource|null|false null = sem --saida (só simulação); false = recusado (já reportado)
     */
    private function abrirSaida(InputInterface $input, bool $gravar, SymfonyStyle $io)
    {
        $saida = $input->getOption('saida');
        $saida = \is_string($saida) && $saida !== '' ? $saida : null;
        if ($gravar && $saida === null) {
            $io->error('--confirmar exige --saida=<arquivo.csv>: sem o CSV não há como conferir depois o que foi enviado à lixeira.');

            return false;
        }
        if ($saida === null) {
            return null;
        }

        $arquivo = $this->abrirCsv($saida);
        if ($arquivo === null) {
            $io->error(sprintf('Não consegui criar o CSV em %s (o arquivo já existe ou a pasta não aceita gravação). Use um nome novo por fatia.', $saida));

            return false;
        }

        return $arquivo;
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

    /**
     * Cria o CSV (`xb`: nunca sobrescreve) e escreve o cabeçalho. Só ids e tamanhos, sem nome de
     * cliente nem de arquivo.
     *
     * @return resource|null
     */
    private function abrirCsv(string $caminho)
    {
        $arquivo = @fopen($caminho, 'xb');
        if ($arquivo === false) {
            return null;
        }

        fputcsv($arquivo, ['tenant_id', 'pasta_id', 'secao_id', 'documento_mantido', 'documentos_enviados', 'bytes_enviados', 'motivo', 'modo'], ',', '"', '');
        fflush($arquivo);

        return $arquivo;
    }

    /**
     * As linhas de UMA pasta, logo depois do COMMIT dela: se a execução falhar no meio, o CSV tem
     * tudo o que já foi gravado.
     *
     * @param resource                            $arquivo
     * @param list<DecisaoSobreGrupoDeDuplicados> $decisoes
     */
    private function gravarLinhas($arquivo, array $decisoes, bool $gravou): void
    {
        foreach ($decisoes as $d) {
            fputcsv($arquivo, [$d->tenantId, $d->pastaId, $d->secaoId ?? '', $d->mantidoId ?? '', implode(' ', $d->enviadosIds), $d->bytesEnviados, $d->motivo, $gravou ? 'gravacao' : 'simulacao'], ',', '"', '');
        }
        fflush($arquivo);
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
