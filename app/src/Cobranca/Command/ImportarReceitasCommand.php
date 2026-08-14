<?php

declare(strict_types=1);

namespace App\Cobranca\Command;

use App\Cobranca\Service\Espelho\GuardaDeLogComPii;
use App\Cobranca\Service\Importacao\RecorteEsperado;
use App\Cobranca\Service\Importacao\ResultadoImportacaoReceitas;
use App\Cobranca\Service\Importacao\ResultadoLeituraReceitas;
use App\Cobranca\Service\Importacao\TopLifeReceitasAdapter;
use App\Cobranca\Service\Importacao\RegistrarEmissaoNaCarteira;
use App\Cobranca\Service\Importacao\ValidadorRodapeFiltros;
use App\Cobranca\UseCase\ImportarReceitasUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Repository\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Importa o relatório "Receitas detalhadas por unidade/cliente" (.xlsx) da contábil L.G — spec
 * `docs/specs/cobranca-importar-receitas.md`. É o 4º e último relatório, o que responde *o que foi pago*.
 *
 * Mesmo contrato dos irmãos: DRY-RUN por padrão, `--confirmar` persiste.
 *
 * **CLI e não tela** porque são ~5.100 linhas válidas: a tela morre no timeout de 30s (medido no
 * precedente da TOP LIFE I, 2.901 boletos → 500). Em produção: `scp` → `docker cp` →
 * `docker exec -w /var/www/app`, com `APP_DEBUG=0` e `memory_limit` alto.
 *
 * ⚠️ **O que este comando cria é diferente dos irmãos.** Ele não só registra recebimento: quando o
 * boleto não existe no sistema (medido: 96% dos casos), ele **cria a obrigação já paga** — decisão R1
 * do dono. Rodar isto contra uma carteira grande dobra o acervo. O dry-run existe para você ver esse
 * número ANTES.
 *
 * Uso:
 *   php bin/console app:cobranca:importar-receitas \
 *     --tenant-id=1 --carteira-id=3 --usuario-id=1 --arquivo=/tmp/receitas.xlsx          # dry-run
 *   php bin/console app:cobranca:importar-receitas ... --confirmar                       # persiste
 */
#[AsCommand(
    name: 'app:cobranca:importar-receitas',
    description: 'Importa as receitas detalhadas (.xlsx): registra o que foi pago e cria o boleto que o sistema não conhecia',
)]
final class ImportarReceitasCommand extends Command implements LidaComDadoPessoal
{
    use ConfereRecorteDoArquivo;

    public function __construct(
        private readonly GuardaDeLogComPii $guardaDeLog,
        private readonly ImportarReceitasUseCase $importar,
        private readonly TopLifeReceitasAdapter $adapter,
        private readonly ValidadorRodapeFiltros $validadorRodape,
        private readonly TenantRepository $tenantRepository,
        private readonly EntityManagerInterface $em,
        private readonly RegistrarEmissaoNaCarteira $registrarEmissao,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'aceito-log-com-pii',
                null,
                InputOption::VALUE_NONE,
                'Roda mesmo com o log de SQL ligado. A saída conterá dado pessoal.',
            )
            ->addOption('tenant-id', null, InputOption::VALUE_REQUIRED, 'ID do escritório (tenant) dono da carteira')
            ->addOption('carteira-id', null, InputOption::VALUE_REQUIRED, 'ID da carteira de cobrança destino')
            ->addOption('usuario-id', null, InputOption::VALUE_REQUIRED, 'ID do usuário (membro do tenant) que assina a importação')
            ->addOption('arquivo', null, InputOption::VALUE_REQUIRED, 'Caminho do .xlsx de receitas detalhadas')
            ->addOption('confirmar', null, InputOption::VALUE_NONE, 'Persiste (sem esta flag é dry-run/prever)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // 🔴 INV-Q10: com o log de SQL ligado, os parâmetros das consultas — dado pessoal
        // por extenso — saem na tela. Ver {@see GuardaDeLogComPii}.
        if ($this->guardaDeLog->bloqueia($io, (bool) $input->getOption('aceito-log-com-pii'), 'app:cobranca:importar-receitas')) {
            return GuardaDeLogComPii::LOG_COM_PII;
        }

        $tenantId = (int) $input->getOption('tenant-id');
        $carteiraId = (int) $input->getOption('carteira-id');
        $usuarioId = (int) $input->getOption('usuario-id');
        $arquivo = (string) $input->getOption('arquivo');
        $confirmar = (bool) $input->getOption('confirmar');

        if ($tenantId <= 0 || $carteiraId <= 0 || $usuarioId <= 0 || $arquivo === '') {
            $io->error('Informe --tenant-id, --carteira-id, --usuario-id e --arquivo.');

            return Command::INVALID;
        }

        if (!is_readable($arquivo)) {
            $io->error(sprintf('Arquivo não encontrado ou sem permissão de leitura: %s', $arquivo));

            return Command::INVALID;
        }

        $tenant = $this->tenantRepository->find($tenantId);
        if ($tenant === null) {
            $io->error(sprintf('Tenant %d não encontrado.', $tenantId));

            return Command::FAILURE;
        }

        $user = $this->em->getRepository(User::class)->find($usuarioId);
        if ($user === null) {
            $io->error(sprintf('Usuário %d não encontrado.', $usuarioId));

            return Command::FAILURE;
        }

        // Guarda multi-tenant: o usuário que assina precisa ser membro do escritório informado.
        if ($this->em->getRepository(UserTenant::class)->findOneBy(['user' => $user, 'tenant' => $tenant]) === null) {
            $io->error(sprintf('Usuário %d não pertence ao tenant %d.', $usuarioId, $tenantId));

            return Command::FAILURE;
        }

        if (!$this->recorteConfere($io, $arquivo, RecorteEsperado::receitas())) {
            return Command::INVALID;
        }

        $leitura = $this->adapter->ler($arquivo);
        $io->section(sprintf(
            'Leitura: %d recebimento(s) · %d em aberto (descartados) · %d rejeição(ões) · %d linha(s) de rodapé',
            count($leitura->receitas),
            $leitura->emAberto,
            count($leitura->rejeitadas),
            $leitura->linhasIgnoradas,
        ));

        if ($leitura->emAberto > 0) {
            $io->note(sprintf(
                '%d linha(s) com Recebimento "-" foram DESCARTADAS: são boletos em aberto, não receita. '
                . 'Se este número surpreender, o export foi feito incluindo a situação "Aberta".',
                $leitura->emAberto,
            ));
        }

        foreach ($leitura->rejeitadas as $rejeitada) {
            $io->writeln(sprintf('  <comment>%s</comment> — %s', $rejeitada->referencia, $rejeitada->motivo));
        }

        // Classe de conta fora do mapa da spec §5: caiu no principal por OMISSÃO, não por decisão.
        // Hoje são só as raras conhecidas (1.12, 1.19, 1.22). O aviso existe para o dia em que a
        // contábil criar um código novo: sem ele, o dinheiro trocaria de balde em silêncio — o total
        // fecharia igual e o rateio do `Pagamento` sairia errado.
        if ($leitura->classesForaDoMapa !== []) {
            $linhas = [];
            foreach ($leitura->classesForaDoMapa as $codigo => $qtd) {
                $linhas[] = sprintf('%s (%d linha(s))', $codigo, $qtd);
            }

            $io->note(sprintf(
                "Classe(s) de conta fora do mapa conhecido, somadas ao PRINCIPAL: %s.\n"
                . 'Confira contra o quadro "Total recebido por classe de conta" do rodapé do relatório: '
                . 'se alguma dessas não for taxa/principal, o balde está errado (o total fecha do mesmo jeito).',
                implode(' · ', $linhas),
            ));
        }

        // ⚠️ ANTES da escrita, não depois. Este aviso saía de `imprimirTotais`, que roda DEPOIS de
        // `confirmar()` — quem importasse veria os boletos de R$ 0,00 já gravados, e um aviso pós-fato
        // não devolve conferência a ninguém. (A §9.1 já não é decisão pendente — ver o método.)
        //
        // Sai da LEITURA e não do resultado de propósito: a leitura é o que se sabe antes de escrever.
        // A contagem é conservadora (inclui o que a idempotência viria a ignorar), o que é o lado certo
        // de errar num aviso que existe para segurar a mão de quem confirma.
        $this->avisarSemPrincipal($io, $leitura, $confirmar);

        // ⚠️ A PROJEÇÃO SEMPRE PRIMEIRO, inclusive com --confirmar. Os avisos abaixo dependem do banco
        // (não dá para derivá-los da leitura, como o de "sem principal"), e um aviso de dinheiro que
        // sai DEPOIS da gravação não devolve decisão nenhuma a ninguém — foi o bloqueante que a 2ª
        // revisão da etapa 2 achou. No dry-run isto não custa nada: a projeção É o resultado.
        $projecao = $this->importar->prever($carteiraId, $leitura, $tenant);

        $this->avisarAcordosIncompletos($io, $projecao, $confirmar);
        $this->avisarDinheiroParado($io, $projecao, $confirmar);
        $this->avisarImpactoDaReativacao($io, $projecao, $confirmar);
        $this->avisarTrocaDeAcordo($io, $projecao, $confirmar);
        $this->avisarPalpiteDeAlocacao($io, $projecao);

        // 🔑 Marcador explícito da fronteira. Sem ele, "o aviso sai antes da gravação" é INTESTÁVEL
        // pela saída: mover os avisos para depois de `confirmar()` não mudaria uma vírgula do texto,
        // porque `imprimirTotais` continua sendo o último. Com esta linha, tudo o que aparece acima
        // dela foi impresso antes de a transação abrir — e um teste consegue medir isso.
        if ($confirmar) {
            $io->section('>>> GRAVANDO (--confirmar). Tudo acima desta linha saiu ANTES da escrita.');
        }

        $resultado = $confirmar
            ? $this->importar->confirmar($carteiraId, $leitura, $tenant, $user)
            : $projecao;

        // Só depois da importação CONFIRMADA: a carteira passa a saber até quando os dados vão, e a tela
        // mostra isso. Falha aqui não afeta o que foi importado (o serviço engole a própria exceção).
        if ($confirmar) {
            $this->registrarEmissao->registrar($carteiraId, $tenant, RegistrarEmissaoNaCarteira::RECEITAS, $arquivo);
        }

        $this->imprimirTotais($io, $resultado, $confirmar);

        if (!$confirmar) {
            $io->warning(
                'DRY-RUN: nada foi gravado. Confira os números acima — em especial quantas obrigações '
                . 'seriam CRIADAS — e repita com --confirmar.',
            );

            return Command::SUCCESS;
        }

        $io->success('Receitas importadas.');
        $io->note(
            'O acervo cresceu: os boletos criados nascem QUITADOS e não mexem no saldo devedor, mas '
            . 'passam a aparecer como histórico pago das unidades.',
        );

        return Command::SUCCESS;
    }

    private function imprimirTotais(SymfonyStyle $io, ResultadoImportacaoReceitas $r, bool $confirmar): void
    {
        $io->section($confirmar ? 'O que foi feito' : 'O que aconteceria');
        $io->table(
            ['Item', 'Quantidade'],
            [
                ['Recebimentos registrados', count($r->pagamentosCriados)],
                ['— em obrigação que JÁ existia', count($r->obrigacoesExistentes)],
                ['— com obrigação CRIADA (boleto novo no sistema)', count($r->obrigacoesCriadas)],
                // Fica AQUI, colado nos recebimentos, e não depois de "Casos abertos": o travessão puxa
                // o olho para a linha de cima, e lá em baixo ele dizia "destes casos" quando o número é
                // de RECEBIMENTOS (na TOP LIFE I, 160 de 1.220 — logo abaixo de "Casos abertos 119").
                ['— destes, PARCELAS de acordo (coluna J)', count($r->parcelasDeAcordo)],
                ['Já importados antes (ignorados)', count($r->jaImportados)],
                ['Unidades criadas', $r->objetosCriados],
                ['Pessoas criadas', $r->pessoasCriadas],
                ['Casos abertos', $r->casosCriados],
                ['Acordos criados', $r->acordosCriados],
                ['— que ficam CUMPRIDOS (todas as parcelas pagas)', $r->acordosCumpridos],
                ['— que ficam INCOMPLETOS (faltam parcelas)', count($r->acordosIncompletos)],
                ['Acordos REATIVADOS pela importação (D6)', $r->acordosReativados],
            ],
        );
        // Os TRÊS baldes, e não só o total: o rodapé do relatório da contábil imprime o recebido por
        // classe de conta, então cada linha daqui confere contra uma linha de lá. Só o total bateria
        // mesmo com uma troca entre baldes — principal virando encargo, por exemplo.
        $io->table(
            ['Dinheiro', 'Valor', 'Confere contra (rodapé da planilha)'],
            [
                ['Total recebido (bruto do devedor)', $this->reais($r->totalRecebidoCentavos), 'Total de receitas'],
                ['— principal (já líquido de descontos)', $this->reais($r->principalCentavos()), '1.1 + 1.12 + 1.14 + 1.19 + 1.22 + 1.6'],
                ['— juros e multa', $this->reais($r->encargosCentavos), '1.4 + 1.5'],
                ['— honorários do escritório', $this->reais($r->honorariosCentavos), '1.15'],
            ],
        );
        $io->note('Confira o total recebido contra a soma da coluna "Valor recebido" da planilha: têm de bater ao centavo.');
    }

    /**
     * Recebimento sem principal é **parcela de acordo** — a §9.1 da spec de receitas está RESOLVIDA,
     * e foi a coluna J que resolveu: 37 de 37 na TOP LIFE I. Numa parcela, não ter taxa é NORMAL,
     * porque o acordo redistribui a dívida ao longo das prestações e a primeira pode ficar só com
     * honorário. A etapa 3 faz essas nascerem como parcela.
     *
     * O aviso não sumiu porque continua havendo um caso que merece olho: coluna J **vazia**, e aí o
     * recebimento entraria como boleto avulso de valor original R$ 0,00, descrito como "Taxa MM/AAAA"
     * sem taxa nenhuma. Contar os dois juntos — e chamar o conjunto de "decisão do dono, ainda
     * ABERTA" — fez esta pendência ser repetida como aberta meses depois de fechada.
     *
     * ⚠️ A contagem é do ARQUIVO INTEIRO, não do que vai entrar: a maior parte costuma já estar
     * importada (em 11/08/2026, 7.493 de 7.536 na TOP LIFE I). O que entra sai na tabela de resultado.
     *
     * Nenhum centavo se perde em nenhuma hipótese — o total recebido fecha ao centavo com a
     * contabilidade de qualquer jeito. O que está em jogo é a FORMA do que entra.
     */
    private function avisarSemPrincipal(SymfonyStyle $io, ResultadoLeituraReceitas $leitura, bool $confirmar): void
    {
        $parcelas = [];
        $avulsos = [];
        $centavos = 0;

        foreach ($leitura->receitas as $receita) {
            if (!$receita->semPrincipal()) {
                continue;
            }

            $centavos += $receita->totalRecebidoCentavos();

            if ($receita->acordo !== null) {
                $parcelas[] = $receita->nn;

                continue;
            }

            $avulsos[] = $receita->nn;
        }

        if ($parcelas === [] && $avulsos === []) {
            return;
        }

        $linhas = [sprintf(
            '%d recebimento(s) do arquivo, somando %s, NÃO têm principal — são só honorário e/ou juros/multa.',
            count($parcelas) + count($avulsos),
            $this->reais($centavos),
        )];

        if ($parcelas !== []) {
            $linhas[] = sprintf(
                '%d são parcela(s) de acordo (coluna J): ali não ter principal é NORMAL — o acordo '
                . 'redistribui a dívida ao longo das parcelas. NN: %s',
                count($parcelas),
                $this->listarNns($parcelas),
            );
        }

        if ($avulsos !== []) {
            $linhas[] = sprintf(
                '%d estão sem acordo na coluna J e entrariam como boleto AVULSO de valor original '
                . 'R$ 0,00 — estes merecem conferência. NN: %s',
                count($avulsos),
                $this->listarNns($avulsos),
            );
        }

        $linhas[] = $confirmar
            ? '>>> Você passou --confirmar: o que ainda não estiver importado SERÁ gravado a seguir. <<<'
            : 'Dry-run: nada será gravado.';

        // Só é alarme quando há o caso que pede conferência; se é tudo parcela de acordo, é informação.
        $texto = implode("\n", $linhas);
        $avulsos === [] ? $io->note($texto) : $io->warning($texto);
    }

    /** @param list<string> $nns */
    private function listarNns(array $nns): string
    {
        return implode(', ', array_slice($nns, 0, 40))
            . (count($nns) > 40 ? sprintf(' … (+%d)', count($nns) - 40) : '');
    }

    /**
     * Decisão B1: os acordos cujas parcelas futuras nenhuma fonte traz nascem só com as pagas, e o dono
     * fica sabendo QUAIS são para pedir a planilha à contábil. Nada é sintetizado.
     *
     * Medido em 03/08: 31 acordos ficam incompletos; 27 deles têm aba no relatório "Acordos detalhados"
     * (rodar aquele importador DEPOIS completa as parcelas futuras) e **4 não têm fonte nenhuma** — os
     * acordos 212, 230, 237 e 280, somando 71 parcelas.
     */
    private function avisarAcordosIncompletos(SymfonyStyle $io, ResultadoImportacaoReceitas $r, bool $confirmar): void
    {
        if ($r->acordosIncompletos === []) {
            return;
        }

        $faltando = 0;
        $terminados = 0;
        $comAnteriores = 0;
        $linhas = [];
        foreach ($r->acordosIncompletos as $acordo) {
            $faltando += $acordo['total'] - $acordo['pagas'];

            // ⚠️ O export é filtrado por VENCIMENTO. Se a menor parcela paga não é a 1, as anteriores
            // faltam por estarem FORA DA JANELA — já foram pagas, não são futuras. E se a MAIOR é a
            // última, o acordo terminou de ser pago: ele só continua `Ativo` porque a régua conta o que
            // este arquivo traz (spec §5.3), e é o lado certo de errar.
            $antes = $acordo['menorPaga'] > 1;
            $terminou = $acordo['maiorPaga'] === $acordo['total'];
            $terminados += $terminou ? 1 : 0;
            $comAnteriores += $antes ? 1 : 0;

            $linhas[] = sprintf(
                'Acordo %d: %d de %d pagas (parcelas %d a %d)%s%s',
                $acordo['numero'], $acordo['pagas'], $acordo['total'],
                $acordo['menorPaga'], $acordo['maiorPaga'],
                $antes ? sprintf(' · faltam as 1..%d, ANTERIORES à janela', $acordo['menorPaga'] - 1) : '',
                $terminou ? ' · ÚLTIMA PAGA: o acordo terminou' : '',
            );
        }

        $io->note(sprintf(
            "%d acordo(s) ficam INCOMPLETOS — %d parcela(s) que este arquivo não traz.\n"
            . "⚠️ NÃO são todas futuras: em %d deles a menor parcela paga não é a 1, ou seja, as anteriores\n"
            . "ficaram FORA DA JANELA do relatório (filtrado por vencimento) e já foram pagas. E em %d a\n"
            . "ÚLTIMA parcela está paga — o acordo terminou, e só a régua conservadora o mantém Ativo.\n"
            . "Nada é inventado (decisão B1). Rode \"Acordos detalhados\" DEPOIS desta importação para\n"
            . "completar os que têm aba lá; para os demais, peça à contábil o extrato do acordo.\n"
            . "%s\n%s",
            count($r->acordosIncompletos),
            $faltando,
            $comAnteriores,
            $terminados,
            $confirmar ? '>>> Você passou --confirmar: eles SERÃO gravados assim a seguir. <<<' : 'Dry-run: nada será gravado.',
            implode("\n", array_slice($linhas, 0, 40))
                . (count($linhas) > 40 ? sprintf("\n… (+%d)", count($linhas) - 40) : ''),
        ));
    }

    /**
     * ⚠️ O único ponto desta etapa em que dinheiro já recebido pode parar de abater o saldo.
     *
     * A reativação de D6 devolve as obrigações originais ao estado "substituída" e elas saem do
     * exigível; `CalculadoraSaldo` só abate alocação de obrigação exigível. Se alguém tiver recebido
     * numa original enquanto o acordo estava rompido, esse pagamento deixa de abater — e o devedor
     * volta a ser cobrado por algo que já pagou.
     *
     * O importador não decide para onde vai dinheiro de terceiro. Ele avisa ANTES da gravação, com o
     * caso, o NN e o valor.
     */
    private function avisarDinheiroParado(SymfonyStyle $io, ResultadoImportacaoReceitas $r, bool $confirmar): void
    {
        if ($r->reativacoesComDinheiroParado === []) {
            return;
        }

        // A lista aqui é SÓ de obrigação com pagamento alocado — o impacto genérico no saldo saiu para
        // `avisarImpactoDaReativacao`. Misturados, este texto ("o devedor já pagou") disparava também
        // onde ninguém tinha pagado nada: alarme falso em aviso de dinheiro ensina a ignorar o aviso.
        $io->warning(sprintf(
            "D6 · DINHEIRO JÁ PAGO QUE PARA DE ABATER: %d obrigação(ões) originais com pagamento alocado saem do exigível.\n"
            . "Esse dinheiro deixa de abater o saldo, e o devedor passa a ser cobrado por algo que já pagou.\n"
            . "O importador NÃO corrige isso sozinho; confira caso a caso.\n%s\n%s",
            count($r->reativacoesComDinheiroParado),
            $confirmar
                ? '>>> Você passou --confirmar: a reativação SERÁ gravada a seguir. Interrompa se não for isso. <<<'
                : 'Dry-run: nada será gravado.',
            implode("\n", array_slice($r->reativacoesComDinheiroParado, 0, 40))
                . (count($r->reativacoesComDinheiroParado) > 40
                    ? sprintf("\n… (+%d)", count($r->reativacoesComDinheiroParado) - 40)
                    : ''),
        ));
    }

    /**
     * Quanto o saldo devedor se move com a reativação — as originais saem do exigível e as parcelas
     * entram. Aviso SEPARADO do de cima de propósito: este vale mesmo quando ninguém pagou nada, e o
     * texto do outro afirmaria, falsamente, que o devedor está sendo cobrado por algo que já pagou.
     */
    private function avisarImpactoDaReativacao(SymfonyStyle $io, ResultadoImportacaoReceitas $r, bool $confirmar): void
    {
        if ($r->reativacoesImpactoNoSaldo === []) {
            return;
        }

        $io->note(sprintf(
            "D6 · IMPACTO NO SALDO: a reativação move o saldo devedor em %d acordo(s), mesmo sem dinheiro já pago.\n"
            . "Os valores vêm do snapshot gravado: se o rompimento é antigo, a dívida cresceu desde então\n"
            . "e o número abaixo é piso, não fechamento.\n%s\n%s",
            count($r->reativacoesImpactoNoSaldo),
            $confirmar ? '>>> Você passou --confirmar: SERÁ gravado a seguir. <<<' : 'Dry-run: nada será gravado.',
            implode("\n", array_slice($r->reativacoesImpactoNoSaldo, 0, 40))
                . (count($r->reativacoesImpactoNoSaldo) > 40
                    ? sprintf("\n… (+%d)", count($r->reativacoesImpactoNoSaldo) - 40)
                    : ''),
        ));
    }

    /**
     * Obrigação que MUDA de acordo. Medido em 03/08: nenhum NN aparece em dois acordos, então esta
     * lista sai vazia hoje — mas mover uma parcela entre acordos altera a composição de saldo dos
     * dois, e a fonte de amanhã não deve nada à propriedade medida hoje.
     */
    private function avisarTrocaDeAcordo(SymfonyStyle $io, ResultadoImportacaoReceitas $r, bool $confirmar): void
    {
        if ($r->trocasDeAcordo === []) {
            return;
        }

        $io->warning(sprintf(
            "%d obrigação(ões) MUDAM de acordo nesta importação — isso altera a composição de saldo dos dois.\n%s\n%s",
            count($r->trocasDeAcordo),
            $confirmar ? '>>> Você passou --confirmar: a troca SERÁ gravada a seguir. <<<' : 'Dry-run: nada será gravado.',
            implode("\n", array_slice($r->trocasDeAcordo, 0, 40))
                . (count($r->trocasDeAcordo) > 40 ? sprintf("\n… (+%d)", count($r->trocasDeAcordo) - 40) : ''),
        ));
    }

    /**
     * O palpite de alocação em obrigação PREEXISTENTE nunca é silencioso.
     *
     * Quem decide se a alocação leva o bruto é o override `taxa_honorarios_bp = 0`, e a 3ª revisão
     * mostrou que a tela alcança essa coluna nos dois sentidos. Nas obrigações que este importador
     * cria não há palpite nenhum — ele mesmo grava as duas pontas. Nas preexistentes há, e o operador
     * precisa poder conferir o `valorOriginal` de cada uma. Medido em 03/08: zero casos.
     */
    private function avisarPalpiteDeAlocacao(SymfonyStyle $io, ResultadoImportacaoReceitas $r): void
    {
        if ($r->alocacaoBrutaEmPreexistente === []) {
            return;
        }

        $io->note(sprintf(
            "%d recebimento(s) pousam em obrigação que JÁ EXISTIA e cuja alocação levou o valor BRUTO,\n"
            . "porque ela tem o override de honorário zerado (sinal de que o honorário está dentro do\n"
            . "valor original). Confira o valor original dessas obrigações — se o honorário NÃO estiver\n"
            . "embutido, a alocação abate mais do que deveria.\nNN: %s",
            count($r->alocacaoBrutaEmPreexistente),
            implode(', ', array_slice($r->alocacaoBrutaEmPreexistente, 0, 40))
                . (count($r->alocacaoBrutaEmPreexistente) > 40
                    ? sprintf(' … (+%d)', count($r->alocacaoBrutaEmPreexistente) - 40)
                    : ''),
        ));
    }

    private function reais(int $centavos): string
    {
        return 'R$ ' . number_format($centavos / 100, 2, ',', '.');
    }
}
