<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\Conector\DTO\ChamadaDoConector;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Entity\ClienteDoConector;
use App\Conector\Entity\ConsentimentoDoConector;
use App\Conector\Entity\EscritorioDoConector;
use App\Conector\Enum\TipoDeClienteDoConector;
use App\Conector\Exception\ErroDaFerramenta;
use App\Conector\Ferramenta\AutomacoesDeEmailFerramenta;
use App\Conector\Ferramenta\ConfirmarAcaoFerramenta;
use App\Conector\Ferramenta\TabelaDeAbaEChave;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `automacoes_de_email` com o banco e o portão de verdade (spec mcp-remoto-oauth 6.9 e 12.30): só o Master passa, na
 * chamada e na reconferência; desligar acontece na hora; ligar, ou mudar o que fica ligado, vira proposta que só grava
 * quando confirmada; pedir o estado que já está é "nada a fazer"; as regras dos UseCases recusam antes de propor.
 */
#[CoversClass(AutomacoesDeEmailFerramenta::class)]
final class AutomacoesDeEmailFerramentaTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private Tenant $escritorio;
    private User $pessoa;
    private ConsentimentoDoConector $consentimento;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->escritorio = $this->escritorio();
        $this->pessoa = $this->pessoa(['ROLE_USER', 'ROLE_SUPER_ADMIN']);
        $this->vinculo($this->pessoa, $this->escritorio);
        $doConector = new EscritorioDoConector($this->escritorio);
        $doConector->ligar($this->pessoa, new \DateTimeImmutable());
        $this->em()->persist($doConector);
        $app = new ClienteDoConector('https://claude.ai/oauth/' . uniqid() . '/client.json', TipoDeClienteDoConector::Cimd, 'Claude', ['https://claude.ai/api/mcp/auth_callback'], new \DateTimeImmutable());
        $this->em()->persist($app);
        $this->consentimento = new ConsentimentoDoConector($this->pessoa, $this->escritorio, $app, ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable());
        $this->em()->persist($this->consentimento);
        $this->em()->flush();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get('services_resetter')->reset();
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function acoes(): array
    {
        return array_combine(AutomacoesDeEmailFerramenta::ACOES, array_map(static fn (string $a): array => [$a], AutomacoesDeEmailFerramenta::ACOES));
    }

    #[DataProvider('acoes')]
    #[TestDox('segunda porta: quem não é Master é recusado em toda ação, com a mensagem neutra e o motivo so_do_master, sem gravar nada')]
    public function testNaoMasterRecusado(string $acao): void
    {
        $comum = $this->pessoa(['ROLE_USER', 'ROLE_ADMIN']);
        $this->vinculo($comum, $this->escritorio);
        $antes = $this->estadoDoBanco();

        try {
            $this->executar(['acao' => $acao, 'chave' => $acao === 'alterar_data' ? 'ano' : 'lemb', 'ligada' => false], $comum);
            self::fail('devia recusar');
        } catch (ErroDaFerramenta $erro) {
            self::assertSame(AutomacoesDeEmailFerramenta::MOTIVO_SO_DO_MASTER, $erro->motivo);
            self::assertSame('Ferramenta não disponível.', $erro->getMessage());
        }
        self::assertSame($antes, $this->estadoDoBanco());
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('a tabela de aba e chave tem uma linha por ação, sem módulo nem chave; o esquema é fechado')]
    public function testTabelaEEsquema(): void
    {
        self::assertSame(AutomacoesDeEmailFerramenta::ACOES, TabelaDeAbaEChave::acoes(AutomacoesDeEmailFerramenta::NOME));
        foreach (AutomacoesDeEmailFerramenta::ACOES as $acao) {
            $linha = TabelaDeAbaEChave::linha(AutomacoesDeEmailFerramenta::NOME, $acao);
            self::assertNotNull($linha, $acao);
            self::assertSame([], $linha->modulos);
            self::assertSame([], $linha->chaves);
        }
        $esquema = static::getContainer()->get(AutomacoesDeEmailFerramenta::class)->esquemaDeEntrada();
        self::assertFalse($esquema['additionalProperties']);
        self::assertSame(['acao', 'chave'], $esquema['required']);
    }

    #[TestDox('entrada: ação fora da lista, chave faltando, campo de outra ação ou de tipo errado é entrada inválida')]
    public function testEntradaInvalida(): void
    {
        $invalidas = [
            [], ['acao' => 'alterar_automacao'], ['acao' => 'excluir', 'chave' => 'lemb'], ['acao' => 'alterar_automacao', 'chave' => 3],
            ['acao' => 'alterar_automacao', 'chave' => 'lemb', 'publico' => 'todos'],
            ['acao' => 'alterar_data', 'chave' => 'ano', 'atraso' => '24h'],
            ['acao' => 'alterar_automacao', 'chave' => 'lemb', 'ligada' => 'sim'],
            ['acao' => 'alterar_data', 'chave' => 'ano', 'antecedencia' => '1'],
        ];
        foreach ($invalidas as $argumentos) {
            try {
                $this->executar($argumentos);
                self::fail('devia recusar: ' . json_encode($argumentos));
            } catch (ErroDaFerramenta $erro) {
                self::assertSame(ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA, $erro->motivo);
            }
        }
    }

    #[TestDox('desligar uma automação grava na hora, sem proposta, e a resposta não traz e-mail')]
    public function testDesligarAutomacaoGravaDireto(): void
    {
        $resposta = $this->executar(['acao' => 'alterar_automacao', 'chave' => 'lemb', 'ligada' => false]);

        self::assertSame('concluida', $resposta->dados['estado']);
        self::assertFalse($resposta->dados['ligada']);
        self::assertFalse($this->automacao('lemb')['ligada']);
        self::assertSame(0, $this->contarPropostas());
        self::assertDoesNotMatchRegularExpression('/[\w.+-]+@[\w-]+\.[\w.-]+/u', json_encode($resposta->dados, \JSON_THROW_ON_ERROR));
    }

    #[TestDox('ligar uma automação vira proposta e não grava; confirmar pelo executor grava, e o resumo diz que passa a mandar e-mail')]
    public function testLigarAutomacaoPorProposta(): void
    {
        self::assertFalse($this->automacao('dica')['ligada']);

        $proposta = $this->executar(['acao' => 'alterar_automacao', 'chave' => 'dica', 'ligada' => true]);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        $resumo = $proposta->dados['proposta']['resumo'];
        self::assertStringContainsString('de desligada para ligada, atraso 7d', $resumo);
        self::assertStringContainsString('passa a mandar e-mail', $resumo);
        self::assertFalse($this->automacao('dica')['ligada'], 'propor não grava');
        self::assertSame(1, $this->contarPropostas());

        $confirmada = $this->confirmar($proposta);

        self::assertSame('confirmada', $confirmada->dados['estado']);
        self::assertTrue($this->automacao('dica')['ligada']);
    }

    #[TestDox('mudar o atraso de uma automação que fica ligada é proposta; campo omitido mantém o atraso atual ao ligar')]
    public function testMudarAtrasoComLigadaViraProposta(): void
    {
        $proposta = $this->executar(['acao' => 'alterar_automacao', 'chave' => 'lemb', 'atraso' => '72h']);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertStringContainsString('de ligada, atraso 24h para ligada, atraso 72h', $proposta->dados['proposta']['resumo']);
        self::assertSame('24h', $this->automacao('lemb')['atraso']);
        $this->confirmar($proposta);
        self::assertSame('72h', $this->automacao('lemb')['atraso']);
    }

    #[TestDox('estado final igual ao atual é "nada a fazer": não grava e não propõe')]
    public function testEstadoIgualNadaAFazer(): void
    {
        $antes = $this->estadoDoBanco();
        foreach ([
            ['acao' => 'alterar_automacao', 'chave' => 'lemb', 'ligada' => true],
            ['acao' => 'alterar_automacao', 'chave' => 'lemb', 'atraso' => '24h'],
            ['acao' => 'alterar_automacao', 'chave' => 'dica', 'ligada' => false],
            ['acao' => 'alterar_automacao', 'chave' => 'cad', 'ligada' => true],
            ['acao' => 'alterar_data', 'chave' => 'ano'],
            ['acao' => 'alterar_data', 'chave' => 'ano', 'ligada' => true, 'antecedencia' => 0, 'publico' => 'todos'],
        ] as $argumentos) {
            $resposta = $this->executar($argumentos);
            self::assertSame('nada_a_fazer', $resposta->dados['estado'], json_encode($argumentos));
        }
        self::assertSame(0, $this->contarPropostas());
        self::assertSame($antes, $this->estadoDoBanco());
    }

    #[TestDox('a automação cad não desliga: a recusa é a do UseCase e nada é gravado nem proposto')]
    public function testCadNaoDesliga(): void
    {
        $this->assertRecusa(['acao' => 'alterar_automacao', 'chave' => 'cad', 'ligada' => false], 'Esta automação é obrigatória: sem ela ninguém consegue criar conta.');
        self::assertTrue($this->automacao('cad')['ligada']);
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('automação desconhecida, de aviso fixo ou com atraso fora da lista é recusada com a mensagem do UseCase')]
    public function testAutomacaoInvalida(): void
    {
        $this->assertRecusa(['acao' => 'alterar_automacao', 'chave' => 'xyz', 'ligada' => true], 'Automação desconhecida.');
        $this->assertRecusa(['acao' => 'alterar_automacao', 'chave' => 'lib', 'ligada' => false], 'Automação desconhecida.');
        $this->assertRecusa(['acao' => 'alterar_automacao', 'chave' => 'lemb', 'atraso' => '5h'], 'Escolha um dos atrasos da lista.');
        $this->assertRecusa(['acao' => 'alterar_automacao', 'chave' => 'lemb', 'ligada' => false, 'atraso' => '5h'], 'Escolha um dos atrasos da lista.');
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('reconferir: a pessoa deixou de ser Master depois da proposta, a confirmação recusa e nada é gravado')]
    public function testReconferirSemMaster(): void
    {
        $proposta = $this->executar(['acao' => 'alterar_automacao', 'chave' => 'dica', 'ligada' => true]);
        $this->tirarOPapelDeMaster();

        $erro = $this->recusaDaConfirmacao($proposta);

        self::assertSame(AutomacoesDeEmailFerramenta::MOTIVO_SO_DO_MASTER, $erro->motivo);
        self::assertFalse($this->automacao('dica')['ligada']);
    }

    #[TestDox('executarConfirmada também confere o Master: quem não é Master é recusado mesmo sem passar pelo reconferir')]
    public function testConfirmadaSemMaster(): void
    {
        $comum = $this->pessoa(['ROLE_USER', 'ROLE_ADMIN']);
        $this->vinculo($comum, $this->escritorio);
        $dados = ['chave' => 'dica', 'antes' => ['ligada' => false, 'atraso' => '7d'], 'depois' => ['ligada' => true, 'atraso' => '7d']];

        try {
            static::getContainer()->get(AutomacoesDeEmailFerramenta::class)->executarConfirmada('alterar_automacao', $dados, $this->chamada($comum));
            self::fail('deveria recusar quem não é Master');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(AutomacoesDeEmailFerramenta::MOTIVO_SO_DO_MASTER, $e->motivo);
        }
        self::assertFalse($this->automacao('dica')['ligada']);
    }

    #[TestDox('reconferir recusa proposta que não termina ligada: o que desliga é direto e nunca vira proposta')]
    public function testReconferirSoLigada(): void
    {
        $dados = ['chave' => 'dica', 'antes' => ['ligada' => false, 'atraso' => '7d'], 'depois' => ['atraso' => '72h']];

        $this->expectExceptionObject(ErroDaFerramenta::entradaInvalida());
        static::getContainer()->get(AutomacoesDeEmailFerramenta::class)->reconferir('alterar_automacao', $dados, $this->chamada());
    }

    #[TestDox('reconferir: o estado mudou depois da proposta, a confirmação recusa e nada é gravado')]
    public function testReconferirEstadoMudou(): void
    {
        $proposta = $this->executar(['acao' => 'alterar_automacao', 'chave' => 'lemb', 'atraso' => '72h']);
        $this->conexao()->executeStatement("UPDATE comercial_automacao SET atraso = '48h' WHERE chave = 'lemb'");

        $erro = $this->recusaDaConfirmacao($proposta);

        self::assertSame(AutomacoesDeEmailFerramenta::MUDOU, $erro->getMessage());
        self::assertSame('48h', $this->automacao('lemb')['atraso']);
    }

    #[TestDox('alterar_data: desligar grava na hora; a data sem linha no banco ganha a linha')]
    public function testDesligarDataGravaDireto(): void
    {
        $this->conexao()->executeStatement("DELETE FROM comercial_data_comemorativa WHERE chave = 'ano'");

        $resposta = $this->executar(['acao' => 'alterar_data', 'chave' => 'ano', 'ligada' => false]);

        self::assertSame('concluida', $resposta->dados['estado']);
        self::assertFalse($resposta->dados['ligada']);
        self::assertSame(['ligada' => false, 'antecedencia' => 0, 'publico' => 'todos'], $this->data('ano'));
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('alterar_data: ligar e mudar antecedência ou público viram proposta; confirmar grava; a data sem linha vale o padrão no "antes"')]
    public function testLigarDataPorProposta(): void
    {
        $this->conexao()->executeStatement("UPDATE comercial_data_comemorativa SET ligada = false WHERE chave = 'carnaval'");
        $proposta = $this->executar(['acao' => 'alterar_data', 'chave' => 'carnaval', 'ligada' => true, 'antecedencia' => 3, 'publico' => 'prim']);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        $resumo = $proposta->dados['proposta']['resumo'];
        self::assertStringContainsString('de desligada para ligada, 3 dias antes, para Primários', $resumo);
        self::assertStringContainsString('passa a mandar e-mail', $resumo);
        self::assertFalse($this->data('carnaval')['ligada']);
        $this->confirmar($proposta);
        self::assertSame(['ligada' => true, 'antecedencia' => 3, 'publico' => 'prim'], $this->data('carnaval'));

        $this->conexao()->executeStatement("DELETE FROM comercial_data_comemorativa WHERE chave = 'pascoa'");
        $semLinha = $this->executar(['acao' => 'alterar_data', 'chave' => 'pascoa', 'antecedencia' => 1]);
        self::assertStringContainsString('de ligada, no dia, para Todos para ligada, 1 dia antes, para Todos', $semLinha->dados['proposta']['resumo']);
        $this->confirmar($semLinha);
        self::assertSame(['ligada' => true, 'antecedencia' => 1, 'publico' => 'todos'], $this->data('pascoa'));
    }

    #[TestDox('alterar_data: chave, antecedência e público inválidos são recusados com a mensagem do UseCase')]
    public function testDataInvalida(): void
    {
        $this->assertRecusa(['acao' => 'alterar_data', 'chave' => 'xyz', 'ligada' => true], 'Data comemorativa desconhecida.');
        $this->assertRecusa(['acao' => 'alterar_data', 'chave' => 'ano', 'antecedencia' => 2], 'Escolha No dia, 1 dia antes ou 3 dias antes.');
        $this->assertRecusa(['acao' => 'alterar_data', 'chave' => 'ano', 'publico' => 'csv'], 'Escolha Todos, Primários ou Base de contatos.');
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('reconferir da data: a pessoa deixou de ser Master, ou a data mudou, e a confirmação recusa sem gravar')]
    public function testReconferirData(): void
    {
        $proposta = $this->executar(['acao' => 'alterar_data', 'chave' => 'ano', 'antecedencia' => 3]);
        $this->conexao()->executeStatement("UPDATE comercial_data_comemorativa SET publico = 'prim' WHERE chave = 'ano'");
        self::assertSame(AutomacoesDeEmailFerramenta::MUDOU, $this->recusaDaConfirmacao($proposta)->getMessage());
        self::assertSame(0, $this->data('ano')['antecedencia']);

        $outra = $this->executar(['acao' => 'alterar_data', 'chave' => 'carnaval', 'antecedencia' => 3]);
        $this->tirarOPapelDeMaster();
        self::assertSame(AutomacoesDeEmailFerramenta::MOTIVO_SO_DO_MASTER, $this->recusaDaConfirmacao($outra)->motivo);
        self::assertSame(0, $this->data('carnaval')['antecedencia']);
    }

    private function tirarOPapelDeMaster(): void
    {
        $this->conexao()->executeStatement('UPDATE "user" SET roles = ? WHERE id = ?', ['["ROLE_USER"]', $this->pessoa->getId()]);
        $this->em()->clear();
    }

    /** @param array<string, mixed> $argumentos */
    private function executar(array $argumentos, ?User $pessoa = null): RespostaDaFerramenta
    {
        static::getContainer()->get('services_resetter')->reset();

        return static::getContainer()->get(AutomacoesDeEmailFerramenta::class)->executar($argumentos, $this->chamada($pessoa));
    }

    private function confirmar(RespostaDaFerramenta $proposta): RespostaDaFerramenta
    {
        static::getContainer()->get('services_resetter')->reset();
        $argumentos = ['proposta_id' => $proposta->dados['proposta']['proposta_id'], 'resumo' => $proposta->dados['proposta']['resumo']];

        return static::getContainer()->get(ConfirmarAcaoFerramenta::class)->executar($argumentos, $this->chamada());
    }

    private function recusaDaConfirmacao(RespostaDaFerramenta $proposta): ErroDaFerramenta
    {
        try {
            $this->confirmar($proposta);
        } catch (ErroDaFerramenta $e) {
            return $e;
        }
        self::fail('a confirmação deveria recusar');
    }

    /** @param array<string, mixed> $argumentos */
    private function assertRecusa(array $argumentos, string $mensagem): void
    {
        try {
            $this->executar($argumentos);
        } catch (ErroDaFerramenta $e) {
            self::assertSame(ErroDaFerramenta::MOTIVO_RECUSADA, $e->motivo);
            self::assertSame($mensagem, $e->getMessage());

            return;
        }
        self::fail('deveria recusar: ' . $mensagem);
    }

    private function chamada(?User $pessoa = null): ChamadaDoConector
    {
        $pessoa ??= $this->pessoa;
        $contexto = $this->portao()->abrir((int) $pessoa->getId(), (int) $this->escritorio->getId());

        return new ChamadaDoConector($contexto, 41, (int) $this->consentimento->getId(), 'claude.ai', ['mcp:leitura', 'mcp:escrita'], new \DateTimeImmutable('+7 days'));
    }

    /** @return array{ligada: bool, atraso: ?string} */
    private function automacao(string $chave): array
    {
        $linha = $this->conexao()->fetchAssociative('SELECT ligada, atraso FROM comercial_automacao WHERE chave = ?', [$chave]);
        self::assertIsArray($linha);

        return ['ligada' => (bool) $linha['ligada'], 'atraso' => $linha['atraso']];
    }

    /** @return array{ligada: bool, antecedencia: int, publico: string} */
    private function data(string $chave): array
    {
        $linha = $this->conexao()->fetchAssociative('SELECT ligada, antecedencia, publico FROM comercial_data_comemorativa WHERE chave = ?', [$chave]);
        self::assertIsArray($linha);

        return ['ligada' => (bool) $linha['ligada'], 'antecedencia' => (int) $linha['antecedencia'], 'publico' => (string) $linha['publico']];
    }

    private function estadoDoBanco(): string
    {
        return json_encode([
            $this->conexao()->fetchAllAssociative('SELECT chave, ligada, atraso FROM comercial_automacao ORDER BY chave'),
            $this->conexao()->fetchAllAssociative('SELECT chave, ligada, antecedencia, publico FROM comercial_data_comemorativa ORDER BY chave'),
        ], \JSON_THROW_ON_ERROR);
    }

    private function contarPropostas(): int
    {
        return (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM conector_ia_proposta');
    }

    private function conexao(): \Doctrine\DBAL\Connection
    {
        return static::getContainer()->get(\Doctrine\DBAL\Connection::class);
    }
}
