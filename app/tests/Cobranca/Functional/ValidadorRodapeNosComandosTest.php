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
        // A frase INTEIRA: "Cancelado" e "Situação do acordo" sozinhos também aparecem no rodapé que a
        // mensagem imprime de volta, então assert com eles passaria por QUALQUER motivo de recusa
        // (achado da 1ª revisão). O trecho "e precisa ser" só existe no motivo.
        self::assertStringContainsString(
            'O campo "Situação do acordo" veio como "Cancelado" e precisa ser "Em andamento" ou "Liquidado".',
            $saida,
        );
    }

    #[TestDox('receitas: a janela de recebimento (o filtro de 2026) é barrada')]
    public function testReceitasComJanelaDeRecebimentoEhRecusada(): void
    {
        $saida = $this->rodarCom(
            'app:cobranca:importar-receitas',
            'Filtros: Situação das contas: Baixadas; Competência: Todas; Período de vencimento: Todos; Período de recebimento: 01/01/2026 a 04/08/2026; Unidade: Todos; Classe de conta: Todas; Sacado: Todos;',
        );

        self::assertStringContainsString('RECUSADA', $saida);
        self::assertStringContainsString(
            'O campo "Período de recebimento" veio como "01/01/2026 a 04/08/2026" e precisa ser "Todos" ou "Todas".',
            $saida,
        );
    }

    #[TestDox('inadimplência: recorte com competência filtrada é barrado')]
    public function testInadimplenciaComCompetenciaFiltradaEhRecusada(): void
    {
        $saida = $this->rodarCom(
            'app:cobranca:importar',
            'Filtros:  Inadimplência até:04/08/2026; Competência: 07/2026; Período de vencimento: Todos; Unidade: Todas; Sacado: Todos',
        );

        self::assertStringContainsString('RECUSADA', $saida);
        self::assertStringContainsString(
            'O campo "Competência" veio como "07/2026" e precisa ser "Todas".',
            $saida,
        );
    }

    #[TestDox('cadastro: recorte de unidade específica é barrado')]
    public function testCadastroComUnidadeEspecificaEhRecusado(): void
    {
        $saida = $this->rodarCom('app:cobranca:importar-cadastro', 'Filtros: Unidades: 01-03A');

        self::assertStringContainsString('RECUSADA', $saida);
        self::assertStringContainsString(
            'O campo "Unidades" veio como "01-03A" e precisa ser "Todas".',
            $saida,
        );
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
        self::assertStringContainsString('não tem a linha "Filtros:" no rodapé', $saida);
    }

    /**
     * A ORDEM — a conferência vem ANTES do adapter (spec §3.2), não depois.
     *
     * ⚠️ **Este teste já nasceu errado DUAS vezes**, e as duas provas falsas ensinam mais que o teste:
     *
     *  1. a 1ª versão dizia que a planilha vazia provava a ordem. Não provava — os 4 adapters leem
     *     planilha vazia sem exceção, devolvendo zero itens (achado da 1ª revisão);
     *  2. a 2ª versão exigia que a seção `Leitura:` não saísse. Também não provava: mover a leitura
     *     para antes NÃO move a impressão, que fica depois da conferência. Injetei a troca de ordem e
     *     o teste continuou VERDE.
     *
     * O que prova de verdade: um arquivo que **não é um `.xlsx`**. O validador o transforma em recusa
     * com motivo (ele trata a falha de abertura); o adapter, não — ele estoura. Então, se a
     * conferência acontecer primeiro, sai a mensagem do validador; se o adapter for chamado antes,
     * o comando morre com outra coisa. É observável, e é o efeito que distingue os dois mundos.
     *
     * De quebra, é o cenário-mãe desta frente: download interrompido.
     */
    #[TestDox('🔑 arquivo corrompido: recusa com motivo (prova que o adapter não foi chamado antes)')]
    public function testArquivoCorrompidoViraRecusaEnaoStackTrace(): void
    {
        // Assinatura de ZIP ("PK\x03\x04") seguida de lixo: é o que um download interrompido produz.
        // O `IOFactory` reconhece como `.xlsx` pela assinatura e estoura ao abrir o zip — texto puro
        // NÃO serve aqui, porque ele é aceito como CSV e vira "sem linha Filtros:", que é outro ramo
        // (medido ao escrever este teste).
        $caminho = tempnam(sys_get_temp_dir(), 'quebrado') . '.xlsx';
        file_put_contents($caminho, "PK\x03\x04" . str_repeat("\x00", 64));
        $this->temporarios[] = $caminho;

        $tester = $this->rodarArquivo('app:cobranca:importar-receitas', $caminho);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        $saida = (string) preg_replace('/\s+/u', ' ', $tester->getDisplay());
        self::assertStringContainsString('Não foi possível abrir o arquivo para conferir o recorte', $saida);
        self::assertStringContainsString('o download completou', $saida, 'a mensagem precisa dizer o que fazer');
    }

    /** Roda o comando contra uma planilha que só tem o rodapé, e exige `INVALID`. */
    private function rodarCom(string $comando, ?string $rodape): string
    {
        $tester = $this->rodarArquivo($comando, $this->planilhaSoComRodape($rodape));

        self::assertSame(
            Command::INVALID,
            $tester->getStatusCode(),
            'recorte errado tem de devolver INVALID — e sem `--confirmar`, porque o dry-run também mente',
        );

        // O SymfonyStyle quebra linha por largura de console: sem colapsar os brancos, um assert de
        // frase inteira falharia por causa da formatação, não do comportamento.
        return (string) preg_replace('/\s+/u', ' ', $tester->getDisplay());
    }

    /** Roda o comando contra um caminho qualquer, sem exigir código de saída — quem exige é o chamador. */
    private function rodarArquivo(string $comando, string $caminho): CommandTester
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [$carteira] = $this->semearGrafo($tenant);

        $tester = new CommandTester((new Application(static::$kernel))->find($comando));
        $tester->execute([
            '--tenant-id' => (string) $tenant->getId(),
            '--carteira-id' => (string) $carteira->getId(),
            '--usuario-id' => (string) $this->idDoAdmin($tenant),
            '--arquivo' => $caminho,
        ]);

        return $tester;
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
