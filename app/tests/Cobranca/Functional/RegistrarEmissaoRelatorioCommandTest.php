<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Command\RegistrarEmissaoRelatorioCommand;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * O comando que grava a emissão de um relatório JÁ importado, sem importar nada.
 *
 * Tem teste desde o primeiro dia por lição registrada nesta base: `ImportarReceitasCommand` nasceu sem
 * teste, um `use` faltando o quebrava FATAL na primeira linha do caminho real, e a suíte seguiu verde.
 * Comando sem teste é caminho inteiro sem rede — e este grava em banco.
 *
 * O que se guarda aqui: a data vem do RODAPÉ do arquivo (não do relógio), o tipo inválido é recusado
 * antes de qualquer escrita, e — o mais importante — arquivo sem a linha `Emissão:` **falha visível**,
 * em vez de o comando anunciar sucesso sem ter gravado.
 */
#[CoversClass(RegistrarEmissaoRelatorioCommand::class)]
final class RegistrarEmissaoRelatorioCommandTest extends CobrancaWebTestCase
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

    #[TestDox('grava na carteira a emissão lida do rodapé do arquivo')]
    public function testGravaAEmissaoLidaDoArquivo(): void
    {
        [$tenantId, $carteiraId] = $this->cenario();
        $arquivo = $this->planilha('Emissão: 03/08/2026 09:48');

        $tester = $this->rodar($arquivo, $tenantId, $carteiraId, 'inadimplencia');

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('03/08/2026 09:48', $tester->getDisplay());

        $json = $this->conexao()->fetchOne(
            'SELECT emissao_por_tipo_de_relatorio FROM cobranca_carteira WHERE id = ?',
            [$carteiraId],
        );
        self::assertIsString($json);
        $mapa = json_decode($json, true);
        self::assertArrayHasKey('inadimplencia', $mapa);
        self::assertStringStartsWith('2026-08-03', $mapa['inadimplencia']);
    }

    #[TestDox('a data é a do RODAPÉ, não a de hoje')]
    public function testADataEhADoRodapeNaoADeHoje(): void
    {
        // O ponto inteiro da funcionalidade: importar hoje um relatório de três dias atrás deixa os
        // dados de três dias atrás. Se o comando carimbasse `now`, a tela mentiria dizendo "hoje".
        [$tenantId, $carteiraId] = $this->cenario();
        $arquivo = $this->planilha('Emissão: 29/07/2026 16:08');

        $this->rodar($arquivo, $tenantId, $carteiraId, 'cadastro')->assertCommandIsSuccessful();

        $mapa = json_decode(
            (string) $this->conexao()->fetchOne('SELECT emissao_por_tipo_de_relatorio FROM cobranca_carteira WHERE id = ?', [$carteiraId]),
            true,
        );
        self::assertStringStartsWith('2026-07-29', $mapa['cadastro']);
        self::assertStringNotContainsString((new \DateTimeImmutable())->format('Y-m-d'), $mapa['cadastro']);
    }

    #[TestDox('🔑 arquivo sem a linha "Emissão:" FALHA — não pode anunciar sucesso sem gravar')]
    public function testArquivoSemEmissaoFalhaEmVezDeMentir(): void
    {
        // `RegistrarEmissaoNaCarteira::registrar` engole a própria exceção de propósito (numa
        // importação, uma data ilegível não pode derrubar 8 mil recebimentos). Aqui a data É o
        // trabalho: se o null virasse SUCCESS, o operador rodaria os 12 arquivos, veria 12 sucessos e
        // a tela continuaria sem data nenhuma — sem ninguém saber por quê.
        [$tenantId, $carteiraId] = $this->cenario();
        $arquivo = $this->planilha('Filtros: Situação das contas: Todas;');

        $tester = $this->rodar($arquivo, $tenantId, $carteiraId, 'receitas');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        // Trecho curto de propósito: o SymfonyStyle quebra a mensagem em várias linhas na caixa de
        // erro, e afirmar a frase inteira testaria a largura do terminal, não o comportamento.
        self::assertStringContainsString('Não foi possível ler a emissão', $tester->getDisplay());
        self::assertNull(
            $this->conexao()->fetchOne('SELECT emissao_por_tipo_de_relatorio FROM cobranca_carteira WHERE id = ?', [$carteiraId]) ?: null,
        );
    }

    #[TestDox('tipo fora da lista é recusado antes de tocar no arquivo')]
    public function testTipoInvalidoEhRecusado(): void
    {
        [$tenantId, $carteiraId] = $this->cenario();
        $arquivo = $this->planilha('Emissão: 03/08/2026 09:48');

        $tester = $this->rodar($arquivo, $tenantId, $carteiraId, 'planilha-qualquer');

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('Tipo inválido', $tester->getDisplay());
    }

    #[TestDox('carteira de OUTRO tenant não é tocada')]
    public function testNaoAtravessaTenant(): void
    {
        [, $carteiraId] = $this->cenario();
        $outroTenant = $this->tenantAvulso();
        $arquivo = $this->planilha('Emissão: 03/08/2026 09:48');

        $tester = $this->rodar($arquivo, (int) $outroTenant->getId(), $carteiraId, 'acordos');

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertNull(
            $this->conexao()->fetchOne('SELECT emissao_por_tipo_de_relatorio FROM cobranca_carteira WHERE id = ?', [$carteiraId]) ?: null,
        );
    }

    /** @return array{int, int} tenantId, carteiraId */
    private function cenario(): array
    {
        $tenant = $this->tenantAvulso();
        $cliente = ClientePFFactory::createOne(['tenant' => $tenant]);
        $carteira = CarteiraFactory::createOne(['tenant' => $tenant, 'cliente' => $cliente])->_real();

        return [(int) $tenant->getId(), (int) $carteira->getId()];
    }

    private function rodar(string $arquivo, int $tenantId, int $carteiraId, string $tipo): CommandTester
    {
        $tester = new CommandTester(
            (new Application(static::$kernel))->find('app:cobranca:registrar-emissao'),
        );
        $tester->execute([
            '--tenant-id' => (string) $tenantId,
            '--carteira-id' => (string) $carteiraId,
            '--tipo' => $tipo,
            '--arquivo' => $arquivo,
        ]);

        return $tester;
    }

    /** Planilha mínima com o rodapé informado na coluna A — é só o rodapé que este comando lê. */
    private function planilha(string $rodape): string
    {
        $planilha = new Spreadsheet();
        $aba = $planilha->getActiveSheet();
        $aba->fromArray([['Unidade', 'Sacado', 'NN']], null, 'A7');
        $aba->fromArray([['CHACARA 80', 'Fulano', '7001']], null, 'A8');
        $aba->setCellValue('A10', $rodape);

        $caminho = tempnam(sys_get_temp_dir(), 'emissao').'.xlsx';
        (new Xlsx($planilha))->save($caminho);
        $this->temporarios[] = $caminho;

        return $caminho;
    }

    private function conexao(): \Doctrine\DBAL\Connection
    {
        return static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class)->getConnection();
    }
}
