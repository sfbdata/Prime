<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\DTO\GravarEspelhoRelatorioInput;
use App\Cobranca\Entity\Carteira;
use App\Cobranca\Entity\RelatorioImportado;
use App\Cobranca\Entity\RelatorioLinha;
use App\Cobranca\Entity\RelatorioTotalizador;
use App\Cobranca\Enum\BlocoRelatorio;
use App\Cobranca\Repository\RelatorioImportadoRepository;
use App\Cobranca\Service\Espelho\ArquivoForaDoLayoutException;
use App\Cobranca\Service\Espelho\LeitorEspelhoRelatorio;
use App\Cobranca\Service\Espelho\ReconciliacaoInternaFalhouException;
use App\Cobranca\UseCase\GravarEspelhoRelatorioUseCase;
use App\Tests\Cobranca\Support\MontaPlanilhaDeEspelho;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Factory\Cobranca\ObrigacaoFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * A gravação do espelho contra o BANCO REAL
 * (SPEC docs/specs/cobranca-espelho-da-contabilidade.md §4, testes §9).
 *
 * Prova os quatro invariantes do UseCase: a reconciliação interna é portão (INV-G1), a leitura é
 * idempotente por hash + versão do leitor (INV-G2), **nada de dado de dívida é escrito** (INV-G3) e
 * o tenant vem da carteira (INV-G4).
 */
#[CoversClass(GravarEspelhoRelatorioUseCase::class)]
final class GravarEspelhoRelatorioTest extends KernelTestCase
{
    use Factories;
    use MontaPlanilhaDeEspelho;

    private EntityManagerInterface $em;
    private GravarEspelhoRelatorioUseCase $gravar;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        /** @var RelatorioImportadoRepository $relatorios */
        $relatorios = $this->em->getRepository(RelatorioImportado::class);

        $this->gravar = new GravarEspelhoRelatorioUseCase(
            new LeitorEspelhoRelatorio(),
            $relatorios,
            $this->em,
        );
    }

    protected function tearDown(): void
    {
        $this->limparPlanilhas();
        parent::tearDown();
    }

    #[TestDox('Grava o lote, as linhas e o totalizador, e a contagem por balde fecha')]
    public function testGravaAsTresTabelas(): void
    {
        $carteira = $this->criarCarteira();
        $arquivo = $this->montarPlanilha([
            $this->linhaDeDado(nn: '74608'),
            $this->linhaDeDado(nn: '74609', unidade: '01-02'),
        ]);

        $saida = $this->gravar->executar(new GravarEspelhoRelatorioInput($carteira, $arquivo));

        self::assertFalse($saida->jaExistia);
        self::assertSame(2, $saida->linhasDados);
        self::assertSame(
            $saida->linhasTotal,
            array_sum($saida->linhasPorBloco),
            'a soma dos seis baldes tem que dar o total de linhas do arquivo'
        );

        self::assertCount(
            $saida->linhasTotal,
            $this->em->getRepository(RelatorioLinha::class)->findBy(['relatorio' => $saida->relatorioId]),
            'toda linha do arquivo foi gravada, não só as de dado'
        );
        self::assertNotSame(
            [],
            $this->em->getRepository(RelatorioTotalizador::class)->findBy(['relatorio' => $saida->relatorioId])
        );
    }

    #[TestDox('Guarda a data de corte, a emissão e a config que a contabilidade declarou')]
    public function testGuardaOsMetadadosDaEmissao(): void
    {
        $carteira = $this->criarCarteira();
        $arquivo = $this->montarPlanilha([$this->linhaDeDado()]);

        $saida = $this->gravar->executar(new GravarEspelhoRelatorioInput($carteira, $arquivo));

        // `dadosAte` é a âncora da calibração — hoje o importador a lê e DESCARTA de propósito.
        self::assertSame('12/08/2026', $saida->dadosAte?->format('d/m/Y'));
        self::assertSame('12/08/2026 09:42', $saida->emitidoEm?->format('d/m/Y H:i'));
        self::assertSame(['juros' => 100, 'multa' => 200, 'honorarios' => 2000], $saida->configDeclarada);
    }

    #[TestDox('INV-G2: ler o mesmo arquivo duas vezes não duplica nada')]
    public function testLeituraEhIdempotente(): void
    {
        $carteira = $this->criarCarteira();
        $arquivo = $this->montarPlanilha([$this->linhaDeDado(), $this->linhaDeDado(nn: '74609')]);
        $entrada = new GravarEspelhoRelatorioInput($carteira, $arquivo);

        $primeira = $this->gravar->executar($entrada);
        $linhasApos1 = $this->contarLinhas();

        $segunda = $this->gravar->executar($entrada);

        self::assertFalse($primeira->jaExistia);
        self::assertTrue($segunda->jaExistia, 'a segunda leitura reconhece o hash');
        self::assertSame($primeira->relatorioId, $segunda->relatorioId, 'é o MESMO lote, não um novo');
        self::assertSame($linhasApos1, $this->contarLinhas(), 'nenhuma linha a mais foi gravada');
    }

    #[TestDox('INV-G1: se a soma não bate com o totalizador da planilha, NADA é gravado')]
    public function testReconciliacaoQueNaoFechaAbortaSemGravar(): void
    {
        $carteira = $this->criarCarteira();

        // Prova reintroduzindo o defeito: o totalizador é adulterado, e o portão tem que fechar.
        $arquivo = $this->montarPlanilha(
            linhas: [$this->linhaDeDado()],
            valorTotalAdulterado: 999999.99,
        );

        $lotesAntes = $this->contarLotes();
        $linhasAntes = $this->contarLinhas();

        try {
            $this->gravar->executar(new GravarEspelhoRelatorioInput($carteira, $arquivo));
            self::fail('a reconciliação interna deveria ter barrado a gravação');
        } catch (ReconciliacaoInternaFalhouException $e) {
            self::assertStringContainsString('Nada foi gravado', $e->getMessage());
        }

        self::assertSame($lotesAntes, $this->contarLotes(), 'nenhum lote foi criado');
        self::assertSame($linhasAntes, $this->contarLinhas(), 'nenhuma linha foi criada');
    }

    #[TestDox('Arquivo com coluna fora de posição é recusado e nada é gravado')]
    public function testArquivoForaDoLayoutEhRecusado(): void
    {
        $carteira = $this->criarCarteira();

        $torto = self::CABECALHO_ESPELHO;
        [$torto[8], $torto[9]] = [$torto[9], $torto[8]]; // Juros ↔ Multa

        $arquivo = $this->montarPlanilha(linhas: [$this->linhaDeDado()], cabecalho: $torto);
        $lotesAntes = $this->contarLotes();

        try {
            $this->gravar->executar(new GravarEspelhoRelatorioInput($carteira, $arquivo));
            self::fail('o cabeçalho torto deveria ter sido recusado');
        } catch (ArquivoForaDoLayoutException) {
            // esperado
        }

        self::assertSame($lotesAntes, $this->contarLotes());
    }

    #[TestDox('INV-G3: a Fase 0 não escreve UMA LINHA em dado de dívida')]
    public function testNaoEscreveEmDadoDeDivida(): void
    {
        // A asserção via ORM NÃO serviria: o identity map devolveria o objeto já mutado em memória,
        // com ou sem flush. A foto é tirada por DBAL, fora do ORM, e `atualizado_em` é o detector —
        // a entidade o carimba no PreUpdate. O acidente é real e está no repositório:
        // `CalculadoraSaldo` hidrata (muta entidades managed) e `EncerrarCasoUseCase` flusha depois.
        $carteira = $this->criarCarteira();
        ObrigacaoFactory::createMany(3);

        $antes = $this->fotografarObrigacoes();
        self::assertNotSame([], $antes, 'o teste precisa de obrigações no banco para ter o que provar');

        $arquivo = $this->montarPlanilha([$this->linhaDeDado(), $this->linhaDeDado(nn: '74609')]);
        $this->gravar->executar(new GravarEspelhoRelatorioInput($carteira, $arquivo));

        self::assertSame($antes, $this->fotografarObrigacoes(), 'nenhuma obrigação foi tocada');
    }

    #[TestDox('INV-G4: o tenant do espelho é o da CARTEIRA, não o do usuário')]
    public function testTenantVemDaCarteira(): void
    {
        $tenant = TenantFactory::createOne();
        $carteira = CarteiraFactory::createOne(['tenant' => $tenant])->_real();

        $arquivo = $this->montarPlanilha([$this->linhaDeDado()]);
        $saida = $this->gravar->executar(new GravarEspelhoRelatorioInput($carteira, $arquivo));

        $lote = $this->em->getRepository(RelatorioImportado::class)->find($saida->relatorioId);

        self::assertNotNull($lote);
        self::assertSame($tenant->getId(), $lote->getTenant()?->getId());

        $linhas = $this->em->getRepository(RelatorioLinha::class)->findBy(['relatorio' => $lote], [], 1);
        self::assertSame($tenant->getId(), $linhas[0]->getTenant()?->getId(), 'a linha carrega o tenant, não só o lote');
    }

    #[TestDox('Linha que o importador rejeitaria é gravada com os valores dela')]
    public function testGravaLinhaQueOImportadorRejeitaria(): void
    {
        // Boleto composto só de encargo (sem principal): o importador o rejeita e sua `LinhaRejeitada`
        // guarda apenas [nn, unidade, sacado, competencia] — todo valor financeiro se perde. São os 17
        // boletos de R$ 5.152,19 que originaram esta frente.
        $carteira = $this->criarCarteira();
        $arquivo = $this->montarPlanilha([
            $this->linhaDeDado(
                nn: '67611',
                classe: '1.4 - Juros',
                valor: 445.45,
                juros: 88.35,
                multa: 8.91,
                honorarios: 108.54,
                acordo: 'Acordo 426 - Parc. 1/6',
            ),
        ]);

        $saida = $this->gravar->executar(new GravarEspelhoRelatorioInput($carteira, $arquivo));

        $linha = $this->em->getRepository(RelatorioLinha::class)->findOneBy([
            'relatorio' => $saida->relatorioId,
            'nn' => '67611',
        ]);

        self::assertNotNull($linha);
        self::assertSame(BlocoRelatorio::Dados, $linha->getBloco());
        self::assertSame(44545, $linha->getValor(), 'o valor do boleto rejeitado NÃO se perde');
        self::assertSame(65125, $linha->getTotal(), 'a coluna Total, que o importador nunca lê');
        self::assertSame('Acordo 426 - Parc. 1/6', $linha->getAcordoTexto());
    }

    private function criarCarteira(): Carteira
    {
        return CarteiraFactory::createOne()->_real();
    }

    /**
     * Foto por DBAL das colunas que uma escrita acidental mexeria — fora do ORM de propósito.
     *
     * @return list<array<string, mixed>>
     */
    private function fotografarObrigacoes(): array
    {
        /** @var list<array<string, mixed>> $linhas */
        $linhas = $this->em->getConnection()->fetchAllAssociative(
            'SELECT id, valor_original, juros, multa, correcao, honorarios,
                    encargos_reconhecidos, encargos_atualizados_em, encargos_congelados_em,
                    liquidada_em, atualizado_em
             FROM cobranca_obrigacao ORDER BY id'
        );

        return $linhas;
    }

    private function contarLotes(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cobranca_relatorio_importado');
    }

    private function contarLinhas(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM cobranca_relatorio_linha');
    }
}
