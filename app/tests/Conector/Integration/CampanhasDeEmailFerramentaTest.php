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
use App\Conector\Ferramenta\ConfirmarAcaoFerramenta;
use App\Conector\Ferramenta\CampanhasDeEmailFerramenta;
use App\Conector\Ferramenta\TabelaDeAbaEChave;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * `campanhas_de_email` com o banco e o portão de verdade (spec mcp-remoto-oauth 6.9, decisões 36 a 41): só o Master
 * passa, na chamada e na reconferência; criar, editar e cancelar viram proposta e só gravam confirmados; enviar teste é direto, só ao Master;
 * modelo ou campanha mudados entre propor e confirmar recusam; a trilha sai com a rota `conector_mcp` e sem escritório.
 */
#[CoversClass(CampanhasDeEmailFerramenta::class)]
final class CampanhasDeEmailFerramentaTest extends KernelTestCase
{
    use CenarioDoConectorTrait;

    private Tenant $escritorio;
    private User $pessoa;
    private ConsentimentoDoConector $consentimento;

    protected function setUp(): void
    {
        self::bootKernel();
        static::getContainer()->get(RequestStack::class)->push(new Request(attributes: ['_route' => 'conector_mcp']));
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
        return array_combine(CampanhasDeEmailFerramenta::ACOES, array_map(static fn (string $a): array => [$a], CampanhasDeEmailFerramenta::ACOES));
    }

    #[DataProvider('acoes')]
    #[TestDox('segunda porta: quem não é Master é recusado em toda ação, com a mensagem neutra, sem gravar nem propor')]
    public function testNaoMasterRecusado(string $acao): void
    {
        $comum = $this->pessoa(['ROLE_USER', 'ROLE_ADMIN']);
        $this->vinculo($comum, $this->escritorio);
        $this->semearLead();

        try {
            $this->executar(['acao' => $acao] + self::argumentosValidos($acao), $comum);
            self::fail('devia recusar');
        } catch (ErroDaFerramenta $erro) {
            self::assertSame(CampanhasDeEmailFerramenta::MOTIVO_SO_DO_MASTER, $erro->motivo);
            self::assertSame('Ferramenta não disponível.', $erro->getMessage());
        }
        self::assertSame(0, $this->contar('comercial_campanha'));
        self::assertSame(0, $this->contarPropostas());
    }

    /** @return array<string, mixed> */
    private static function argumentosValidos(string $acao): array
    {
        return match ($acao) {
            'criar' => ['modelo' => 'convite', 'publico' => 'leads'],
            'enviar_teste' => ['modelo' => 'convite'],
            default => ['campanha_id' => 1],
        };
    }

    #[TestDox('a tabela de aba e chave tem uma linha por ação, sem módulo nem chave; o esquema é fechado e sem público de lista')]
    public function testTabelaEEsquema(): void
    {
        self::assertSame(CampanhasDeEmailFerramenta::ACOES, TabelaDeAbaEChave::acoes(CampanhasDeEmailFerramenta::NOME));
        foreach (CampanhasDeEmailFerramenta::ACOES as $acao) {
            $linha = TabelaDeAbaEChave::linha(CampanhasDeEmailFerramenta::NOME, $acao);
            self::assertNotNull($linha, $acao);
            self::assertSame([], $linha->modulos);
            self::assertSame([], $linha->chaves);
        }
        $esquema = static::getContainer()->get(CampanhasDeEmailFerramenta::class)->esquemaDeEntrada();
        self::assertFalse($esquema['additionalProperties']);
        self::assertSame(['leads', 'prim', 'sec', 'todos'], $esquema['properties']['publico']['enum']);
        self::assertArrayNotHasKey('mensagem', $esquema['properties'], 'a campanha é só de modelo salvo');
    }

    #[TestDox('entrada: ação fora da lista, campo faltando, público de lista, campo de outra ação ou de tipo errado é entrada inválida')]
    public function testEntradaInvalida(): void
    {
        $invalidas = [
            [], ['acao' => 'importar'], ['acao' => 'criar'], ['acao' => 'criar', 'modelo' => 'convite'], ['acao' => 'criar', 'modelo' => 'convite', 'publico' => 'csv'],
            ['acao' => 'criar', 'modelo' => 'convite', 'publico' => 'indiv'], ['acao' => 'criar', 'modelo' => 'convite', 'publico' => 'leads', 'campanha_id' => 1],
            ['acao' => 'criar', 'modelo' => 'convite', 'publico' => 'leads', 'frequencia' => 'diaria'], ['acao' => 'criar', 'modelo' => 'Bad Key!', 'publico' => 'leads'],
            ['acao' => 'cancelar'], ['acao' => 'cancelar', 'campanha_id' => '1'], ['acao' => 'cancelar', 'campanha_id' => 0], ['acao' => 'editar', 'campanha_id' => 1, 'publico' => 'leads'],
            ['acao' => 'editar', 'campanha_id' => 1, 'atualizar_do_modelo' => 'sim'], ['acao' => 'enviar_teste'], ['acao' => 'enviar_teste', 'modelo' => 'convite', 'para' => 'a@b.com'],
        ];
        foreach ($invalidas as $argumentos) {
            try {
                $this->executar($argumentos);
                self::fail('devia recusar: ' . json_encode($argumentos));
            } catch (ErroDaFerramenta $erro) {
                self::assertSame(ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA, $erro->motivo, json_encode($argumentos));
            }
        }
        $this->assertRecusa(['acao' => 'criar', 'modelo' => 'convite', 'publico' => 'leads', 'agendar' => str_repeat('2', 20)], 'O campo agendar passa do limite de 19 caracteres.');
        $this->assertRecusa(['acao' => 'criar', 'modelo' => str_repeat('a', 41), 'publico' => 'leads'], 'O campo modelo passa do limite de 40 caracteres.');
        self::assertSame(0, $this->contarPropostas());
    }

    #[TestDox('criar vira proposta com modelo, assunto, texto, público, total, data e aviso de envio desligado; só grava depois do sim, com a trilha conector_mcp')]
    public function testCriarPorProposta(): void
    {
        $this->semearLead();
        $agendar = (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i');
        $proposta = $this->executar(['acao' => 'criar', 'modelo' => 'convite', 'publico' => 'leads', 'agendar' => $agendar]);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        $resumo = $proposta->dados['proposta']['resumo'];
        foreach (['Convite · novos escritórios', 'Base de contatos, 1 destinatário(s)', 'envio único em ' . (new \DateTimeImmutable($agendar))->format('d/m/Y H:i'), 'Seu escritório 100% digital, de graça', 'envio de campanhas está desligado', 'Texto:'] as $trecho) {
            self::assertStringContainsString($trecho, $resumo);
        }
        self::assertSame(0, $this->contar('comercial_campanha'), 'propor não grava');

        $confirmada = $this->confirmar($proposta);

        self::assertSame('confirmada', $confirmada->dados['estado']);
        $campanha = $this->conexao()->fetchAssociative('SELECT estado, total, publico, modelo_chave, chave_idempotencia FROM comercial_campanha');
        self::assertSame(['agendada', 1, 'leads', 'convite'], [$campanha['estado'], (int) $campanha['total'], $campanha['publico'], $campanha['modelo_chave']]);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $campanha['chave_idempotencia']);
        $this->exigirTrilha('camp_conf');
        self::assertDoesNotMatchRegularExpression('/[\w.+-]+@[\w-]+\.[\w.-]+/u', json_encode($confirmada->dados, \JSON_THROW_ON_ERROR));
    }

    #[TestDox('criar recusa antes de propor: sem destinatário, data passada ou inválida e modelo inexistente; recorrente aparece no resumo')]
    public function testCriarRecusas(): void
    {
        $this->assertRecusa(['acao' => 'criar', 'modelo' => 'convite', 'publico' => 'leads'], 'Nenhum destinatário nesta lista.');
        $this->semearLead();
        $this->assertRecusa(['acao' => 'criar', 'modelo' => 'convite', 'publico' => 'leads', 'agendar' => '2020-01-01T10:00'], 'Escolha uma data futura.');
        $this->assertRecusa(['acao' => 'criar', 'modelo' => 'convite', 'publico' => 'leads', 'agendar' => 'amanha'], 'Data de agendamento inválida.');
        try {
            $this->executar(['acao' => 'criar', 'modelo' => 'cu999999', 'publico' => 'leads']);
            self::fail('devia recusar');
        } catch (ErroDaFerramenta $erro) {
            self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $erro->motivo);
        }
        self::assertSame(0, $this->contarPropostas());

        $recorrente = $this->executar(['acao' => 'criar', 'modelo' => 'convite', 'publico' => 'leads', 'frequencia' => 'mensal']);
        self::assertStringContainsString('mensal, a partir de agora', $recorrente->dados['proposta']['resumo']);
        $this->confirmar($recorrente);
        self::assertSame('recorrente', $this->conexao()->fetchOne('SELECT estado FROM comercial_campanha'));
    }

    #[TestDox('reconferir: modelo mudado entre propor e confirmar recusa e não cria campanha; o mesmo vale para quem perdeu o papel de Master')]
    public function testCriarReconferir(): void
    {
        $this->semearLead();
        $id = $this->inserirModelo('Meu modelo');
        $proposta = $this->executar(['acao' => 'criar', 'modelo' => 'cu' . $id, 'publico' => 'leads']);
        $this->conexao()->executeStatement("UPDATE comercial_modelo SET assunto = 'Mudou por fora' WHERE id = ?", [$id]);

        self::assertSame(CampanhasDeEmailFerramenta::MUDOU, $this->recusaDaConfirmacao($proposta)->getMessage());
        self::assertSame(0, $this->contar('comercial_campanha'));

        $outra = $this->executar(['acao' => 'criar', 'modelo' => 'convite', 'publico' => 'leads']);
        $this->tirarOPapelDeMaster();
        self::assertSame(CampanhasDeEmailFerramenta::MOTIVO_SO_DO_MASTER, $this->recusaDaConfirmacao($outra)->motivo);
        self::assertSame(0, $this->contar('comercial_campanha'));
    }

    #[TestDox('reconferir: entrou destinatário depois da proposta, o total mudou e a confirmação recusa com a frase do público, sem criar campanha')]
    public function testCriarPublicoMudou(): void
    {
        $this->semearLead();
        $proposta = $this->executar(['acao' => 'criar', 'modelo' => 'convite', 'publico' => 'leads']);
        $agora = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->conexao()->executeStatement("INSERT INTO comercial_confirmacao_endereco (lista, email, pedido_em, confirmado_em) VALUES ('comercial_campanhas', ?, ?, ?)", ['outro_' . uniqid() . '@exemplo.com', $agora, $agora]);

        $erro = $this->recusaDaConfirmacao($proposta);

        self::assertSame('O público mudou de 1 para 2 destinatários. Peça de novo para ver o resumo atualizado.', $erro->getMessage());
        self::assertSame(0, $this->contar('comercial_campanha'));
    }

    #[TestDox('idempotência: executarConfirmada duas vezes com os mesmos dados (a mesma chave) gera uma campanha só')]
    public function testCriarIdempotente(): void
    {
        $this->semearLead();
        $dados = ['modelo' => 'convite', 'publico' => 'leads', 'total' => 1, 'frequencia' => 'unica', 'agendar' => '', 'chave' => bin2hex(random_bytes(16))];
        static::getContainer()->get('services_resetter')->reset();
        $ferramenta = static::getContainer()->get(CampanhasDeEmailFerramenta::class);
        $chamada = $this->chamada();

        $ferramenta->executarConfirmada('criar', $dados, $chamada);
        $ferramenta->executarConfirmada('criar', $dados, $chamada);

        self::assertSame(1, $this->contar('comercial_campanha'));
    }

    #[TestDox('executarConfirmada também confere o Master, mesmo sem passar pelo reconferir')]
    public function testConfirmadaSemMaster(): void
    {
        $comum = $this->pessoa(['ROLE_USER', 'ROLE_ADMIN']);
        $this->vinculo($comum, $this->escritorio);
        static::getContainer()->get('services_resetter')->reset();

        try {
            static::getContainer()->get(CampanhasDeEmailFerramenta::class)->executarConfirmada('cancelar', ['campanha_id' => 1, 'antes' => 'x'], $this->chamada($comum));
            self::fail('deveria recusar quem não é Master');
        } catch (ErroDaFerramenta $e) {
            self::assertSame(CampanhasDeEmailFerramenta::MOTIVO_SO_DO_MASTER, $e->motivo);
        }
    }

    #[TestDox('editar muda a agenda por proposta; só grava confirmada, deixa a trilha camp_edit com conector_mcp; pedir o que já está dá nada a fazer')]
    public function testEditarAgenda(): void
    {
        $id = $this->campanhaAgendada();
        $nova = (new \DateTimeImmutable('+5 days'))->format('Y-m-d\TH:i');

        $proposta = $this->executar(['acao' => 'editar', 'campanha_id' => $id, 'frequencia' => 'semanal', 'agendar' => $nova]);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertStringContainsString('de envio único em', $proposta->dados['proposta']['resumo']);
        self::assertStringContainsString('para semanal, a partir de ' . (new \DateTimeImmutable($nova))->format('d/m/Y H:i'), $proposta->dados['proposta']['resumo']);
        self::assertSame('unica', $this->campanha($id)['frequencia'], 'propor não grava');

        $this->confirmar($proposta);

        self::assertSame(['semanal', 'recorrente'], [$this->campanha($id)['frequencia'], $this->campanha($id)['estado']]);
        $this->exigirTrilha('camp_edit');
        $semMudar = $this->executar(['acao' => 'editar', 'campanha_id' => $id, 'frequencia' => 'semanal']);
        self::assertSame('nada_a_fazer', $semMudar->dados['estado']);
    }

    #[TestDox('editar com atualizar_do_modelo regrava o conteúdo com a versão atual do modelo e mostra o texto novo no resumo')]
    public function testEditarAtualizandoDoModelo(): void
    {
        $this->semearLead();
        $modelo = $this->inserirModelo('Para campanha');
        $this->confirmar($this->executar(['acao' => 'criar', 'modelo' => 'cu' . $modelo, 'publico' => 'leads', 'agendar' => (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i')]));
        $id = (int) $this->conexao()->fetchOne('SELECT MAX(id) FROM comercial_campanha');
        $this->conexao()->executeStatement("UPDATE comercial_modelo SET assunto = 'Assunto renovado' WHERE id = ?", [$modelo]);

        $proposta = $this->executar(['acao' => 'editar', 'campanha_id' => $id, 'atualizar_do_modelo' => true]);

        self::assertStringContainsString('Assunto novo: Assunto renovado', $proposta->dados['proposta']['resumo']);
        self::assertSame('Assunto original', $this->campanha($id)['assunto']);
        $this->confirmar($proposta);
        self::assertSame('Assunto renovado', $this->campanha($id)['assunto']);
    }

    #[TestDox('editar recusa campanha que já começou a sair e campanha que não existe; mudou entre propor e confirmar recusa')]
    public function testEditarRecusas(): void
    {
        $id = $this->campanhaAgendada();
        $proposta = $this->executar(['acao' => 'editar', 'campanha_id' => $id, 'frequencia' => 'mensal']);
        $this->conexao()->executeStatement("UPDATE comercial_campanha SET frequencia = 'quinzenal' WHERE id = ?", [$id]);
        self::assertSame(CampanhasDeEmailFerramenta::MUDOU, $this->recusaDaConfirmacao($proposta)->getMessage());

        $this->conexao()->executeStatement("UPDATE comercial_campanha SET estado = 'enviando', execucoes = 1 WHERE id = ?", [$id]);
        $this->assertRecusa(['acao' => 'editar', 'campanha_id' => $id, 'frequencia' => 'mensal'], 'Só dá para editar antes de começar a sair: esta campanha já começou.');
        try {
            $this->executar(['acao' => 'editar', 'campanha_id' => 999999, 'frequencia' => 'mensal']);
            self::fail('devia recusar');
        } catch (ErroDaFerramenta $erro) {
            self::assertSame(ErroDaFerramenta::MOTIVO_NAO_ENCONTRADO, $erro->motivo);
        }
    }

    #[TestDox('cancelar vira proposta, só encerra depois do sim e deixa a trilha camp_fim com conector_mcp; campanha já encerrada é recusada')]
    public function testCancelar(): void
    {
        $id = $this->campanhaAgendada();
        $proposta = $this->executar(['acao' => 'cancelar', 'campanha_id' => $id]);

        self::assertSame('aguardando_confirmacao', $proposta->dados['estado']);
        self::assertStringContainsString('não dá para desfazer', $proposta->dados['proposta']['resumo']);
        self::assertSame('agendada', $this->campanha($id)['estado'], 'propor não cancela');

        $this->confirmar($proposta);

        self::assertSame('cancelada', $this->campanha($id)['estado']);
        $this->exigirTrilha('camp_fim');
        $this->assertRecusa(['acao' => 'cancelar', 'campanha_id' => $id], 'Campanha não encontrada ou já encerrada.');
    }

    #[TestDox('cancelar: campanha encerrada por fora entre propor e confirmar recusa a confirmação')]
    public function testCancelarMudou(): void
    {
        $id = $this->campanhaAgendada();
        $proposta = $this->executar(['acao' => 'cancelar', 'campanha_id' => $id]);
        $this->conexao()->executeStatement("UPDATE comercial_campanha SET estado = 'cancelada' WHERE id = ?", [$id]);

        $erro = $this->recusaDaConfirmacao($proposta);

        self::assertSame(ErroDaFerramenta::MOTIVO_RECUSADA, $erro->motivo);
    }

    #[TestDox('enviar_teste é direto: envia na hora só ao próprio Master, sem proposta, e a resposta não traz e-mail; o registro é a linha de teste')]
    public function testEnviarTesteDireto(): void
    {
        $resposta = $this->executar(['acao' => 'enviar_teste', 'modelo' => 'convite']);

        self::assertSame('concluida', $resposta->dados['estado']);
        self::assertSame(1, $resposta->dados['enviados']);
        self::assertSame(0, $this->contarPropostas());
        self::assertSame(1, $this->contar('comercial_campanha', "estado = 'teste'"));
        self::assertDoesNotMatchRegularExpression('/[\w.+-]+@[\w-]+\.[\w.-]+/u', json_encode($resposta->dados, \JSON_THROW_ON_ERROR));
        $this->assertRecusaDeEntrada(['acao' => 'enviar_teste', 'modelo' => 'convite', 'para' => 'outro@exemplo.com']);
    }

    private function semearLead(): void
    {
        $agora = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->conexao()->executeStatement('DELETE FROM comercial_confirmacao_endereco');
        $this->conexao()->executeStatement(
            "INSERT INTO comercial_confirmacao_endereco (lista, email, pedido_em, confirmado_em) VALUES ('comercial_campanhas', ?, ?, ?)",
            ['lead_' . uniqid() . '@exemplo.com', $agora, $agora],
        );
    }

    /** Uma campanha agendada (única, daqui a 2 dias) criada pela própria ferramenta, como o Master faria. */
    private function campanhaAgendada(): int
    {
        $this->semearLead();
        $this->confirmar($this->executar(['acao' => 'criar', 'modelo' => 'convite', 'publico' => 'leads', 'agendar' => (new \DateTimeImmutable('+2 days'))->format('Y-m-d\TH:i')]));

        return (int) $this->conexao()->fetchOne('SELECT MAX(id) FROM comercial_campanha');
    }

    private function inserirModelo(string $nome): int
    {
        $agora = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $this->conexao()->executeStatement(
            'INSERT INTO comercial_modelo (nome, icone, modo, assunto, selo, titulo, conteudo, criado_em, alterado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$nome, 'bi-file-earmark', 'blocos', 'Assunto original', 'SELO', 'Título', '[{"t":"texto","a":"Olá, {nome}!","b":""}]', $agora, $agora],
        );

        return (int) $this->conexao()->lastInsertId();
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

        return static::getContainer()->get(CampanhasDeEmailFerramenta::class)->executar($argumentos, $this->chamada($pessoa));
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
    private function assertRecusaDeEntrada(array $argumentos): void
    {
        try {
            $this->executar($argumentos);
        } catch (ErroDaFerramenta $e) {
            self::assertSame(ErroDaFerramenta::MOTIVO_ENTRADA_INVALIDA, $e->motivo);

            return;
        }
        self::fail('deveria recusar a entrada');
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

    /** @return array<string, mixed> */
    private function campanha(int $id): array
    {
        $linha = $this->conexao()->fetchAssociative('SELECT * FROM comercial_campanha WHERE id = ?', [$id]);
        self::assertIsArray($linha);

        return $linha;
    }

    private function contar(string $tabela, string $onde = 'TRUE'): int
    {
        return (int) $this->conexao()->fetchOne('SELECT COUNT(*) FROM ' . $tabela . ' WHERE ' . $onde);
    }

    private function contarPropostas(): int
    {
        return $this->contar('conector_ia_proposta');
    }

    /** A última linha da campanha na trilha: a ação esperada, a rota do conector e nenhum escritório (ato de plataforma). */
    private function exigirTrilha(string $acao): void
    {
        $linha = $this->conexao()->fetchAssociative("SELECT route, tenant_id FROM audit_log WHERE entity_class LIKE '%CampanhaDeEmail' AND action = ? ORDER BY id DESC LIMIT 1", [$acao]);
        self::assertIsArray($linha, 'a gravação precisa deixar a trilha ' . $acao);
        self::assertSame('conector_mcp', $linha['route']);
        self::assertNull($linha['tenant_id']);
    }

    private function conexao(): Connection
    {
        return static::getContainer()->get(Connection::class);
    }
}
