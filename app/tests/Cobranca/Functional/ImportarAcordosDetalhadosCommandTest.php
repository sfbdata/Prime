<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Command\ImportarAcordosDetalhadosCommand;
use App\Cobranca\Entity\Acordo;
use App\Cobranca\Enum\StatusAcordo;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * O comando de importar acordos detalhados — o que ele IMPRIME.
 *
 * 🔑 **Por que esta classe existe.** O comando não tinha teste nenhum, e a spec §10 exigia um (achado da
 * 1ª revisão desta frente). A mesma lacuna já cobrou o preço no importador de Receitas, na etapa 2: um
 * `use` faltando era erro FATAL na primeira linha do caminho real e a suíte seguiu verde — só o dry-run
 * manual pegou. A superfície do comando é a ÚNICA que o dono olha antes de mandar gravar.
 *
 * O que estes testes guardam é o contrato de TELA, não os números: que o dry-run não grava, que a
 * sobrescrita de status aparece, que a recusa por guarda de dinheiro aparece **separada** das situações
 * não reconhecidas, e que a prévia não fala como fato consumado. Os números vivem em
 * `ImportarAcordosDetalhadosTest`.
 */
#[CoversClass(ImportarAcordosDetalhadosCommand::class)]
final class ImportarAcordosDetalhadosCommandTest extends CobrancaWebTestCase
{
    /** Rodapé real de uma emissão de acordos — recorte aceito pelo validador. */
    private const RODAPE_ACORDOS = 'Filtros: Situação do acordo: Em andamento; Período de criação do acordo: Todos; Unidade/Cliente: Todos; Sacado: Todos';

    /** @var list<string> */
    private array $temporarios = [];

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $caminho) {
            if (is_file($caminho)) {
                unlink($caminho);
            }
        }
        $this->temporarios = [];
        parent::tearDown();
    }

    #[TestDox('dry-run: não grava o status e diz que NÃO gravou')]
    public function testDryRunNaoGravaOStatus(): void
    {
        [$tenantId, $carteiraId, $usuarioId, $acordoId] = $this->cenarioComAcordoAtivo();
        $arquivo = $this->planilhaDoAcordo(numero: 7001, situacao: 'Liquidado');

        $saida = $this->rodar($arquivo, $tenantId, $carteiraId, $usuarioId);

        self::assertStringContainsString('SOBRESCREVERIA', $saida, 'no dry-run o título não pode afirmar escrita consumada');
        self::assertStringNotContainsString('sobrescreveu o sistema', $saida);

        $this->em()->clear();
        $acordo = $this->em()->getRepository(Acordo::class)->find($acordoId);
        self::assertNotNull($acordo);
        self::assertSame(StatusAcordo::Ativo, $acordo->getStatus(), 'dry-run não pode escrever status');
    }

    /**
     * 🟡A da 2ª revisão — o dry-run afirmava escrita não feita.
     *
     * O bloco da data preenchida era o ÚNICO do relatório que não alternava prévia × confirmação: saía
     * em pretérito ("os encargos foram calculados") mesmo sem `--confirmar`. É a mesma família de defeito
     * que esta frente inteira combate — o sistema afirmando o que não aconteceu —, e o projeto já pagou
     * caro por relatório de prévia que mente.
     *
     * 🔴 PROVA POR REINTRODUÇÃO: tirar o `$confirmar` de `relatarDatasPreenchidas` derruba este teste.
     */
    #[TestDox('🟡A: o dry-run NAO afirma que os encargos foram calculados')]
    public function testDryRunNaoAfirmaQueOsEncargosForamCalculados(): void
    {
        [$tenantId, $carteiraId, $usuarioId] = $this->cenarioComAcordoAtivo(semData: true);
        $arquivo = $this->planilhaDoAcordo(numero: 7001, situacao: 'Em andamento');

        $saida = $this->rodar($arquivo, $tenantId, $carteiraId, $usuarioId);

        // Pré-condição: o bloco tem de APARECER, senão o teste passaria a troco de nada — foi o que
        // aconteceu na primeira versão dele, que usava o cenário com data e nunca imprimia o bloco.
        self::assertStringContainsString('SERIA preenchida', $saida, 'o bloco da data preenchida não apareceu');
        self::assertStringNotContainsString(
            'FORAM calculados',
            $saida,
            'o dry-run afirmou escrita consumada — nada foi gravado',
        );
    }

    #[TestDox('🔑 A recusa por guarda de dinheiro sai em bloco PRÓPRIO, não sob "situação não reconhecida"')]
    public function testRecusaPorGuardaSaiSeparadaDasNaoReconhecidas(): void
    {
        [$tenantId, $carteiraId, $usuarioId] = $this->cenarioComAcordoAtivo(comParcelaPaga: true);
        $arquivo = $this->planilhaDoAcordo(numero: 7001, situacao: 'Cancelado');

        $saida = $this->rodar($arquivo, $tenantId, $carteiraId, $usuarioId);

        // `Cancelado` FOI reconhecida — foi barrada por outro motivo. Sob o título "não reconhecida" o
        // operador leria "o sistema não entendeu" onde o certo é "o sistema entendeu e se recusou".
        self::assertStringContainsString('Sobrescrita RECUSADA por guarda de dinheiro', $saida);
        self::assertStringContainsString('PARCELA PAGA', $saida);
        self::assertStringContainsString('exclua o recebimento', $saida, 'o operador precisa do QUE FAZER');
        self::assertStringNotContainsString('o importe não adivinha', $saida, 'esta situação foi reconhecida');
    }

    #[TestDox('situação fora do mapa continua saindo como "não reconhecida"')]
    public function testSituacaoDesconhecidaContinuaNoBlocoDela(): void
    {
        [$tenantId, $carteiraId, $usuarioId] = $this->cenarioComAcordoAtivo();
        $arquivo = $this->planilhaDoAcordo(numero: 7001, situacao: 'Em negociação judicial');

        $saida = $this->rodar($arquivo, $tenantId, $carteiraId, $usuarioId);

        self::assertStringContainsString('o importe não adivinha', $saida);
        self::assertStringNotContainsString('Sobrescrita RECUSADA', $saida, 'aqui não houve recusa: houve desconhecimento');
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{0: int, 1: int, 2: int, 3: int} tenantId, carteiraId, usuarioId, acordoId */
    private function cenarioComAcordoAtivo(bool $comParcelaPaga = false, bool $semData = false): array
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [$carteira, $caso] = $this->semearGrafo($tenant);

        $acordo = new Acordo();
        $acordo->setTenant($tenant);
        $acordo->setCaso($caso);
        // `$semData`: o acordo que nasceu pela inadimplência/receitas, sem data — o cenário em que o
        // relatório de acordos PREENCHE a data (6ª violação) e o bloco do relatório aparece.
        $acordo->setDataAcordo($semData ? null : new \DateTimeImmutable('2026-05-01'));
        $acordo->setStatus(StatusAcordo::Ativo);
        $acordo->setNumeroExterno(7001);
        $acordo->setNumeroParcelasTotal(1);
        $this->em()->persist($acordo);

        $parcela = \App\Tests\Factory\Cobranca\ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Parcela 1/1', 'valorOriginal' => 20000, 'encargosReconhecidos' => 0,
            'referenciaExterna' => '9101', 'competencia' => '06/2026',
            'vencimentoOriginal' => new \DateTimeImmutable('2026-06-10'),
        ])->_real();
        $parcela->setAcordoOrigem($acordo);
        $this->em()->flush();

        if ($comParcelaPaga) {
            $pagamento = new \App\Cobranca\Entity\Pagamento();
            $pagamento->setTenant($tenant);
            $pagamento->setCaso($caso);
            $pagamento->setData(new \DateTimeImmutable('2026-06-15'));
            $pagamento->setValorDivida(20000);
            $pagamento->setValorEncargos(0);
            $pagamento->setValorHonorarios(0);
            $pagamento->setCriadoPor($user);

            $alocacao = new \App\Cobranca\Entity\AlocacaoPagamento();
            $alocacao->setTenant($tenant);
            $alocacao->setObrigacao($parcela);
            $alocacao->setValor(20000);
            $pagamento->adicionarAlocacao($alocacao);

            $this->em()->persist($pagamento);
            $this->em()->flush();
        }

        return [(int) $tenant->getId(), (int) $carteira->getId(), (int) $user->getId(), (int) $acordo->getId()];
    }

    private function rodar(string $arquivo, int $tenantId, int $carteiraId, int $usuarioId): string
    {
        $tester = new CommandTester(
            (new Application(static::$kernel))->find('app:cobranca:importar-acordos'),
        );
        // Sem `--confirmar` em teste NENHUM desta classe: o caminho de escrita é exercitado contra o
        // UseCase em `ImportarAcordosDetalhadosTest`, onde o rollback do DAMA é o mesmo.
        $tester->execute([
            '--tenant-id' => (string) $tenantId,
            '--carteira-id' => (string) $carteiraId,
            '--usuario-id' => (string) $usuarioId,
            '--arquivo' => $arquivo,
        ]);

        $tester->assertCommandIsSuccessful();

        return $tester->getDisplay();
    }

    /**
     * Uma aba no layout medido da fonte: cabeçalho institucional, `Acordo de número N`, os rótulos, e as
     * duas seções. O mínimo que o adapter aceita.
     */
    private function planilhaDoAcordo(int $numero, string $situacao): string
    {
        $planilha = new Spreadsheet();
        $aba = $planilha->getActiveSheet();
        $aba->setTitle('Acordo n' . $numero);
        $aba->fromArray([
            ['Relatório de acordos', ''],
            ['Acordo de número ' . $numero, ''],
            ['Unidade: CH 01', '01/05/2026'],
            ['Sacado: FULANO DE TAL', 'R$ 200,00'],
            ['Criado em: 01/05/2026', 'R$ 200,00'],
            ['Situação: ' . $situacao, ''],
            ['Relação das contas originais', ''],
            ['NN', 'Classe', 'Competência', 'Vencimento', 'Valor'],
            ['9001', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '200,00'],
            ['Parcelas das contas geradas pelo acordo', ''],
            ['NN', 'Classe', 'Parcela', 'Competência', 'Vencimento', 'Liquidação', 'Valor'],
            ['9101', '1.1 - Taxa de condomínio', '1/1', '06/2026', '10/06/2026', '-', '200,00'],
            // Rodapé `Filtros:` COPIADO de arquivo real. Sem ele o comando recusa antes de ler (spec
            // `docs/specs/cobranca-validador-rodape-filtros.md`), e a fixture sem rodapé é que era
            // irreal — toda planilha da contábil traz esta linha.
            //
            // ⚠️ O rodapé é o filtro da EMISSÃO; `Situação:` (L6) é a situação DAQUELE acordo. São
            // coisas diferentes, e por isso o rodapé aqui fica fixo em "Em andamento" mesmo quando a
            // aba diz "Cancelado": é o único jeito de continuar exercitando o ramo de cancelamento
            // pelo comando, agora que o arquivo `*_CANCELADO.xlsx` é barrado por decisão do dono. No
            // dado real os dois coincidem — a emissão é uma por situação.
            [self::RODAPE_ACORDOS, ''],
            ['Emissão: 03/08/2026 09:49', ''],
        ], null, 'A1');

        $caminho = tempnam(sys_get_temp_dir(), 'acordos') . '.xlsx';
        (new Xlsx($planilha))->save($caminho);
        $this->temporarios[] = $caminho;

        return $caminho;
    }

    private function em(): \Doctrine\ORM\EntityManagerInterface
    {
        return static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
    }
}
