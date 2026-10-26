<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Contribua\DTO\RetratoDoEspaco;
use App\Contribua\Entity\ConfiguracaoDoEspaco;
use App\Contribua\Entity\Plano;
use App\Contribua\Enum\FaixaDoEspaco;
use App\Contribua\Event\EspacoAtingiuFaixa;
use App\Contribua\Repository\ConfiguracaoDoEspacoRepository;
use App\Contribua\Repository\PlanoRepository;
use App\Contribua\Service\SituacaoDoEspaco;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Controller\PainelMasterDadosController;
use App\Master\Controller\PlanosELimitesController;
use App\Master\EventListener\AlertaDeConsumoNaTrilha;
use App\Master\UseCase\ConsultarPlanosUseCase;
use App\Master\UseCase\DefinirLimiteDaContaUseCase;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Factory\Cobranca\CarteiraDocumentoFactory;
use App\Tests\Factory\Cobranca\CarteiraFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Planos e limites no Painel do Master (spec docs/specs/perfis-1.2-planos-e-limites.md P1 a P7, P9 a P11).
 *
 * As regras são da Contribua; aqui se prova o lado do Master: 403 para quem não é Master, CSRF, as
 * recusas (\DomainException da Contribua) com a mensagem no JSON 422, a auditoria no escritório certo
 * e os dados que o painel lê. Armazém de CSRF fixo ('TOKEN_<id>'), como nos outros testes do painel.
 */
#[CoversClass(PlanosELimitesController::class)]
#[CoversClass(PainelMasterDadosController::class)]
#[CoversClass(DefinirLimiteDaContaUseCase::class)]
#[CoversClass(ConsultarPlanosUseCase::class)]
#[CoversClass(AlertaDeConsumoNaTrilha::class)]
final class PlanosELimitesControllerTest extends JusPrimeWebTestCase
{
    use Factories;

    private const GB = RetratoDoEspaco::BYTES_POR_GB;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $master;
    private Tenant $contaDoMaster;
    private Tenant $alvo;
    private User $titular;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->instalarCsrfFixo();
        // D5 revogada: os planos nascem sem limite de usuários; estes testes precisam de planos com limite.
        $this->em->getConnection()->executeStatement("UPDATE contribua_plano SET limite_usuarios = CASE codigo WHEN 'free' THEN 3 WHEN 'essencial' THEN 8 WHEN 'profissional' THEN 15 ELSE NULL END");

        $this->master = $this->usuario('Farlei Master', ['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']);
        $this->contaDoMaster = $this->conta('Escritório do Master', $this->master);
        $this->vincular($this->master, $this->contaDoMaster, sistema: true);

        $this->titular = $this->usuario('Titular Comum', ['ROLE_USER']);
        $this->alvo = $this->conta('Conta Alvo', $this->titular);
        $this->vincular($this->titular, $this->alvo, sistema: true);
        $this->vincular($this->usuario('Secundária', ['ROLE_USER']), $this->alvo, sistema: false);
    }

    #[TestDox('quem não é Master recebe 403 em todas as escritas e na leitura dos planos; nada muda')]
    public function testNaoMasterRecebe403(): void
    {
        $this->entrar($this->titular, $this->alvo);
        $id = $this->alvo->getId();

        foreach ([
            ['/master/contas/' . $id . '/limite-armazenamento', ['_token' => 'TOKEN_master_conta_' . $id, 'valor' => '50', 'justificativa' => 'x']],
            ['/master/contas/' . $id . '/limite-usuarios', ['_token' => 'TOKEN_master_conta_' . $id, 'valor' => '50', 'justificativa' => 'x']],
            ['/master/planos/free', ['_token' => 'TOKEN_master_plano_free', 'nome' => 'Free', 'cotaGb' => '99', 'precoCentavos' => '0', 'descricao' => 'x', 'ativo' => '1']],
            ['/master/faixas', ['_token' => 'TOKEN_master_faixas', 'atencao' => '10', 'alerta' => '20', 'critico' => '30', 'bloqueio' => '40', 'bloqueioAtivo' => '1']],
        ] as [$url, $corpo]) {
            $this->client->request('POST', $url, $corpo);
            self::assertResponseStatusCodeSame(403, $url);
        }
        $this->client->request('GET', '/master/dados/planos');
        self::assertResponseStatusCodeSame(403);

        $this->entrar($this->master, $this->contaDoMaster);
        self::assertSame(10 * self::GB, $this->retrato()->bytesLimite);
        self::assertSame(10, $this->plano('free')->getCotaGb());
        self::assertSame(70, $this->configuracao()->getFaixaAtencao());
    }

    #[TestDox('CSRF inválido ou de outra conta: 403 com "Sessão expirada" e nada muda')]
    public function testCsrf(): void
    {
        $this->entrar($this->master, $this->contaDoMaster);
        $id = $this->alvo->getId();

        foreach ([
            ['/master/contas/' . $id . '/limite-armazenamento', ['_token' => 'TOKEN_master_conta_' . $this->contaDoMaster->getId(), 'valor' => '50', 'justificativa' => 'x']],
            ['/master/contas/' . $id . '/limite-usuarios', ['_token' => 'errado', 'valor' => '50', 'justificativa' => 'x']],
            ['/master/planos/essencial', ['_token' => 'TOKEN_master_plano_free', 'nome' => 'Essencial', 'cotaGb' => '99', 'precoCentavos' => '0', 'descricao' => 'x', 'ativo' => '1']],
            ['/master/faixas', ['_token' => 'errado', 'atencao' => '10', 'alerta' => '20', 'critico' => '30', 'bloqueio' => '40']],
        ] as [$url, $corpo]) {
            self::assertSame(['erro' => 'Sessão expirada. Recarregue a página.'], $this->postar($url, $corpo), $url);
            self::assertResponseStatusCodeSame(403);
        }

        self::assertSame(10 * self::GB, $this->retrato()->bytesLimite);
        self::assertSame(20, $this->plano('essencial')->getCotaGb());
    }

    #[TestDox('limite de armazenamento: grava no escopo da CONTA ALVO e a trilha mostra "10 GB → 25 GB · justificativa" (o Free de hoje), depois "25 GB → 30 GB"')]
    public function testLimiteDeArmazenamentoEAuditoria(): void
    {
        $this->entrar($this->master, $this->contaDoMaster);

        $resposta = $this->limite('armazenamento', ['valor' => '25', 'justificativa' => '  cliente contratou ampliação  ']);
        self::assertResponseIsSuccessful();
        self::assertSame('Limite de armazenamento atualizado.', $resposta['mensagem']);
        self::assertSame(25, $resposta['conta']['espaco']['limiteGb']);
        self::assertSame('master', $resposta['conta']['espaco']['origemDoLimite']);
        $this->limite('armazenamento', ['valor' => '30', 'justificativa' => 'nova ampliação']);
        self::assertResponseIsSuccessful();
        self::assertSame(30 * self::GB, $this->retrato()->bytesLimite);

        $linhas = $this->em->getRepository(AuditLog::class)->findBy(['entityClass' => 'App\Contribua\Entity\EspacoDoEscritorio'], ['id' => 'DESC'], 2);
        self::assertCount(2, $linhas);
        foreach ($linhas as $linha) {
            self::assertSame($this->alvo->getId(), $linha->getTenantId(), 'grava com a conta alvo, não com o escritório do Master');
        }

        $daConta = $this->trilhaDaConta($this->alvo, 'Limite de armazenamento');
        self::assertSame(['25 GB → 30 GB · nova ampliação', '10 GB → 25 GB · cliente contratou ampliação'], array_column($daConta, 'detalhe'));
        self::assertSame('Farlei Master', $daConta[0]['quem']);
        self::assertSame($this->alvo->getName(), $daConta[0]['conta']);
    }

    #[TestDox('limite de armazenamento: justificativa vazia ou só com espaços, valor vazio e abaixo do uso dão 422 com a mensagem do desenho')]
    public function testRecusasDoLimiteDeArmazenamento(): void
    {
        $carteira = CarteiraFactory::createOne(['tenant' => $this->alvo, 'cliente' => ClientePFFactory::createOne(['tenant' => $this->alvo])]);
        CarteiraDocumentoFactory::createOne(['tenant' => $this->alvo, 'carteira' => $carteira, 'tamanhoBytes' => (int) (1.5 * self::GB)]);
        $this->entrar($this->master, $this->contaDoMaster);

        foreach ([
            [['valor' => '25', 'justificativa' => '   '], 'A justificativa é obrigatória e fica na auditoria.'],
            [['valor' => '', 'justificativa' => 'motivo'], 'Informe um limite em GB maior que zero.'],
            [['valor' => '2.5', 'justificativa' => 'motivo'], 'Informe um limite em GB maior que zero.'],
            [['valor' => '1', 'justificativa' => 'motivo'], 'O novo limite é menor que o volume em uso (1,5 GB). Reduzir não apaga arquivos, por isso não é permitido abaixo do uso.'],
        ] as [$corpo, $mensagem]) {
            self::assertSame(['erro' => $mensagem], $this->limite('armazenamento', $corpo));
            self::assertResponseStatusCodeSame(422);
        }

        self::assertSame(10 * self::GB, $this->retrato()->bytesLimite, 'nada mudou');
    }

    #[TestDox('sem limite: libera espaço e usuários; o rótulo da trilha é "10 GB → sem limite"')]
    public function testSemLimite(): void
    {
        $this->entrar($this->master, $this->contaDoMaster);

        $resposta = $this->limite('armazenamento', ['valor' => '', 'semLimite' => '1', 'justificativa' => 'escritório do dono']);

        self::assertResponseIsSuccessful();
        self::assertTrue($resposta['conta']['espaco']['semLimite']);
        self::assertNull($resposta['conta']['espaco']['limiteGb']);
        self::assertNull($resposta['conta']['espaco']['limiteUsuarios']);
        self::assertTrue($this->retrato()->semLimite());
        self::assertSame(['10 GB → sem limite · escritório do dono'], array_column($this->trilhaDaConta($this->alvo, 'Limite de armazenamento'), 'detalhe'));
    }

    #[TestDox('limite de usuários: abaixo dos cadastrados e vazio dão 422; 5 grava, com "3 usuários → 5 usuários" (o Free de hoje) na trilha da conta')]
    public function testLimiteDeUsuarios(): void
    {
        $this->entrar($this->master, $this->contaDoMaster);

        self::assertSame(['erro' => 'Menor que os usuários já cadastrados.'], $this->limite('usuarios', ['valor' => '1', 'justificativa' => 'motivo']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'Informe um limite de usuários maior que zero.'], $this->limite('usuarios', ['valor' => '', 'justificativa' => 'motivo']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame(['erro' => 'A justificativa é obrigatória e fica na auditoria.'], $this->limite('usuarios', ['valor' => '5', 'justificativa' => '']));
        self::assertResponseStatusCodeSame(422);

        $resposta = $this->limite('usuarios', ['valor' => '5', 'justificativa' => 'novo estagiário']);

        self::assertResponseIsSuccessful();
        self::assertSame('Limite de usuários atualizado.', $resposta['mensagem']);
        self::assertSame(5, $resposta['conta']['espaco']['limiteUsuarios']);
        self::assertSame(['3 usuários → 5 usuários · novo estagiário'], array_column($this->trilhaDaConta($this->alvo, 'Limite de usuários'), 'detalhe'));
    }

    #[TestDox('limite: conta inexistente 404; conta em quarentena 422')]
    public function testContaInexistenteEQuarentena(): void
    {
        $this->entrar($this->master, $this->contaDoMaster);

        self::assertSame(['erro' => 'Conta não encontrada.'], $this->postar('/master/contas/99999999/limite-armazenamento', ['_token' => 'TOKEN_master_conta_99999999', 'valor' => '20', 'justificativa' => 'x']));
        self::assertResponseStatusCodeSame(404);

        $this->recarregar($this->alvo)->setExcluidoEm(new \DateTimeImmutable());
        $this->em->flush();
        self::assertSame(['erro' => 'Esta conta foi excluída. Reative a conta antes de mudar o limite.'], $this->limite('usuarios', ['valor' => '20', 'justificativa' => 'x']));
        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('editar plano: grava no escopo de plataforma e a trilha mostra "Plano alterado" com os campos')]
    public function testEditarPlano(): void
    {
        $this->entrar($this->master, $this->contaDoMaster);

        $resposta = $this->plano_('essencial', ['nome' => 'Essencial', 'cotaGb' => '25', 'precoCentavos' => '2490', 'limiteUsuarios' => '', 'descricao' => 'Advogado autônomo ou escritório pequeno', 'ativo' => '1']);

        self::assertResponseIsSuccessful();
        self::assertSame(['mensagem' => 'Plano "Essencial" atualizado.'], $resposta);
        $plano = $this->plano('essencial');
        self::assertSame([25, 2490, null], [$plano->getCotaGb(), $plano->getPrecoCentavos(), $plano->getLimiteUsuarios()]);

        $linha = $this->em->getRepository(AuditLog::class)->findOneBy(['entityClass' => Plano::class], ['id' => 'DESC']);
        self::assertNotNull($linha);
        self::assertNull($linha->getTenantId(), 'ato de plataforma: sem escritório');
        $registro = $this->registroDaTrilha(static fn (array $r): bool => $r['acao'] === 'Plano alterado');
        self::assertSame('espaço: 20 GB → 25 GB; preço: R$ 19,90 → R$ 24,90; usuários: 8 → sem limite', $registro['detalhe']);
        self::assertSame('Plataforma (sem conta)', $registro['conta']);
    }

    #[TestDox('editar plano: cada recusa da Contribua volta em 422 com a mensagem; nada muda')]
    public function testRecusasDoPlano(): void
    {
        $this->entrar($this->master, $this->contaDoMaster);
        $free = ['nome' => 'Free', 'cotaGb' => '10', 'precoCentavos' => '0', 'limiteUsuarios' => '3', 'descricao' => 'Todos os recursos do BlueJus, sem custo', 'ativo' => '1'];

        foreach ([
            ['free', ['precoCentavos' => '990'] + $free, 'O plano Free é o de toda conta nova: continua grátis e ativo.'],
            ['free', ['ativo' => '0'] + $free, 'O plano Free é o de toda conta nova: continua grátis e ativo.'],
            ['free', ['nome' => '  '] + $free, 'Informe o nome do plano.'],
            ['free', ['cotaGb' => '0'] + $free, 'O espaço do plano precisa ser maior que zero.'],
            ['essencial', ['nome' => 'Essencial', 'precoCentavos' => '-1'] + $free, 'O preço não pode ser negativo.'],
            ['free', ['limiteUsuarios' => '0'] + $free, 'O limite de usuários precisa ser maior que zero, ou vazio para sem limite.'],
            ['inexistente', $free, 'Plano não encontrado.'],
            ['free', ['cotaGb' => 'dez'] + $free, 'Informe o espaço em GB e o preço em reais.'],
        ] as [$codigo, $corpo, $mensagem]) {
            self::assertSame(['erro' => $mensagem], $this->plano_($codigo, $corpo), $mensagem);
            self::assertResponseStatusCodeSame(422);
        }

        $plano = $this->plano('free');
        self::assertSame([10, 0, 3, true], [$plano->getCotaGb(), $plano->getPrecoCentavos(), $plano->getLimiteUsuarios(), $plano->isAtivo()]);
    }

    #[TestDox('faixas: grava as quatro e a chave do bloqueio no escopo de plataforma; fora de ordem dá 422')]
    public function testFaixas(): void
    {
        $this->entrar($this->master, $this->contaDoMaster);

        self::assertSame(['erro' => 'As faixas precisam crescer em ordem, de 1% a 100%.'], $this->postar('/master/faixas', ['_token' => 'TOKEN_master_faixas', 'atencao' => '90', 'alerta' => '80', 'critico' => '95', 'bloqueio' => '100']));
        self::assertResponseStatusCodeSame(422);

        $resposta = $this->postar('/master/faixas', ['_token' => 'TOKEN_master_faixas', 'atencao' => '60', 'alerta' => '80', 'critico' => '90', 'bloqueio' => '100', 'bloqueioAtivo' => '1']);

        self::assertSame(['mensagem' => 'Faixas de alerta atualizadas.'], $resposta);
        $c = $this->configuracao();
        self::assertSame([60, 80, 90, 100, true], [$c->getFaixaAtencao(), $c->getFaixaAlerta(), $c->getFaixaCritico(), $c->getFaixaBloqueio(), $c->isBloqueioAtivo()]);
        $linha = $this->em->getRepository(AuditLog::class)->findOneBy(['entityClass' => ConfiguracaoDoEspaco::class], ['id' => 'DESC']);
        self::assertNotNull($linha);
        self::assertNull($linha->getTenantId());
        $registro = $this->registroDaTrilha(static fn (array $r): bool => $r['acao'] === 'Faixas de alerta alteradas');
        self::assertSame('Atenção: 70% → 60%; Alerta: 90% → 80%; Crítico: 95% → 90%; bloqueio de envio: desligado → ligado', $registro['detalhe']);
    }

    #[TestDox('o painel lê o catálogo (valores da D5), as faixas, a chave e os tokens; as contas trazem o espaço do RetratoDoEspaco')]
    public function testDadosDoPainel(): void
    {
        $this->entrar($this->master, $this->contaDoMaster);

        $this->client->request('GET', '/master/dados/planos');
        self::assertResponseIsSuccessful();
        $dados = json_decode((string) $this->client->getResponse()->getContent(), true);
        $planos = array_column($dados['planos'], null, 'codigo');
        self::assertSame(['free', 'essencial', 'profissional', 'avancado'], array_keys($planos));
        self::assertSame(
            [[10, 0, 3], [20, 1990, 8], [50, 3990, 15], [100, 6990, null]],
            array_map(static fn (array $p): array => [$p['cotaGb'], $p['precoCentavos'], $p['limiteUsuarios']], array_values($planos)),
        );
        self::assertIsString($planos['free']['token']);
        self::assertNotSame('', $planos['free']['token']);
        self::assertGreaterThanOrEqual(2, $planos['free']['escritorios'], 'as duas contas do teste estão no Free');
        self::assertSame(['atencao' => 70, 'alerta' => 90, 'critico' => 95, 'bloqueio' => 100], $dados['faixas']);
        self::assertFalse($dados['bloqueioAtivo']);
        self::assertNotSame('', (string) $dados['tokenFaixas']);

        $this->client->request('GET', '/master/dados/contas');
        $contas = array_column(json_decode((string) $this->client->getResponse()->getContent(), true)['contas'], null, 'id');
        $espaco = $contas[$this->alvo->getId()]['espaco'];
        self::assertSame(['free', 'Free', 10, 0, 'livre', false, 3], [$espaco['planoCodigo'], $espaco['planoNome'], $espaco['limiteGb'], $espaco['percentual'], $espaco['faixa'], $espaco['semLimite'], $espaco['limiteUsuarios']]);

        $this->client->request('GET', '/master/dados/visao-geral');
        $visao = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsInt($visao['registrosDeAuditoria']);
        self::assertGreaterThanOrEqual(20, $visao['armazenamento']['cotaGb'], 'soma dos limites das contas (as duas do teste no Free)');
        self::assertContains($visao['armazenamento']['faixa'], ['livre', 'atencao', 'alerta', 'critico', 'bloqueio']);

        // Conta sem limite sai da soma e é contada à parte (decisão H do orquestrador).
        $antes = $visao['armazenamento'];
        $this->limite('armazenamento', ['valor' => '', 'semLimite' => '1', 'justificativa' => 'escritório do dono']);
        $this->client->request('GET', '/master/dados/visao-geral');
        $depois = json_decode((string) $this->client->getResponse()->getContent(), true)['armazenamento'];
        self::assertSame($antes['cotaGb'] - 10, $depois['cotaGb']);
        self::assertSame($antes['contasSemLimite'] + 1, $depois['contasSemLimite']);
        self::assertSame($antes['bytesUsados'], $depois['bytesUsados'], 'a conta sem uso saiu da soma das contas com limite');
        self::assertSame($antes['bytesSemLimite'], $depois['bytesSemLimite'], 'o uso dela (zero) vai para o sufixo das contas sem limite');
    }

    #[TestDox('alerta de consumo (P11): subir de faixa grava a linha do Sistema na trilha da conta, "Passou de 90% do armazenamento"')]
    public function testAlertaDeConsumo(): void
    {
        $situacao = static::getContainer()->get(SituacaoDoEspaco::class);
        $retrato = $situacao->retratar((int) $this->alvo->getId(), (int) (9.2 * self::GB));
        static::getContainer()->get(EventDispatcherInterface::class)->dispatch(new EspacoAtingiuFaixa((int) $this->alvo->getId(), FaixaDoEspaco::Alerta, FaixaDoEspaco::Atencao, $retrato, 85));
        $this->entrar($this->master, $this->contaDoMaster);

        $registros = $this->trilhaDaConta($this->alvo, 'Alerta de consumo');

        self::assertCount(1, $registros);
        self::assertSame('Sistema', $registros[0]['quem']);
        self::assertSame('Passou de 90% do armazenamento', $registros[0]['detalhe']);
        self::assertSame($this->alvo->getName(), $registros[0]['conta']);
    }

    /** @param array<string, string> $corpo @return array<string, mixed> */
    private function limite(string $tipo, array $corpo): array
    {
        return $this->postar('/master/contas/' . $this->alvo->getId() . '/limite-' . $tipo, $corpo + ['_token' => 'TOKEN_master_conta_' . $this->alvo->getId()]);
    }

    /** @param array<string, string> $corpo @return array<string, mixed> */
    private function plano_(string $codigo, array $corpo): array
    {
        return $this->postar('/master/planos/' . $codigo, $corpo + ['_token' => 'TOKEN_master_plano_' . $codigo]);
    }

    /** @param array<string, string> $corpo @return array<string, mixed> */
    private function postar(string $url, array $corpo): array
    {
        $this->client->request('POST', $url, $corpo);

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /** @return list<array<string, mixed>> */
    private function trilhaDaConta(Tenant $conta, string $acao): array
    {
        $this->client->request('GET', '/master/dados/auditoria');
        self::assertResponseIsSuccessful();
        $registros = json_decode((string) $this->client->getResponse()->getContent(), true)['registros'];

        return array_values(array_filter($registros, static fn (array $r): bool => $r['contaId'] === $conta->getId() && $r['acao'] === $acao));
    }

    /** @return array<string, mixed> */
    private function registroDaTrilha(callable $filtro): array
    {
        $this->client->request('GET', '/master/dados/auditoria');
        $registros = array_values(array_filter(json_decode((string) $this->client->getResponse()->getContent(), true)['registros'], $filtro));
        self::assertNotSame([], $registros);

        return $registros[0];
    }

    private function retrato(): RetratoDoEspaco
    {
        $this->em->clear();

        return static::getContainer()->get(SituacaoDoEspaco::class)->doEscritorio((int) $this->alvo->getId());
    }

    private function plano(string $codigo): Plano
    {
        $this->em->clear();
        $plano = static::getContainer()->get(PlanoRepository::class)->findOneBy(['codigo' => $codigo]);
        self::assertInstanceOf(Plano::class, $plano);

        return $plano;
    }

    private function configuracao(): ConfiguracaoDoEspaco
    {
        $this->em->clear();

        return static::getContainer()->get(ConfiguracaoDoEspacoRepository::class)->atual();
    }

    private function entrar(User $user, Tenant $tenant): void
    {
        $this->logarComTenant($this->client, $user, $tenant);
    }

    /** @param list<string> $papeis */
    private function usuario(string $nome, array $papeis): User
    {
        return UserFactory::createOne(['fullName' => $nome, 'roles' => $papeis, 'isActive' => true])->_real();
    }

    private function conta(string $nome, User $criador): Tenant
    {
        $conta = (new Tenant())->setName($nome . ' ' . uniqid())->setCriadoPor($criador);
        $this->em->persist($conta);
        $this->em->flush();

        return $conta;
    }

    private function vincular(User $user, Tenant $tenant, bool $sistema): void
    {
        $perfil = (new TenantRole())->setTenant($tenant)->setName($sistema ? 'Administrador do Escritório' : 'Advogado (a)')->setIsSystem($sistema);
        $this->em->persist($perfil);
        $this->em->persist((new UserTenant($user, $tenant))->setTenantRole($perfil));
        $this->em->flush();
    }

    private function recarregar(Tenant $conta): Tenant
    {
        $this->em->clear();
        $recarregada = $this->em->find(Tenant::class, $conta->getId());
        self::assertNotNull($recarregada);

        return $recarregada;
    }

    private function instalarCsrfFixo(): void
    {
        static::getContainer()->set('security.csrf.token_storage', new class () implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string
            {
                return 'TOKEN_' . $tokenId;
            }
            public function setToken(string $tokenId, string $token): void
            {
            }
            public function removeToken(string $tokenId): ?string
            {
                return null;
            }
            public function hasToken(string $tokenId): bool
            {
                return true;
            }
            public function clear(): void
            {
            }
        });
    }
}
