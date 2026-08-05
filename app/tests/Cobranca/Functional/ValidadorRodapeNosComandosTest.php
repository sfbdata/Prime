<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Service\Importacao\ValidadorRodapeFiltros;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * O validador do rodapé LIGADO aos 4 comandos — spec
 * `docs/specs/cobranca-validador-rodape-filtros.md` §3.2.
 *
 * 🔑 **Por que esta classe existe, separada dos testes unitários.** O
 * `ValidadorRodapeFiltrosTest` prova as REGRAS; aqui se prova que elas estão de fato no caminho de
 * execução. As duas coisas já falharam separadamente nesta frente: regra certa em serviço que ninguém
 * chama é o mesmo que regra nenhuma, e foi assim que a purga da outra frente passou batida (handoff:
 * *"2 mutações passaram batido — purga sem chamador"*).
 *
 * Os testes rodam **sem `--confirmar`**, de propósito: a recusa tem de valer no dry-run também. Um
 * dry-run sobre arquivo errado imprime um relatório convincente e falso — pior do que erro nenhum.
 */
#[CoversClass(ValidadorRodapeFiltros::class)]
final class ValidadorRodapeNosComandosTest extends CobrancaWebTestCase
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

    #[TestDox('🔒 acordos: o arquivo CANCELADO é barrado — a decisão do dono vira trava técnica')]
    public function testAcordosCanceladoEhRecusado(): void
    {
        $saida = $this->rodarCom(
            'app:cobranca:importar-acordos',
            'Filtros: Situação do acordo: Cancelado; Período de criação do acordo: Todos; Unidade/Cliente: Todos; Sacado: Todos',
        );

        self::assertStringContainsString('RECUSADA', $saida);
        self::assertStringContainsString('Situação do acordo', $saida);
        self::assertStringContainsString('Cancelado', $saida, 'a mensagem tem de mostrar o que veio no arquivo');
    }

    #[TestDox('receitas: a janela de recebimento (o filtro de 2026) é barrada')]
    public function testReceitasComJanelaDeRecebimentoEhRecusada(): void
    {
        $saida = $this->rodarCom(
            'app:cobranca:importar-receitas',
            'Filtros: Situação das contas: Baixadas; Competência: Todas; Período de vencimento: Todos; Período de recebimento: 01/01/2026 a 04/08/2026; Unidade: Todos; Classe de conta: Todas; Sacado: Todos;',
        );

        self::assertStringContainsString('RECUSADA', $saida);
        self::assertStringContainsString('Período de recebimento', $saida);
    }

    #[TestDox('inadimplência: recorte com competência filtrada é barrado')]
    public function testInadimplenciaComCompetenciaFiltradaEhRecusada(): void
    {
        $saida = $this->rodarCom(
            'app:cobranca:importar',
            'Filtros:  Inadimplência até:04/08/2026; Competência: 07/2026; Período de vencimento: Todos; Unidade: Todas; Sacado: Todos',
        );

        self::assertStringContainsString('RECUSADA', $saida);
        self::assertStringContainsString('Competência', $saida);
    }

    #[TestDox('cadastro: recorte de unidade específica é barrado')]
    public function testCadastroComUnidadeEspecificaEhRecusado(): void
    {
        $saida = $this->rodarCom('app:cobranca:importar-cadastro', 'Filtros: Unidades: 01-03A');

        self::assertStringContainsString('RECUSADA', $saida);
        self::assertStringContainsString('Unidades', $saida);
    }

    /**
     * Sem a linha `Filtros:` não há recorte para conferir, e "não achei" NÃO pode virar "está tudo
     * bem": seria uma porta aberta silenciosa — exatamente o defeito que este item existe para fechar.
     */
    #[TestDox('arquivo sem linha Filtros: é recusado, não aceito por omissão')]
    public function testArquivoSemRodapeEhRecusado(): void
    {
        $saida = $this->rodarCom('app:cobranca:importar-receitas', null);

        self::assertStringContainsString('RECUSADA', $saida);
        self::assertStringContainsString('Filtros:', $saida);
    }

    /**
     * Roda o comando contra uma planilha que só tem o rodapé, e exige `INVALID`.
     *
     * 🔑 **A planilha é deliberadamente VAZIA de dados.** É o que prova que a recusa aconteceu ANTES
     * do adapter: um arquivo sem cabeçalho nem linhas faria o adapter devolver zero (ou explodir), e
     * em nenhum dos dois casos o comando devolveria `INVALID` com a mensagem do recorte. Se alguém
     * mover a conferência para depois da leitura, estes testes ficam vermelhos.
     */
    private function rodarCom(string $comando, ?string $rodape): string
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);

        $tester = new CommandTester((new Application(static::$kernel))->find($comando));
        $tester->execute([
            '--tenant-id' => (string) $tenant->getId(),
            '--carteira-id' => (string) $carteira->getId(),
            '--usuario-id' => (string) $this->idDoAdmin($tenant),
            '--arquivo' => $this->planilhaSoComRodape($rodape),
        ]);

        self::assertSame(
            Command::INVALID,
            $tester->getStatusCode(),
            'recorte errado tem de devolver INVALID — e sem `--confirmar`, porque o dry-run também mente',
        );

        return $tester->getDisplay();
    }

    private function planilhaSoComRodape(?string $rodape): string
    {
        $planilha = new Spreadsheet();
        $aba = $planilha->getActiveSheet();
        $aba->setCellValue('A1', 'RELATÓRIO');
        if ($rodape !== null) {
            $aba->setCellValue('A3', $rodape);
        }

        $caminho = tempnam(sys_get_temp_dir(), 'rodape') . '.xlsx';
        (new Xlsx($planilha))->save($caminho);
        $this->temporarios[] = $caminho;

        return $caminho;
    }

    private function idDoAdmin(object $tenant): int
    {
        $vinculo = $this->em()->getRepository(\App\Entity\Auth\UserTenant::class)->findOneBy(['tenant' => $tenant]);
        self::assertNotNull($vinculo);

        return (int) $vinculo->getUser()->getId();
    }

    private function em(): \Doctrine\ORM\EntityManagerInterface
    {
        return static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
    }
}
