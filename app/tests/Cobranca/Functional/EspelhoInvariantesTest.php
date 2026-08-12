<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\DTO\GravarEspelhoRelatorioInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\CasoCobranca;
use App\Cobranca\Entity\ObjetoCobranca;
use App\Cobranca\Entity\Obrigacao;
use App\Cobranca\Entity\RelatorioImportado;
use App\Cobranca\Entity\RelatorioLinha;
use App\Cobranca\Entity\RelatorioTotalizador;
use App\Cobranca\Repository\ObrigacaoRepository;
use App\Cobranca\Repository\RelatorioImportadoRepository;
use App\Cobranca\Repository\RelatorioLinhaRepository;
use App\Cobranca\Service\CalculadoraEncargos;
use App\Cobranca\Service\Espelho\AgrupadorDeBoletos;
use App\Cobranca\Service\Espelho\CalibracaoDoEspelho;
use App\Cobranca\Service\Espelho\ConferenciaDoEspelho;
use App\Cobranca\Service\Espelho\LeitorEspelhoRelatorio;
use App\Cobranca\Service\Importacao\TopLifeInadimplenciaAdapter;
use App\Cobranca\Service\ResolvedorConfigEncargos;
use App\Cobranca\UseCase\GravarEspelhoRelatorioUseCase;
use App\Tests\Cobranca\Support\MontaPlanilhaDeEspelho;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\CasoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObjetoCobrancaFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Os invariantes da Fase 0 que atravessam mais de uma peça (SPEC espelho §9, itens 5, 8, 9, 10d, 12).
 *
 * Estão juntos aqui porque nenhum pertence a uma classe só: a promessa "não escreve em dado de
 * dívida" vale para as TRÊS peças, o isolamento entre escritórios vale para as TRÊS tabelas, e a
 * colisão de chave envolve leitor, gravação e conferência ao mesmo tempo.
 */
final class EspelhoInvariantesTest extends KernelTestCase
{
    use Factories;
    use MontaPlanilhaDeEspelho;

    private EntityManagerInterface $em;
    private GravarEspelhoRelatorioUseCase $gravar;
    private ConferenciaDoEspelho $conferencia;
    private CalibracaoDoEspelho $calibracao;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        /** @var RelatorioImportadoRepository $relatorios */
        $relatorios = $this->em->getRepository(RelatorioImportado::class);
        /** @var RelatorioLinhaRepository $linhas */
        $linhas = $this->em->getRepository(RelatorioLinha::class);
        /** @var ObrigacaoRepository $obrigacoes */
        $obrigacoes = $this->em->getRepository(Obrigacao::class);

        $agrupador = new AgrupadorDeBoletos($linhas);

        $this->gravar = new GravarEspelhoRelatorioUseCase(new LeitorEspelhoRelatorio(), $relatorios, $this->em);
        $this->conferencia = new ConferenciaDoEspelho(
            $agrupador,
            $obrigacoes,
            $this->em->getRepository(ObjetoCobranca::class),
        );
        $this->calibracao = new CalibracaoDoEspelho(
            $agrupador,
            $obrigacoes,
            new CalculadoraEncargos(),
            new ResolvedorConfigEncargos(),
        );
    }

    protected function tearDown(): void
    {
        $this->limparPlanilhas();
        parent::tearDown();
    }

    #[TestDox('§9.9 — NENHUMA das três peças escreve em dado de dívida')]
    public function testNenhumaDasTresPecasEscreveEmDivida(): void
    {
        // A versão anterior deste teste cobria só a gravação. O caminho de risco é a CALIBRAÇÃO, que
        // carrega obrigações MANAGED e as passa para o resolvedor — se alguém trocar isso por
        // `EncargosVivos::hidratar()`, a entidade é mutada e o próximo flush persiste. É o acidente
        // que já existe no repositório (`CalculadoraSaldo` + `EncerrarCasoUseCase`).
        $carteira = $this->carteira();
        $caso = $this->caso($carteira, '01-01');
        $this->obrigacao($caso, '74608', '02/2026', 19000);
        ObrigacaoFactory::createMany(2);

        $arquivo = $this->montarPlanilha([
            $this->linhaDeDado(unidade: '01-01', nn: '74608', competencia: '02/2026', valor: 190.00),
        ]);

        $antes = $this->fotografarObrigacoes();
        self::assertNotSame([], $antes);

        $saida = $this->gravar->executar(new GravarEspelhoRelatorioInput($carteira, $arquivo));
        $lote = $this->em->getRepository(RelatorioImportado::class)->find($saida->relatorioId);
        self::assertNotNull($lote);

        $this->conferencia->conferir($lote);
        $this->calibracao->calibrar($lote);
        $this->em->flush(); // se alguma peça tivesse mutado uma entidade managed, é aqui que gravaria

        self::assertSame($antes, $this->fotografarObrigacoes(), 'nenhuma obrigação foi tocada');
    }

    #[TestDox('§9.8 — as três tabelas do espelho não vazam entre escritórios')]
    public function testIsolamentoEntreEscritorios(): void
    {
        $daCasa = $this->carteira();
        $deOutro = $this->carteira();

        $this->gravar->executar(new GravarEspelhoRelatorioInput(
            $daCasa,
            $this->montarPlanilha([$this->linhaDeDado(nn: '74608')])
        ));
        $this->gravar->executar(new GravarEspelhoRelatorioInput(
            $deOutro,
            $this->montarPlanilha([$this->linhaDeDado(nn: '99999', unidade: '99-99')])
        ));

        $ids = static fn (array $registros): array => array_map(
            static fn (object $r): int => (int) $r->getId(),
            $registros
        );

        foreach ([RelatorioImportado::class, RelatorioLinha::class, RelatorioTotalizador::class] as $classe) {
            $doOutro = $ids($this->em->getRepository($classe)->findBy(['tenant' => $deOutro->getTenant()]));
            $daCasaAgora = $ids($this->em->getRepository($classe)->findBy(['tenant' => $daCasa->getTenant()]));

            self::assertNotSame([], $doOutro, $classe);
            self::assertNotSame([], $daCasaAgora, $classe);
            self::assertSame(
                [],
                array_intersect($doOutro, $daCasaAgora),
                sprintf('%s: registros de escritórios diferentes se misturaram', $classe)
            );
        }
    }

    #[TestDox('§9.5 — o mesmo arquivo com versão de leitor diferente gera lote NOVO')]
    public function testVersaoDoLeitorDiferenteGeraLoteNovo(): void
    {
        // A regra da §4.4 é "corrigiu o leitor? relê e gera lote novo". Sem a versão na chave única,
        // o banco recusaria — o mesmo arquivo tem sempre o mesmo hash.
        $carteira = $this->carteira();
        $arquivo = $this->montarPlanilha([$this->linhaDeDado()]);

        $primeira = $this->gravar->executar(new GravarEspelhoRelatorioInput($carteira, $arquivo));

        $lote = $this->em->getRepository(RelatorioImportado::class)->find($primeira->relatorioId);
        self::assertNotNull($lote);
        self::assertSame(LeitorEspelhoRelatorio::VERSAO, $lote->getVersaoLeitor());

        /** @var RelatorioImportadoRepository $repo */
        $repo = $this->em->getRepository(RelatorioImportado::class);

        self::assertNotNull(
            $repo->findOnePorHash($carteira, $lote->getArquivoHash(), LeitorEspelhoRelatorio::VERSAO),
            'a versão atual acha o lote — é o que impede a duplicação'
        );
        self::assertNull(
            $repo->findOnePorHash($carteira, $lote->getArquivoHash(), LeitorEspelhoRelatorio::VERSAO + 1),
            'uma versão nova do leitor NÃO acha — logo relê e grava um lote novo'
        );
    }

    #[TestDox('§9.12 — dois boletos sem NN, mesmo vencimento, unidades diferentes NÃO colidem')]
    public function testChaveNaoColideEntreUnidades(): void
    {
        // O caso real medido: as unidades 17-01/1-2 e 20-03C compartilham `SNN:2022-05-10|05/2022`.
        // Com a chave errada (sem a unidade), duas dívidas de apartamentos diferentes virariam uma.
        $carteira = $this->carteira();
        $casoA = $this->caso($carteira, '17-01/1-2');
        $casoB = $this->caso($carteira, '20-03C');

        $this->obrigacao($casoA, 'SNN:2022-05-10', '05/2022', 19000, new \DateTimeImmutable('2022-05-10'));
        $this->obrigacao($casoB, 'SNN:2022-05-10', '05/2022', 19000, new \DateTimeImmutable('2022-05-10'));

        $resultado = $this->conferir($carteira, [
            $this->linhaDeDado(unidade: '17-01/1-2', nn: '-', competencia: '05/2022',
                vencimento: '10/05/2022', valor: 190.00),
            $this->linhaDeDado(unidade: '20-03C', nn: '-', competencia: '05/2022',
                vencimento: '10/05/2022', valor: 190.00),
        ]);

        self::assertSame(2, $resultado->gruposNoRelatorio, 'são DOIS boletos, não um');
        self::assertSame(2, $resultado->confere);
        self::assertSame([], $resultado->sobraNoSistema);
        self::assertTrue($resultado->baldesFecham());
    }

    #[TestDox('§9.10d — unidade com DOIS casos vira balde de ambiguidade, não escolha silenciosa')]
    public function testUnidadeComDoisCasosViraAmbiguidade(): void
    {
        $carteira = $this->carteira();
        $objeto = ObjetoCobrancaFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'carteira' => $carteira,
            'identificacao' => '01-01',
        ])->_real();

        // Nenhum índice impede um segundo caso no mesmo objeto — a guarda existe por isso.
        foreach ([1, 2] as $i) {
            $caso = CasoCobrancaFactory::createOne([
                'tenant' => $carteira->getTenant(),
                'objeto' => $objeto,
            ])->_real();

            $this->obrigacao($caso, '7460' . $i, '02/2026', 19000);
        }

        $resultado = $this->conferir($carteira, [
            $this->linhaDeDado(unidade: '01-01', nn: '74601', competencia: '02/2026', valor: 190.00),
        ]);

        self::assertCount(1, $resultado->ambiguidadeDeCaso);
        self::assertSame(0, $resultado->confere, 'não escolheu um caso por conta própria');
        self::assertTrue($resultado->baldesFecham());
    }

    #[TestDox('O padrão de acordo da conferência reconhece as MESMAS strings que o importador')]
    public function testPadraoDeAcordoNaoDivergeDoImportador(): void
    {
        // A regra está escrita duas vezes de propósito (a conferência não pode reusar o adapter que
        // ela confere). Duplicação sem trava vira divergência silenciosa: bastaria a contabilidade
        // mudar o texto para os dois classificarem diferente, e a conferência acusaria divergência
        // falsa em todas as parcelas de acordo.
        $reais = [
            'Acordo 426 - Parc. 1/6',
            'Acordo 155 - Parc. 10/40',
            'Acordo 39 - Parc. 5/40',
            'Acordo 348 - Parc. 6/40',
        ];

        $doAdapter = new \ReflectionClass(TopLifeInadimplenciaAdapter::class);
        $regexDoAdapter = null;

        foreach ($doAdapter->getConstants() as $nome => $valor) {
            if (is_string($valor) && str_contains($valor, 'Parc')) {
                $regexDoAdapter = $valor;
                break;
            }
        }

        self::assertNotNull($regexDoAdapter, sprintf(
            'não achei a regex de acordo em %s — se ela mudou de nome, este teste precisa acompanhar',
            TopLifeInadimplenciaAdapter::class
        ));

        foreach ($reais as $texto) {
            self::assertSame(
                preg_match($regexDoAdapter, $texto) === 1,
                preg_match(AgrupadorDeBoletos::padraoDeAcordo(), $texto) === 1,
                sprintf('classificação divergente para "%s"', $texto)
            );
        }

        foreach (['-', '', 'Acordo cancelado', 'Parc. 1/6'] as $naoAcordo) {
            self::assertSame(
                0,
                preg_match(AgrupadorDeBoletos::padraoDeAcordo(), $naoAcordo),
                sprintf('"%s" não é parcela de acordo', $naoAcordo)
            );
        }
    }

    /**
     * @param list<list<mixed>> $linhas
     */
    private function conferir(Carteira $carteira, array $linhas): \App\Cobranca\DTO\ResultadoConferencia
    {
        $saida = $this->gravar->executar(
            new GravarEspelhoRelatorioInput($carteira, $this->montarPlanilha($linhas))
        );

        $lote = $this->em->getRepository(RelatorioImportado::class)->find($saida->relatorioId);
        self::assertNotNull($lote);

        return $this->conferencia->conferir($lote);
    }

    /** @return list<array<string, mixed>> */
    private function fotografarObrigacoes(): array
    {
        /** @var list<array<string, mixed>> $linhas */
        $linhas = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, valor_original, juros, multa, correcao, honorarios, encargos_reconhecidos,
                    encargos_atualizados_em, encargos_congelados_em, liquidada_em, atualizado_em
             FROM cobranca_obrigacao ORDER BY id'
        );

        return $linhas;
    }

    private function carteira(): Carteira
    {
        return CarteiraFactory::createOne(['tenant' => TenantFactory::createOne()])->_real();
    }

    private function caso(Carteira $carteira, string $identificacao): CasoCobranca
    {
        $objeto = ObjetoCobrancaFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'carteira' => $carteira,
            'identificacao' => $identificacao,
        ])->_real();

        return CasoCobrancaFactory::createOne([
            'tenant' => $carteira->getTenant(),
            'objeto' => $objeto,
        ])->_real();
    }

    private function obrigacao(
        CasoCobranca $caso,
        string $referencia,
        string $competencia,
        int $valorOriginal,
        ?\DateTimeImmutable $vencimento = null,
    ): Obrigacao {
        return ObrigacaoFactory::createOne([
            'tenant' => $caso->getTenant(),
            'caso' => $caso,
            'referenciaExterna' => $referencia,
            'competencia' => $competencia,
            'valorOriginal' => $valorOriginal,
            'vencimentoOriginal' => $vencimento ?? new \DateTimeImmutable('2026-02-10'),
        ])->_real();
    }
}
