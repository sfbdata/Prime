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

        self::assertStringContainsString('Acordo 212: 1 de 20 pagas', $saida, 'o dono precisa saber QUAL pedir à contábil');
        self::assertStringContainsString('(parcelas 1 a 1)', $saida, 'e QUAIS faltam — aqui, as futuras');
        // Fragmento curto: o SymfonyStyle quebra a linha no meio da frase.
        self::assertStringContainsString('19 parcela(s) que este arquivo', $saida);
    }

    #[TestDox('O resumo dos acordos incompletos sai antes do bloco de resultado (ordem de IMPRESSÃO)')]
    public function testAvisoDeAcordoIncompletoVemAntesDoResultado(): void
    {
        // ⚠️ Este teste prova a ordem de IMPRESSÃO e só isso — ele roda em dry-run, onde não há escrita
        // para preceder. Foi apontado na 1ª revisão como assert que não pode falhar quando vendido como
        // "precede a escrita": mover os avisos para depois de `confirmar()` mantém esta ordem intacta.
        // Quem prova a precedência de verdade é `testAvisoDeD6SaiAntesDaGravacao`, logo abaixo.
        [$tenantId, $carteiraId, $usuarioId] = $this->cenario();
        $arquivo = $this->planilha([
            ['CH 89', 'Fulano', '7050', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '15/05/2026', '100,00', '100,00', 'Acordo 230 - Parc. 1/28'],
        ]);

        $saida = $this->rodar($arquivo, $tenantId, $carteiraId, $usuarioId);

        self::assertLessThan(
            mb_strpos($saida, 'O que aconteceria'),
            mb_strpos($saida, 'ficam INCOMPLETOS'),
            'o aviso é impresso antes do bloco de totais',
        );
    }

    #[TestDox('🔑 COM --confirmar: o aviso de D6 sai ANTES da gravação — e some se for calculado depois')]
    public function testAvisoDeD6SaiAntesDaGravacao(): void
    {
        // 🔑 Este é o teste que DISCRIMINA — e a 1ª versão dele NÃO discriminava.
        //
        // A 2ª revisão mostrou por quê: `avisarDinheiroParado()` recebe um objeto JÁ MATERIALIZADO e
        // não consulta nada. Mover a chamada de impressão para depois de `confirmar()` — que é
        // literalmente o defeito histórico da etapa 2 — não mudava um byte da saída, porque
        // `imprimirTotais` continua sendo o último de qualquer jeito. Verde com o defeito.
        //
        // O que faltava era uma FRONTEIRA observável. O comando agora imprime uma linha de seção
        // imediatamente antes de abrir a transação; tudo acima dela saiu antes da escrita. O assert de
        // ordem compara contra ESSA linha, e não contra o bloco de totais.
        [$tenantId, $carteiraId, $usuarioId, $caso, $tenant] = $this->cenarioComCaso();
        $acordo = $this->acordoRompido($caso, $tenant, 600);
        $this->originalComPagamento($caso, $tenant, $acordo, $usuarioId, '6000', 15000);
        $identificacao = $caso->getObjeto()->getIdentificacao();

        $arquivo = $this->planilha([
            [$identificacao, 'Fulano', '7060', '1.1 - Taxa de condomínio', '05/2026', '10/05/2026', '15/05/2026', '100,00', '100,00', 'Acordo 600 - Parc. 1/2'],
        ]);

        $saida = $this->rodar($arquivo, $tenantId, $carteiraId, $usuarioId, confirmar: true);

        // Fragmentos CURTOS: o SymfonyStyle quebra linha dentro do bloco de warning, então um trecho
        // longo não casa mesmo estando na tela.
        // ⚠️ Prefixos DISTINTOS por canal. Quando os dois avisos começavam igual, `mb_strpos` casava a
        // PRIMEIRA ocorrência e o assert de ordem abaixo não enxergava o segundo canal: mover só o
        // aviso de impacto para depois da gravação deixava o teste verde. Achado da 3ª revisão, e é a
        // mesma classe de defeito que a 2ª rodada existiu para corrigir, reintroduzida no canal novo.
        self::assertStringContainsString('D6 · DINHEIRO JÁ PAGO QUE PARA DE ABATER', $saida);
        self::assertStringContainsString('D6 · IMPACTO NO SALDO', $saida);
        self::assertStringContainsString('NN 6000', $saida, 'o NN do dinheiro que para de abater');
        self::assertStringContainsString('150,00', $saida, 'e quanto é');
        self::assertStringContainsString('350,00', $saida, 'e quanto o saldo se move de fato (500 − 150)');
        self::assertStringContainsString('SERÁ gravada a seguir', $saida, 'o aviso fala no futuro porque roda antes');

        // E a gravação de fato aconteceu — senão o teste provaria a ordem num caminho que não escreve.
        self::assertGreaterThan(
            0,
            (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM cobranca_pagamento WHERE tenant_id = ?', [$tenantId]),
            'pré-condição: esta execução GRAVOU — é o que dá sentido a "antes da gravação"',
        );
        self::assertSame(
            'ativo',
            (string) $this->conexao()->fetchOne('SELECT status FROM cobranca_acordo WHERE numero_externo = 600 AND tenant_id = ?', [$tenantId]),
            'e o acordo foi mesmo reativado',
        );

        // Os DOIS canais têm de preceder a fronteira, cada um verificado pelo seu próprio prefixo.
        $fronteira = mb_strpos($saida, 'GRAVANDO (--confirmar)');
        self::assertLessThan(
            $fronteira,
            mb_strpos($saida, 'D6 · DINHEIRO JÁ PAGO QUE PARA DE ABATER'),
            'o aviso de dinheiro já pago tem de sair ANTES da fronteira de gravação',
        );
        self::assertLessThan(
            $fronteira,
            mb_strpos($saida, 'D6 · IMPACTO NO SALDO'),
            'e o aviso de impacto no saldo também — cada canal com o seu próprio assert',
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

    private function rodar(string $arquivo, int $tenantId, int $carteiraId, int $usuarioId, bool $confirmar = false): string
    {
        $tester = new CommandTester(
            (new Application(static::$kernel))->find('app:cobranca:importar-receitas'),
        );
        // O padrão continua sendo dry-run. `--confirmar` só é usado por
        // `testAvisoDeD6SaiAntesDaGravacao`, que precisa da escrita real para que "antes da gravação"
        // signifique alguma coisa — no dry-run não há gravação para preceder. O rollback é o do DAMA,
        // o mesmo do resto da suíte.
        $opcoes = [
            '--tenant-id' => (string) $tenantId,
            '--carteira-id' => (string) $carteiraId,
            '--usuario-id' => (string) $usuarioId,
            '--arquivo' => $arquivo,
        ];
        if ($confirmar) {
            $opcoes['--confirmar'] = true;
        }
        $tester->execute($opcoes);

        $tester->assertCommandIsSuccessful();

        return $tester->getDisplay();
    }

    /** @return array{0: int, 1: int, 2: int, 3: \App\Cobranca\Entity\CasoCobranca, 4: \App\Entity\Tenant\Tenant} */
    private function cenarioComCaso(): array
    {
        $client = static::createClient();
        [$user, $tenant] = $this->criarAdminLogado($client);
        [$carteira, $caso] = $this->semearGrafo($tenant);

        return [(int) $tenant->getId(), (int) $carteira->getId(), (int) $user->getId(), $caso, $tenant];
    }

    private function acordoRompido(\App\Cobranca\Entity\CasoCobranca $caso, \App\Entity\Tenant\Tenant $tenant, int $numeroExterno): \App\Cobranca\Entity\Acordo
    {
        $em = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);

        $acordo = new \App\Cobranca\Entity\Acordo();
        $acordo->setTenant($tenant);
        $acordo->setCaso($caso);
        $acordo->setStatus(\App\Cobranca\Enum\StatusAcordo::Rompido);
        $acordo->setDataAcordo(new \DateTimeImmutable('2026-01-10'));
        $acordo->setNumeroExterno($numeroExterno);
        $acordo->setNumeroParcelasTotal(2);
        $acordo->setMotivoRompimento('inadimplência');
        $em->persist($acordo);
        $em->flush();

        return $acordo;
    }

    /** Dívida ORIGINAL substituída pelo acordo, com pagamento lançado enquanto ele estava rompido. */
    private function originalComPagamento(
        \App\Cobranca\Entity\CasoCobranca $caso,
        \App\Entity\Tenant\Tenant $tenant,
        \App\Cobranca\Entity\Acordo $acordo,
        int $usuarioId,
        string $nn,
        int $valor,
    ): void {
        $em = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $user = $em->find(\App\Entity\Auth\User::class, $usuarioId);

        $original = \App\Tests\Factory\Cobranca\ObrigacaoFactory::createOne([
            'tenant' => $tenant, 'caso' => $caso,
            'descricao' => 'Taxa 01/2025', 'valorOriginal' => 50000, 'encargosReconhecidos' => 0,
            'referenciaExterna' => $nn,
        ])->_real();
        $original->setAcordoSubstituto($acordo);

        $pagamento = new \App\Cobranca\Entity\Pagamento();
        $pagamento->setTenant($tenant);
        $pagamento->setCaso($caso);
        $pagamento->setData(new \DateTimeImmutable('2026-02-01'));
        $pagamento->setValorDivida($valor);
        $pagamento->setValorEncargos(0);
        $pagamento->setValorHonorarios(0);
        $pagamento->setCriadoPor($user);

        $alocacao = new \App\Cobranca\Entity\AlocacaoPagamento();
        $alocacao->setTenant($tenant);
        $alocacao->setObrigacao($original);
        $alocacao->setValor($valor);
        $pagamento->adicionarAlocacao($alocacao);

        $em->persist($pagamento);
        $em->flush();
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
