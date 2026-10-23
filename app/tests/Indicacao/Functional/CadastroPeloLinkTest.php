<?php

declare(strict_types=1);

namespace App\Tests\Indicacao\Functional;

use App\Entity\Auth\User;
use App\Auth\Event\ContaCriadaPeloCadastro;
use App\Indicacao\Controller\IndicacaoController;
use App\Indicacao\Entity\IndicacaoConfig;
use App\Indicacao\Entity\IndicacaoPausa;
use App\Indicacao\EventListener\CadastroPeloLinkListener;
use App\Indicacao\Repository\IndicacaoCodigoRepository;
use App\Indicacao\Repository\IndicacaoConfigRepository;
use App\Indicacao\Repository\IndicacaoRepository;
use App\Indicacao\UseCase\AtribuirCadastroPeloLinkUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Quem chega pelo link curto `/i/{codigo}` e cria a conta pelo "Criar minha conta" fica ligado a quem indicou
 * (spec indicar-bluejus.md, comportamento 7). DAMA desfaz no fim de cada teste.
 */
#[CoversClass(CadastroPeloLinkListener::class)]
#[CoversClass(AtribuirCadastroPeloLinkUseCase::class)]
#[CoversClass(IndicacaoRepository::class)]
final class CadastroPeloLinkTest extends JusPrimeWebTestCase
{
    private const CSRF = 'token-de-teste-do-cadastro';
    private const CODIGO = 'QWER2345';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $conexao;
    private User $ana;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->conexao = $this->em->getConnection();
        $this->ana = new User();
        $this->ana->setEmail(uniqid('ind_') . '@exemplo.com');
        $this->ana->setFullName('Ana Souza Lima');
        $this->ana->setRoles(['ROLE_USER']);
        $this->ana->setPassword('x');
        $this->em->persist($this->ana);
        $this->em->flush();
        $this->conexao->executeStatement('INSERT INTO indicacao_codigo (user_id, codigo) VALUES (?, ?)', [$this->ana->getId(), self::CODIGO]);
        $sessao = $this->client->getSession();
        $sessao->set('_csrf/cadastro_criar', self::CSRF);
        $sessao->save();
    }

    /** O POST do cadastro do desenho 10; devolve o id da conta nova. */
    private function cadastrar(): int
    {
        $email = 'nova_' . uniqid() . '@adv.com';
        $this->client->jsonRequest('POST', '/cadastro/criar', [
            'nome' => 'Nova Pessoa',
            'escritorio' => 'Pessoa Advogados ' . uniqid(),
            'email' => $email,
            'genero' => 'feminino',
            'aceite' => true,
            '_token' => self::CSRF,
        ]);
        self::assertResponseIsSuccessful();

        return (int) $this->conexao->fetchOne('SELECT id FROM "user" WHERE email = ?', [$email]);
    }

    /** @return list<array<string, mixed>> */
    private function atribuicoesDe(int $contaNovaId): array
    {
        return $this->conexao->fetchAllAssociative('SELECT * FROM indicacao WHERE indicado_user_id = ?', [$contaNovaId]);
    }

    private function codigoNaSessao(): bool
    {
        return $this->client->getRequest()->getSession()->has(IndicacaoController::CHAVE_DA_SESSAO);
    }

    #[TestDox('GET /i/{codigo} e o cadastro: liga a conta nova a quem indicou (canal link, sem e-mail) e tira o código da sessão')]
    public function testLinkCurtoLigaAContaNova(): void
    {
        $this->client->request('GET', '/i/' . strtolower(self::CODIGO));
        self::assertResponseRedirects('/cadastro');

        $nova = $this->cadastrar();

        $atribuicoes = $this->atribuicoesDe($nova);
        self::assertCount(1, $atribuicoes);
        self::assertSame($this->ana->getId(), (int) $atribuicoes[0]['indicado_por_id']);
        self::assertSame('link', $atribuicoes[0]['canal']);
        self::assertNull($atribuicoes[0]['email']);
        self::assertNull($atribuicoes[0]['nome']);
        self::assertFalse($this->codigoNaSessao(), 'o código sai da sessão depois de usado');
    }

    #[TestDox('sem passar pelo link: o cadastro não liga nada')]
    public function testSemCodigoNadaMuda(): void
    {
        $antes = (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM indicacao');

        $nova = $this->cadastrar();

        self::assertSame([], $this->atribuicoesDe($nova));
        self::assertSame($antes, (int) $this->conexao->fetchOne('SELECT COUNT(*) FROM indicacao'));
    }

    #[TestDox('código inexistente: o cadastro passa igual, nada é ligado e a chave sai da sessão')]
    public function testCodigoInvalidoNaoDerrubaOCadastro(): void
    {
        $this->client->request('GET', '/i/NAOEXISTE');

        $nova = $this->cadastrar();

        self::assertGreaterThan(0, $nova);
        self::assertSame([], $this->atribuicoesDe($nova));
        self::assertFalse($this->codigoNaSessao());
    }

    #[TestDox('dono do código inativo: o cadastro passa, nada é ligado e a chave sai da sessão')]
    public function testDonoInativoNaoLiga(): void
    {
        $this->client->request('GET', '/i/' . self::CODIGO);
        $this->conexao->executeStatement('UPDATE "user" SET is_active = false WHERE id = ?', [$this->ana->getId()]);

        $nova = $this->cadastrar();

        self::assertSame([], $this->atribuicoesDe($nova));
        self::assertFalse($this->codigoNaSessao());
    }

    #[TestDox('autoindicação: o evento do cadastro com o código da própria conta não liga nada e tira a chave da sessão')]
    public function testAutoindicacaoNaoLiga(): void
    {
        // Pelo HTTP a conta nova nunca tem código antes de nascer: o evento é disparado direto, com a sessão no request.
        $sessao = new Session(new MockArraySessionStorage());
        $sessao->set(IndicacaoController::CHAVE_DA_SESSAO, self::CODIGO);
        $request = new Request();
        $request->setSession($sessao);
        $pilha = static::getContainer()->get(RequestStack::class);
        $pilha->push($request);

        try {
            static::getContainer()->get(EventDispatcherInterface::class)->dispatch(new ContaCriadaPeloCadastro((int) $this->ana->getId(), 0));
        } finally {
            $pilha->pop();
        }

        self::assertSame([], $this->atribuicoesDe((int) $this->ana->getId()));
        self::assertFalse($sessao->has(IndicacaoController::CHAVE_DA_SESSAO));
    }

    #[TestDox('falha ao ligar (repositório lança): o cadastro responde sucesso, a conta nasce e a chave sai da sessão')]
    public function testFalhaNaIndicacaoNaoDerrubaOCadastro(): void
    {
        // Código direto na sessão, sem requisição antes: o dublê posto no container sobrevive até o POST.
        $sessao = $this->client->getSession();
        $sessao->set(IndicacaoController::CHAVE_DA_SESSAO, self::CODIGO);
        $sessao->save();
        $quebrado = $this->createStub(IndicacaoCodigoRepository::class);
        $quebrado->method('donoDoCodigo')->willThrowException(new \RuntimeException('banco fora do ar'));
        static::getContainer()->set(IndicacaoCodigoRepository::class, $quebrado);

        $nova = $this->cadastrar();

        self::assertGreaterThan(0, $nova, 'a conta nasceu');
        self::assertSame([], $this->atribuicoesDe($nova));
        self::assertFalse($this->codigoNaSessao());
    }

    #[TestDox('quem indicou está pausado (e a pausa global ligada): a atribuição vale, porque não é envio')]
    public function testPausaNaoImpede(): void
    {
        $this->em->persist(new IndicacaoPausa($this->ana, null, new \DateTimeImmutable()));
        $this->em->flush();
        $config = static::getContainer()->get(IndicacaoConfigRepository::class)->obter();
        self::assertInstanceOf(IndicacaoConfig::class, $config);
        $config->pausarParaTodos(true, $this->ana, new \DateTimeImmutable());
        $this->em->flush();
        $this->client->request('GET', '/i/' . self::CODIGO);

        $nova = $this->cadastrar();

        self::assertCount(1, $this->atribuicoesDe($nova));
    }

    #[TestDox('a conta que veio pelo link conta em "Suas indicações" e no painel, mas não nos limites nem nos envios')]
    public function testContaNoPainelENaJanela(): void
    {
        $repo = static::getContainer()->get(IndicacaoRepository::class);
        $agora = new \DateTimeImmutable();
        $antes = $repo->totais($agora->modify('-24 hours'));
        $this->client->request('GET', '/i/' . self::CODIGO);

        $this->cadastrar();

        $anaId = (int) $this->ana->getId();
        self::assertSame(0, $repo->contarDoUsuarioDesde($anaId, $agora->modify('-24 hours')), 'atribuir não conta no limite nem no alerta');
        $totais = $repo->totais($agora->modify('-24 hours'));
        self::assertSame(1, $totais['viraramConta'] - $antes['viraramConta']);
        self::assertSame(0, $totais['enviadas'] - $antes['enviadas']);
        self::assertSame(0, $totais['ultimas24h'] - $antes['ultimas24h']);
        $linha = array_values(array_filter(
            $repo->indicadores($agora->modify('-24 hours'), $agora->modify('-30 days'), 0, 1000),
            static fn (array $l): bool => $l['user_id'] === $anaId,
        ));
        self::assertCount(1, $linha);
        self::assertSame([0, 0, 0, 1], [$linha[0]['total'], $linha[0]['ultimas24h'], $linha[0]['ultimos30d'], $linha[0]['contas_criadas']]);

        // "Suas indicações" de quem indicou: a linha do link, sem dado da pessoa nova, com "CONTA CRIADA".
        $this->client->getCookieJar()->clear();
        $this->client->loginUser($this->ana);
        $this->marcarTermosAceitos($this->client);
        $this->client->request('GET', '/indicacao');
        self::assertResponseIsSuccessful();
        $janela = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $janela['total']);
        self::assertSame(['nome' => null, 'email' => null, 'canal' => 'link', 'contaCriada' => true], array_intersect_key($janela['ultimas'][0], array_flip(['nome', 'email', 'canal', 'contaCriada'])));
    }
}
