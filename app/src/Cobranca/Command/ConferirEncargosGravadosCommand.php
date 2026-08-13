<?php

declare(strict_types=1);

namespace App\Cobranca\Command;

use App\Cobranca\DTO\ResultadoConferenciaEncargos;
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
     * 🔑 **O CONTRATO DE SAÍDA — todo estado com significado mora na faixa 6x, e isso é decisão.**
     *
     * Dois códigos baixos **não podem** ser usados para significar nada aqui, e ignorar isso já era
     * achado de revisão:
     *
     * - **`1`** é o que o Symfony devolve para **exceção não capturada** (`Application::doRun()`).
     *   Usá-lo para "achei dupla contagem" faria um cron ler "o comando explodiu" como "tem dinheiro
     *   duplicado", e vice-versa — e esta classe **lança** `LogicException` para lote sem `dadosAte`.
     * - **`2`** é `Command::INVALID`, e no mesmo diretório **10 comandos irmãos** já o usam com esse
     *   sentido (`ImportarCadastroCondominosCommand`, `RegistrarEmissaoRelatorioCommand`,
     *   `ImportarRelatorioCobrancaCommand`, `ImportarAcordosDetalhadosCommand`). Dar outro significado
     *   a ele só neste comando é a mesma armadilha, dentro de casa.
     *
     * Nenhum wrapper lê estes códigos hoje (conferido: nada em `scripts/` chama este comando), então
     * mudar agora custa zero — e depois de existir o `--aplicar`, custaria caro.
     */
    public const ERRO_DE_INVOCACAO = 64;

    /**
     * A régua perdeu dívida ou dinheiro pelo caminho: as identidades de `baldesFecham()` não batem.
     * É defeito DA FERRAMENTA, não do dado.
     */
    public const BALDES_NAO_FECHAM = 65;

    /**
     * Nenhuma carteira chegou a ser conferida (não existe, é de outro tenant, ou não tem lote).
     * Sair `0` aqui diria "limpo e completo" sobre coisa nenhuma — é a mesma falha-aberto que a
     * revisão condenou no repositório, e foi medida: `--carteira-id=9999` saía `0`.
     */
    public const NADA_CONFERIDO = 66;

    /** Rodou, não achou dupla contagem, **e não conferiu tudo**. */
    public const COBERTURA_INCOMPLETA = 67;

    /** 🔴 Achou dinheiro contado duas vezes. É o único código que significa "tem defeito no dado". */
    public const DUPLA_CONTAGEM = 68;

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
            ->addOption('detalhar', null, InputOption::VALUE_NONE, 'Lista as piores diferenças (top 20, contra a fórmula)')
            ->addOption(
                'duplicadas',
                null,
                InputOption::VALUE_NONE,
                'Lista COMPLETA das dívidas com dupla contagem — é a lista da reconciliação (contém PII)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $tenant = $this->tenants->find((int) $input->getOption('tenant-id'));

        if ($tenant === null) {
            $io->error('Escritório (tenant) não encontrado.');

            return self::ERRO_DE_INVOCACAO;
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
        $carteirasConferidas = 0;

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
                    // "sem a assinatura" era ambíguo entre "a assinatura rodou e não casou" e "a
                    // assinatura nunca foi lida" — e no dev 100% era o segundo caso.
                    ['a fórmula não reproduz (duplicação não confirmada)', $r->divergentes],
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

                return self::BALDES_NAO_FECHAM;
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

            // A cobertura sai em CAIXA PRÓPRIA e ANTES do veredito, não como texto solto. Medido pela
            // revisão no dev: as três carteiras saíam com 0,00% de cobertura e a única caixa impressa
            // era a de "divergente", afirmando que "arredondamento explica a maior parte" sobre um
            // universo em que a assinatura não foi lida em nenhuma dívida. O veredito `divergente`
            // vem antes de `cobertura incompleta` de propósito (divergência é achado, cobertura é
            // limitação), e sem esta caixa a limitação sumia justamente quando havia os dois.
            if ($r->injulgaveis > 0) {
                $io->warning(sprintf(
                    "COBERTURA INCOMPLETA: a assinatura foi lida em %d de %d dívida(s) (%.2f%%).\n"
                    . "As outras %d NÃO foram absolvidas — não havia lote com que compará-las.\n"
                    . 'Qualquer conclusão abaixo vale só sobre a parte conferida.',
                    $r->assinaturaAvaliada,
                    $r->universo,
                    $r->percentualCoberto(),
                    $r->injulgaveis,
                ));
            }

            match ($r->veredito()) {
                'coerente' => $io->success('Todo encargo gravado é um número que a nossa fórmula produz.'),
                'cobertura incompleta' => $io->warning(
                    'Nada divergente no que deu para ler — e nada a concluir sobre o resto.'
                ),
                'divergente' => $io->warning(
                    'Há encargo gravado que a fórmula não reproduz. Snapshot velho e arredondamento explicam '
                    . 'a maior parte DO QUE FOI CONFERIDO; use --detalhar e olhe o tamanho antes de concluir '
                    . 'defeito.'
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

            ++$carteirasConferidas;
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

            if ($input->getOption('duplicadas')) {
                $this->listarDuplicadas($io, $r);
            }
        }

        // O código de saída é o que um cron ou um wrapper enxerga. `SUCCESS` significa uma coisa só:
        // conferi TUDO e está limpo. Cada outro desfecho tem código próprio (ver o contrato acima).
        if ($carteirasConferidas === 0) {
            $io->warning(
                'Nenhuma carteira foi conferida — ou o critério não casou nenhuma, ou nenhuma tem lote '
                . 'carregado no espelho. Isto NÃO é "está limpo": é "não houve o que conferir".'
            );

            return self::NADA_CONFERIDO;
        }

        if ($achouDuplaContagem) {
            return self::DUPLA_CONTAGEM;
        }

        return $houveInjulgavel ? self::COBERTURA_INCOMPLETA : Command::SUCCESS;
    }

    /**
     * A LISTA DA RECONCILIAÇÃO (INV-CE8) — completa, ordenada pelo duplicado, com o lote de cada linha.
     *
     * É o artefato que o dono aprova ANTES de existir qualquer comando que escreva (decisão de 13/08:
     * "a rede de segurança fica montada antes da faca"). Não confundir com `--detalhar`, que é top-20
     * ordenado pela diferença contra a fórmula e serve para outra pergunta.
     *
     * ⚠️ Contém PII (unidade e número do boleto identificam o devedor): é saída de terminal para o
     * dono, não vai para log nem para o repositório.
     */
    private function listarDuplicadas(SymfonyStyle $io, ResultadoConferenciaEncargos $r): void
    {
        if ($r->duplicadas === []) {
            $io->text('Nenhuma dívida com a assinatura de dupla contagem nesta carteira.');

            return;
        }

        $io->section(sprintf('Lista da reconciliação — %s (%d dívidas)', $r->carteira, count($r->duplicadas)));

        $io->table(
            ['id', 'unidade', 'referência', 'competência', 'lote (emissão)', 'no saldo', 'honorário', 'total'],
            array_map(
                fn (array $d): array => [
                    $d['obrigacaoId'] ?? '—',
                    $d['unidade'],
                    $d['referencia'] ?? '—',
                    $d['competencia'] ?? '—',
                    // O lote é o que permite achar e desfazer um erro de reconciliação três meses
                    // depois; sem ele a linha não é auditável.
                    sprintf('#%s (%s)', $d['loteId'] ?? '?', $d['loteEmitidoEm']?->format('d/m/Y') ?? '?'),
                    $d['duplicadoNoSaldo'] > 0 ? $this->reais($d['duplicadoNoSaldo']) : '—',
                    $d['duplicadoForaDoSaldo'] > 0 ? $this->reais($d['duplicadoForaDoSaldo']) : '—',
                    $this->reais($d['duplicadoNoSaldo'] + $d['duplicadoForaDoSaldo']),
                ],
                $r->duplicadas,
            )
        );

        // Os dois totais SEPARADOS, e nunca somados numa manchete só: o honorário não entra no
        // `valorExigivel()`, então ele não move a conta de devedor nenhum.
        $io->text(sprintf(
            'sai do SALDO do devedor (juros + multa + correção): %s',
            $this->reais($r->duplicadoNoSaldoEmCentavos()),
        ));
        $io->text(sprintf(
            'sai FORA do saldo (honorário — não muda o que ninguém deve): %s',
            $this->reais($r->duplicadoForaDoSaldoEmCentavos()),
        ));
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
