<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Command\ImportarReceitasCommand;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * O comando de importar receitas, ponta a ponta — do `.xlsx` ao que ele imprime.
 *
 * 🔑 **Por que esta classe existe.** O comando não tinha teste NENHUM (achado da 2ª revisão), e o custo
 * apareceu na hora: uma correção da 1ª revisão introduziu um `use` faltando, o erro era FATAL na primeira
 * linha do caminho real, e a suíte seguiu 3162/3162 verde. Só o dry-run manual contra a planilha real
 * pegou. Um comando de dinheiro sem teste é um caminho inteiro sem rede.
 *
 * O que estes testes guardam é o CONTRATO DE TELA do comando: dry-run não grava, e os avisos que existem
 * para segurar a mão de quem confirma aparecem **antes** da escrita. Os números em si são conferidos em
 * `ImportarReceitasFluxoTest` e `TopLifeReceitasAdapterTest`.
 */
#[CoversClass(ImportarReceitasCommand::class)]
final class ImportarReceitasCommandTest extends CobrancaWebTestCase
{
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

    #[TestDox('dry-run: não grava nada e mostra o que aconteceria')]
    public function testDryRunNaoGravaNada(): void
    {
        [$tenantId, $carteiraId, $usuarioId] = $this->cenario();
        $arquivo = $this->planilha([
            ['CHACARA 80', 'Fulano', '7001', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '15/05/2026', '100,00', '100,00', '-'],
        ]);

        $saida = $this->rodar($arquivo, $tenantId, $carteiraId, $usuarioId);

        self::assertStringContainsString('O que aconteceria', $saida);
        self::assertStringContainsString('DRY-RUN: nada foi gravado', $saida);
        self::assertSame(
            0,
            (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM cobranca_pagamento WHERE tenant_id = ?', [$tenantId]),
            'dry-run não pode criar pagamento',
        );
    }

    #[TestDox('🔑 O aviso de "sem principal" sai ANTES da tabela de resultado — e antes de qualquer escrita')]
    public function testAvisoDeSemPrincipalVemAntesDoResultado(): void
    {
        // O aviso morava em `imprimirTotais`, que roda DEPOIS de `confirmar()`. Quem importasse veria
        // os boletos de R$ 0,00 já gravados — um aviso pós-fato não devolve a decisão a ninguém, e a
        // spec §9.1 declara essa decisão ABERTA e do dono.
        [$tenantId, $carteiraId, $usuarioId] = $this->cenario();
        $arquivo = $this->planilha([
            ['CHACARA 81', 'Fulano', '7010', '1.15 - Honorário advocatício', '05/2026', '10/05/2026', '15/05/2026', '50,00', '50,00', '-'],
            ['CHACARA 82', 'Beltrano', '7011', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '15/05/2026', '100,00', '100,00', '-'],
        ]);

        $saida = $this->rodar($arquivo, $tenantId, $carteiraId, $usuarioId);

        self::assertStringContainsString('NÃO têm principal', $saida);
        self::assertStringContainsString('7010', $saida, 'o NN tem de aparecer para quem confere');
        self::assertStringNotContainsString('7011', $saida, 'o recebimento normal não é marcado');

        // A ORDEM é o que este teste guarda: o aviso vem antes do bloco de resultado.
        self::assertLessThan(
            mb_strpos($saida, 'O que aconteceria'),
            mb_strpos($saida, 'NÃO têm principal'),
            'o aviso tem de preceder o resultado — é o que garante que ele preceda a escrita',
        );
    }

    #[TestDox('classe de conta fora do mapa aparece no aviso de conferência')]
    public function testClasseForaDoMapaEhAvisada(): void
    {
        [$tenantId, $carteiraId, $usuarioId] = $this->cenario();
        $arquivo = $this->planilha([
            ['CHACARA 83', 'Fulano', '7020', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '15/05/2026', '100,00', '100,00', '-'],
            ['CHACARA 83', 'Fulano', '7020', '1.98 - Classe nova da contábil', '05/2026', '10/05/2026', '15/05/2026', '9,00', '9,00', '-'],
        ]);

        $saida = $this->rodar($arquivo, $tenantId, $carteiraId, $usuarioId);

        self::assertStringContainsString('fora do mapa conhecido', $saida);
        self::assertStringContainsString('1.98', $saida);
    }

    #[TestDox('linha em aberto é reportada como descartada, com a causa provável')]
    public function testLinhaEmAbertoEhReportada(): void
    {
        [$tenantId, $carteiraId, $usuarioId] = $this->cenario();
        $arquivo = $this->planilha([
            ['CHACARA 84', 'Fulano', '7030', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '15/05/2026', '100,00', '100,00', '-'],
            ['CHACARA 85', 'Beltrano', '7031', '1.1 - Taxa de condomínio', '06/2026', '10/06/2026', '-', '250,00', '250,00', '-'],
        ]);

        $saida = $this->rodar($arquivo, $tenantId, $carteiraId, $usuarioId);

        self::assertStringContainsString('foram DESCARTADAS', $saida);
        self::assertStringContainsString('situação "Aberta"', $saida, 'a mensagem tem de apontar a causa provável');
    }

    #[TestDox('🔑 Etapa 3: o comando imprime parcelas de acordo, acordos criados, cumpridos e incompletos')]
    public function testResumoDaEtapa3(): void
    {
        [$tenantId, $carteiraId, $usuarioId] = $this->cenario();

        // Cada contador tem CENÁRIO que o exercita. Contador sem cenário compara zero com zero e
        // sobrevive a qualquer defeito — foi um dos três asserts vacuosos da etapa 2.
        $arquivo = $this->planilha([
            // Acordo 400: as 2 de 2 parcelas pagas → CUMPRIDO.
            ['CH 86', 'Fulano', '7040', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '15/05/2026', '100,00', '100,00', 'Acordo 400 - Parc. 1/2'],
            ['CH 86', 'Fulano', '7041', '1.1 - Taxa de condomínio', '06/2026', '10/06/2026', '15/06/2026', '100,00', '100,00', 'Acordo 400 - Parc. 2/2'],
            // Acordo 212: 1 de 20 → INCOMPLETO, com 19 faltando.
            ['CH 87', 'Beltrano', '7042', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '15/05/2026', '100,00', '100,00', 'Acordo 212 - Parc. 1/20'],
            // Avulso: não é parcela e não cria acordo — senão "todos são parcela" passaria igual.
            ['CH 88', 'Cicrano', '7043', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '15/05/2026', '100,00', '100,00', '-'],
        ]);

        $saida = $this->rodar($arquivo, $tenantId, $carteiraId, $usuarioId);

        // A tabela do SymfonyStyle alinha com espaços, sem pipes — o valor é o que vem depois do rótulo
        // e antes da quebra de linha.
        self::assertMatchesRegularExpression('/PARCELAS de acordo \(coluna J\)\s+3\s/u', $saida, '3 das 4 linhas são parcela');
        self::assertMatchesRegularExpression('/Acordos criados\s+2\s/u', $saida, 'o 400 e o 212');
        self::assertMatchesRegularExpression('/CUMPRIDOS \(todas as parcelas pagas\)\s+1\s/u', $saida, 'só o 400 fechou');
        self::assertMatchesRegularExpression('/INCOMPLETOS \(faltam parcelas\)\s+1\s/u', $saida, 'só o 212 ficou faltando parcela');

        self::assertStringContainsString('Acordo 212: 1 de 20 parcelas', $saida, 'o dono precisa saber QUAL pedir à contábil');
        self::assertStringContainsString('19 parcela(s) futura(s)', $saida);
    }

    #[TestDox('🔑 O resumo dos acordos incompletos sai ANTES do bloco de resultado — logo, antes da escrita')]
    public function testAvisoDeAcordoIncompletoVemAntesDoResultado(): void
    {
        // Mesma armadilha do aviso de "sem principal": este resumo depende do BANCO, então não dá para
        // derivá-lo da leitura como aquele. A garantia é outra — o comando projeta primeiro e imprime
        // os avisos da projeção antes de chamar `confirmar()`.
        [$tenantId, $carteiraId, $usuarioId] = $this->cenario();
        $arquivo = $this->planilha([
            ['CH 89', 'Fulano', '7050', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '15/05/2026', '100,00', '100,00', 'Acordo 230 - Parc. 1/28'],
        ]);

        $saida = $this->rodar($arquivo, $tenantId, $carteiraId, $usuarioId);

        self::assertLessThan(
            mb_strpos($saida, 'O que aconteceria'),
            mb_strpos($saida, 'ficam INCOMPLETOS'),
            'o aviso tem de preceder o resultado — é o que garante que ele preceda a escrita',
        );
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{0: int, 1: int, 2: int} tenantId, carteiraId, usuarioId */
    private function cenario(): array
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);

        return [(int) $tenant->getId(), (int) $carteira->getId(), (int) $user->getId()];
    }

    private function rodar(string $arquivo, int $tenantId, int $carteiraId, int $usuarioId): string
    {
        $tester = new CommandTester(
            (new Application(static::$kernel))->find('app:cobranca:importar-receitas'),
        );
        // Sem `--confirmar`: NENHUM teste desta classe grava. O caminho de escrita é exercitado em
        // `ImportarReceitasFluxoTest`, contra o UseCase, onde o rollback do DAMA é o mesmo.
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
     * Planilha no layout medido da fonte: cabeçalho na linha 7, dados a partir da 8.
     *
     * @param list<list<string>> $linhas
     */
    private function planilha(array $linhas): string
    {
        $planilha = new Spreadsheet();
        $aba = $planilha->getActiveSheet();
        $aba->fromArray(
            ['Unidade', 'Sacado', 'NN', 'Classe de Conta', 'Competência', 'Vencimento', 'Recebimento', 'Valor (R$)', 'Valor recebido (R$)', 'Informações do acordo'],
            null,
            'A7',
        );
        $aba->fromArray($linhas, null, 'A8');

        $caminho = tempnam(sys_get_temp_dir(), 'receitas') . '.xlsx';
        (new Xlsx($planilha))->save($caminho);
        $this->temporarios[] = $caminho;

        return $caminho;
    }

    private function conexao(): \Doctrine\DBAL\Connection
    {
        return static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->getConnection();
    }
}
