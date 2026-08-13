<?php

declare(strict_types=1);

namespace App\Cobranca\Command;

use App\Cobranca\Entity\Carteira;
use App\Cobranca\Repository\RelatorioImportadoRepository;
use App\Cobranca\Service\Espelho\ConferenciaDeEncargos;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lê o encargo GRAVADO no banco contra o que a nossa fórmula produz na data do próprio snapshot
 * (SPEC docs/specs/cobranca-espelho-da-contabilidade.md §17).
 *
 * **Somente leitura.** É a régua que a Fase 1 roda ANTES e DEPOIS de matar a dupla contagem — as
 * outras duas peças do espelho dão o mesmo número nos dois momentos, porque nenhuma delas lê o que
 * está escrito em `cobranca_obrigacao.juros/multa/correcao/honorarios`.
 */
#[AsCommand(
    name: 'app:cobranca:espelho:encargos',
    description: 'Confere o encargo gravado no banco contra a nossa própria fórmula (somente leitura)',
)]
final class ConferirEncargosGravadosCommand extends Command
{
    /**
     * Terceiro estado do código de saída: rodou, não achou dupla contagem, **e não conferiu tudo**.
     * Distinto do `FAILURE` (que é dinheiro duplicado achado) e do `SUCCESS` (que é cobertura total),
     * para que um cron consiga tratar "não deu para conferir" diferente de "está limpo".
     */
    public const COBERTURA_INCOMPLETA = 2;

    public function __construct(
        private readonly ConferenciaDeEncargos $conferencia,
        private readonly RelatorioImportadoRepository $relatorios,
        private readonly TenantRepository $tenants,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('tenant-id', null, InputOption::VALUE_REQUIRED, 'ID do escritório')
            ->addOption('carteira-id', null, InputOption::VALUE_REQUIRED, 'Confere só esta carteira')
            ->addOption('detalhar', null, InputOption::VALUE_NONE, 'Lista as piores diferenças');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tenant = $this->tenants->find((int) $input->getOption('tenant-id'));

        if ($tenant === null) {
            $io->error('Escritório (tenant) não encontrado.');

            return Command::FAILURE;
        }

        $criterio = ['tenant' => $tenant];
        $carteiraId = $input->getOption('carteira-id');

        if ($carteiraId !== null) {
            $criterio['id'] = (int) $carteiraId;
        }

        /** @var list<Carteira> $carteiras */
        $carteiras = $this->em->getRepository(Carteira::class)->findBy($criterio);

        $io->title('O encargo gravado no banco × a nossa fórmula');
        $io->text('Somente leitura. Nenhum encargo é alterado.');
        // Sem esta frase o leitor compara o gravado com a planilha de cabeça e conclui que tudo está
        // errado: as duas coisas foram calculadas em dias diferentes, por desenho (INV-CE1).
        $io->text(
            'A comparação é contra a data do PRÓPRIO snapshot de cada dívida, não contra o relatório — '
            . 'divergir da planilha é esperado e não é medido aqui.'
        );

        $achouDuplaContagem = false;
        $houveInjulgavel = false;

        foreach ($carteiras as $carteira) {
            $lote = $this->relatorios->findUltimoDaCarteira($carteira);

            if ($lote === null) {
                continue;
            }

            $r = $this->conferencia->conferir($lote);

            // O lote nomeado aqui é o do UNIVERSO (quais dívidas entram na conta). As comparações saem
            // de um lote POR DÍVIDA, o que a escreveu (INV-CE6) — dizer só "lote de 12/08" faria o
            // leitor concluir que tudo veio de lá.
            $io->section(sprintf(
                '%s — universo do lote de %s (comparações: o lote que escreveu cada dívida)',
                $r->carteira,
                $r->dadosAte?->format('d/m/Y') ?? '?',
            ));

            $io->table(
                ['situação', 'dívidas'],
                [
                    ['coerente com a fórmula', $r->coerentes],
                    ['🔴 com assinatura de DUPLA CONTAGEM', $r->comDuplaContagem],
                    ['divergente (sem a assinatura)', $r->divergentes],
                    ['— universo', $r->universo],
                ]
            );

            // A conta tem de fechar: perder obrigação pelo caminho num instrumento que decide dinheiro
            // é defeito, não detalhe. Falha alto em vez de imprimir número que não soma.
            if (!$r->baldesFecham()) {
                $io->error(sprintf(
                    'BALDES NÃO FECHAM em %s — a régua perdeu dívida pelo caminho e o relatório não vale.',
                    $r->carteira,
                ));

                return Command::FAILURE;
            }

            $io->text(sprintf(
                'assinatura avaliada: %d (%.2f%% do universo) · sem par no lote que a escreveu: %d',
                $r->assinaturaAvaliada,
                $r->percentualCoberto(),
                $r->semParNoRelatorio,
            ));

            // INV-CE6: sem esta linha, "0 dupla contagem" fica ambíguo entre "conferi e está limpo" e
            // "não tinha contra o que conferir". As duas leituras levam a decisões opostas.
            if ($r->injulgaveis > 0) {
                $io->text(sprintf(
                    '⚠️  INJULGÁVEIS: %d dívida(s) cujo snapshot não corresponde a nenhum lote carregado — '
                    . 'a assinatura delas NÃO foi lida (a coerência com a fórmula, sim). '
                    . 'Carregue o lote da emissão que as escreveu.',
                    $r->injulgaveis,
                ));
            }

            $io->text(sprintf('coerentes: %.2f%% do universo', $r->percentualCoerente()));
            $io->text(sprintf('diferença total: %s', $this->reais($r->diferencaEmCentavos)));

            match ($r->veredito()) {
                'coerente' => $io->success('Todo encargo gravado é um número que a nossa fórmula produz.'),
                'cobertura incompleta' => $io->warning(sprintf(
                    'Nada divergente no que deu para ler — mas %d dívida(s) ficaram sem a assinatura '
                    . 'avaliada. Isto NÃO é "está tudo certo": é "não deu para conferir tudo".',
                    $r->injulgaveis,
                )),
                'divergente' => $io->warning(
                    'Há encargo gravado que a fórmula não reproduz. Snapshot velho e arredondamento explicam '
                    . 'a maior parte; use --detalhar e olhe o tamanho antes de concluir defeito.'
                ),
                'dupla contagem' => $io->error(sprintf(
                    "DINHEIRO CONTADO DUAS VEZES em %d dívida(s): %s\n%s\nO encargo gravado é a coluna do "
                    . 'relatório MAIS o valor da linha de encargo, e o mesmo valor já está no principal. '
                    . 'É o defeito 2 da Fase 1, materializado.',
                    $r->comDuplaContagem,
                    $this->reais($r->duplicadoEmCentavos),
                    $this->porCampo($r->duplicadoPorCampo),
                )),
                default => $io->warning('Sem dado suficiente para conferir esta carteira.'),
            };

            $achouDuplaContagem = $achouDuplaContagem || $r->comDuplaContagem > 0;
            $houveInjulgavel = $houveInjulgavel || $r->injulgaveis > 0;

            if ($input->getOption('detalhar') && $r->piores !== []) {
                $io->table(
                    ['unidade', 'referência', 'campo', 'gravado', 'pela fórmula', 'diferença', 'duplicado', 'acordo?'],
                    array_map(
                        fn (array $p): array => [
                            $p['unidade'],
                            $p['referencia'] ?? '—',
                            $p['campo'],
                            $this->reais($p['gravado']),
                            $this->reais($p['pelaFormula']),
                            $this->reais($p['diferenca']),
                            // A coluna que separa os dois baldes: sem ela a lista mistura dinheiro
                            // duplicado com snapshot velho, e os dois pedem consertos diferentes.
                            $p['duplicado'] > 0 ? $this->reais($p['duplicado']) : '—',
                            $p['ehParcelaDeAcordo'] ? 'sim' : '',
                        ],
                        $r->piores,
                    )
                );
            }
        }

        // O código de saída é o que um cron ou um wrapper enxerga, e ele tem TRÊS estados de propósito.
        // Sair 0 com dívida injulgável seria dizer "tudo certo" para a máquina depois de avisar o
        // humano do contrário — o aviso na tela não chega a quem lê só o exit code.
        if ($achouDuplaContagem) {
            return Command::FAILURE;
        }

        return $houveInjulgavel ? self::COBERTURA_INCOMPLETA : Command::SUCCESS;
    }

    private function reais(int $centavos): string
    {
        return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
    }

    /**
     * A decomposição por campo, com a ressalva que muda a leitura do número: **honorário duplicado
     * não entra no saldo cobrado** (`Obrigacao::valorExigivel()` soma principal + juros + multa +
     * correção). Sem essa frase, o total é lido como "saldo cobrado duas vezes" e não é.
     *
     * @param array<string, int> $porCampo
     */
    private function porCampo(array $porCampo): string
    {
        $partes = [];

        foreach ($porCampo as $campo => $centavos) {
            $partes[] = sprintf('%s %s', $campo, $this->reais($centavos));
        }

        $texto = '  por campo: ' . implode(' · ', $partes);

        if (($porCampo['honorarios'] ?? 0) > 0) {
            $texto .= sprintf(
                "\n  ATENÇÃO: dos %s, o honorário NÃO entra no saldo exigível — só juros, multa e correção entram.",
                $this->reais($porCampo['honorarios']),
            );
        }

        return $texto;
    }
}
