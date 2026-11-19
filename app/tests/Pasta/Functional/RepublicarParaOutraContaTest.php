<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Notificacao;
use App\Entity\Tenant\Tenant;
use App\Pasta\Controller\PastasRecebidasController;
use App\Pasta\Entity\EstadoDoCompartilhamento;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaCompartilhamento;
use App\Pasta\Entity\PermissoesDoCompartilhamento;
use App\Pasta\Exception\CompartilhamentoRecusadoException;
use App\Pasta\Repository\PastaCompartilhamentoRepository;
use App\Pasta\UseCase\RepublicarPastaParaOutraContaUseCase;
use App\Tenant\Service\GeradorDeCodigoDaConta;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\DBAL\Types\Types;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Fatia 8 da spec `docs/specs/acesso-restrito-e-compartilhar.md` (§4.5): republicar a pasta para outra conta pelo código
 * pessoal e a caixa "Recebidas" (aceitar, recusar, deixar). O convite aceito ainda não abre nada no escritório da pasta
 * (fatia 9): o escopo e as permissões de lá só olham o compartilhamento interno.
 */
#[CoversClass(RepublicarPastaParaOutraContaUseCase::class)]
#[CoversClass(PastasRecebidasController::class)]
final class RepublicarParaOutraContaTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES = ['modules.expediente.view', 'modules.pastas.view', 'resources.pasta.view', 'resources.pasta.edit'];

    private Tenant $contaA;
    private Tenant $contaB;
    private User $donoA;
    private User $bia;
    private Pasta $pasta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
        [$this->donoA, $this->contaA] = $this->criarEscritorio();
        [, $this->contaB] = $this->criarEscritorio();
        $this->bia = $this->criarColaborador($this->contaB, 'Bia Parceira', self::PERMISSOES);
        $this->pasta = $this->criarPasta($this->contaA, 'CLIENTE DA CONTA A');
        $this->pasta->setResponsavel($this->donoA);
        $this->em()->flush();
    }

    #[TestDox('republicar pelo código, aceitar em Recebidas e deixar de colaborar; o aceite não abre a pasta no escritório de origem')]
    public function testFluxoCompleto(): void
    {
        $codigo = $this->codigoDe($this->bia);
        self::assertMatchesRegularExpression(GeradorDeCodigoDaConta::PADRAO, $codigo);
        self::assertSame($codigo, $this->recebidas()['meuCodigo'], 'o código não muda de uma vez para outra');

        $this->entrar($this->donoA, $this->contaA);
        $resposta = $this->republicar(['codigo' => ' ' . strtolower($codigo) . ' ', 'movimentar' => true, 'mensagem' => 'acompanhe os prazos']);
        self::assertResponseIsSuccessful();
        self::assertSame('Pasta republicada. Bia Parceira recebe o convite e passa a colaborar quando aceitar.', $resposta['mensagem']);
        $linha = $this->janela()['colaboradores'][0];
        self::assertSame('aguardando', $linha['estado']);
        self::assertFalse($linha['interno']);
        self::assertSame($this->contaB->getName(), $linha['conta']);
        self::assertTrue($linha['podeEncerrar']);

        $aviso = $this->avisoDaBia();
        self::assertNotNull($aviso);
        self::assertSame($this->contaB->getId(), $aviso->getTenant()?->getId(), 'o aviso vai para o escritório do destino');
        self::assertStringNotContainsString((string) $this->pasta->getNup(), (string) $aviso->getTitulo() . $aviso->getMensagem());
        self::assertStringNotContainsString((string) $this->contaA->getName(), (string) $aviso->getTitulo() . $aviso->getMensagem());

        $this->entrar($this->bia, $this->contaB);
        $caixa = $this->recebidas();
        self::assertSame(1, $caixa['pendentes']);
        self::assertCount(1, $caixa['itens']);
        $item = $caixa['itens'][0];
        self::assertSame(['aguardando', (string) $this->pasta->getNup(), 'CLIENTE DA CONTA A', $this->contaA->getName(), 'acompanhe os prazos', true, false], [$item['estado'], $item['nup'], $item['cliente'], $item['conta'], $item['mensagem'], $item['movimentar'], $item['baixarDocumentos']]);

        $this->responder($item['id'], 'aceitar', $caixa['token']);
        self::assertResponseIsSuccessful();
        $caixa = $this->recebidas();
        self::assertSame(0, $caixa['pendentes']);
        self::assertSame('colaborando', $caixa['itens'][0]['estado']);

        $repo = static::getContainer()->get(PastaCompartilhamentoRepository::class);
        self::assertNull($repo->ativoPara((int) $this->pasta->getId(), $this->bia, new \DateTimeImmutable()), 'outra conta não vale como compartilhamento interno');
        $this->client->request('GET', '/pasta/' . $this->pasta->getId());
        self::assertNotSame(200, $this->client->getResponse()->getStatusCode(), 'a pasta de outra conta não abre pelas rotas do escritório');

        $this->responder($item['id'], 'deixar', $caixa['token']);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->recebidas()['itens'], 'o encerrado sai da caixa, como no desenho');
    }

    #[TestDox('o aviso no sino da outra conta entra na trilha dela sem quem agiu na conta de origem')]
    public function testAuditoriaDoAvisoSemOAtorDaOrigem(): void
    {
        $codigo = $this->codigoDe($this->bia);
        $this->entrar($this->donoA, $this->contaA);
        $this->republicar(['codigo' => $codigo]);
        self::assertResponseIsSuccessful();

        $aviso = $this->avisoDaBia();
        self::assertNotNull($aviso);
        $trilha = $this->em()->getRepository(AuditLog::class)->findBy(['entityClass' => Notificacao::class, 'tenantId' => $this->contaB->getId(), 'action' => 'create']);
        self::assertNotEmpty($trilha);
        foreach ($trilha as $registro) {
            self::assertSame($this->contaB->getId(), $registro->getTenantId());
            self::assertSame([null, null, null, null], [$registro->getActorUserId(), $registro->getActorEmail(), $registro->getIpAddress(), $registro->getRoute()]);
        }
    }

    #[TestDox('convite vencido dá lugar a um novo pelo mesmo código')]
    public function testReemiteDepoisDeVencido(): void
    {
        $codigo = $this->codigoDe($this->bia);
        $vencido = $this->gravarConvite($this->bia, validoAte: new \DateTimeImmutable('yesterday'));

        $this->entrar($this->donoA, $this->contaA);
        $this->republicar(['codigo' => $codigo]);
        self::assertResponseIsSuccessful();

        $this->em()->clear();
        $antigo = $this->em()->find(PastaCompartilhamento::class, $vencido);
        self::assertSame(EstadoDoCompartilhamento::Encerrada, $antigo?->getStatus());
    }

    #[TestDox('a caixa Recebidas só traz convite de outra conta e religa o filtro do escritório com o mesmo valor')]
    public function testRecebidasSoDeOutraContaEFiltroReligado(): void
    {
        $daEquipe = $this->criarPasta($this->contaB, 'CLIENTE DA CONTA B');
        $this->em()->persist(PastaCompartilhamento::interno($daEquipe, $this->criarColaborador($this->contaB, 'Dono B', self::PERMISSOES), $this->bia, new PermissoesDoCompartilhamento(), null, null, new \DateTimeImmutable('-1 day')));
        $this->em()->flush();
        $this->gravarConvite($this->bia);

        $filtros = $this->em()->getFilters();
        $filtros->enable('tenant')->setParameter('tenant', (int) $this->contaB->getId(), Types::INTEGER);
        $repo = static::getContainer()->get(PastaCompartilhamentoRepository::class);
        $recebidas = $repo->recebidasPor($this->bia);

        self::assertCount(1, $recebidas);
        self::assertFalse($recebidas[0]->isInterno());
        self::assertTrue($filtros->isEnabled('tenant'));
        self::assertSame((string) $this->contaB->getId(), trim((string) $filtros->getFilter('tenant')->getParameter('tenant'), "'"));
        $filtros->disable('tenant');
    }

    #[TestDox('recusas do código: formato, inexistente, o próprio, de alguém da equipe e convite repetido; o restrito não republica')]
    public function testRecusasDoCodigo(): void
    {
        $colega = $this->criarColaborador($this->contaA, 'Caio Colega', self::PERMISSOES);
        $codigoDoColega = $this->codigoDe($colega, $this->contaA);
        $codigoDaBia = $this->codigoDe($this->bia);
        $meuCodigo = $this->codigoDe($this->donoA, $this->contaA);

        $this->entrar($this->donoA, $this->contaA);
        self::assertSame(CompartilhamentoRecusadoException::CODIGO_INVALIDO, $this->republicar(['codigo' => 'BJ-1234'])['erro']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(CompartilhamentoRecusadoException::CODIGO_NAO_ENCONTRADO, $this->republicar(['codigo' => 'BJ-ZZZZ-ZZZZ-ZZZZ'])['erro']);
        self::assertSame(CompartilhamentoRecusadoException::CODIGO_PROPRIO, $this->republicar(['codigo' => $meuCodigo])['erro']);
        self::assertSame(CompartilhamentoRecusadoException::CODIGO_DA_EQUIPE, $this->republicar(['codigo' => $codigoDoColega])['erro']);
        $this->republicar(['codigo' => $codigoDaBia]);
        self::assertResponseIsSuccessful();
        self::assertSame(CompartilhamentoRecusadoException::JA_REPUBLICADA, $this->republicar(['codigo' => $codigoDaBia])['erro']);
        self::assertSame(CompartilhamentoRecusadoException::CODIGO_INVALIDO, $this->republicar(['destinoId' => $colega->getId()])['erro'], 'o 1 clique só vale para quem já recebeu por código');

        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $colega, 'tenant' => $this->contaA]);
        $vinculo?->getTenantRole()?->restringirAsPastasSobResponsabilidade('Parceiro');
        $pasta = $this->em()->find(Pasta::class, $this->pasta->getId());
        $donoA = $this->em()->find(User::class, $this->donoA->getId());
        $colega = $this->em()->find(User::class, $colega->getId());
        self::assertNotNull($pasta);
        self::assertNotNull($donoA);
        self::assertNotNull($colega);
        $this->em()->persist(PastaCompartilhamento::interno($pasta, $donoA, $colega, new PermissoesDoCompartilhamento(true, true, true), null, null, new \DateTimeImmutable('-1 day')));
        $this->em()->flush();
        $this->entrar($colega, $this->contaA);
        $token = $this->janela()['token'];
        $this->postar('/pasta/' . $this->pasta->getId() . '/compartilhamento', ['outraConta' => true, 'codigo' => $codigoDaBia], $token);
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('recusar some da caixa; outra pessoa não responde o convite alheio; vencido não se aceita')]
    public function testRecusarConviteAlheioEVencido(): void
    {
        $caio = $this->criarColaborador($this->contaB, 'Caio da Conta B', self::PERMISSOES);
        $id = $this->gravarConvite($this->bia);
        $vencido = $this->gravarConvite($this->bia, $this->criarPasta($this->contaA, 'OUTRO CLIENTE'), new \DateTimeImmutable('yesterday'));

        $this->entrar($caio, $this->contaB);
        $tokenDoCaio = $this->recebidas()['token'];
        $this->responder($id, 'aceitar', $tokenDoCaio);
        self::assertResponseStatusCodeSame(404);

        $this->entrar($this->bia, $this->contaB);
        $caixa = $this->recebidas();
        self::assertSame(1, $caixa['pendentes'], 'o vencido não conta');
        $estados = array_column($caixa['itens'], 'estado', 'id');
        self::assertSame('expirada', $estados[$vencido]);
        $this->responder($vencido, 'aceitar', $caixa['token']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(CompartilhamentoRecusadoException::CONVITE_INDISPONIVEL, $this->erro());

        $this->responder($id, 'recusar', $caixa['token']);
        self::assertResponseIsSuccessful();
        self::assertNotContains($id, array_column($this->recebidas()['itens'], 'id'));
        $this->responder($id, 'aceitar', $caixa['token']);
        self::assertResponseStatusCodeSame(422, 'o recusado não volta a aguardar');
    }

    #[TestDox('o 1 clique oferece quem já recebeu por código, com a conta, e manda convite novo sem o código')]
    public function testUmCliqueParaOutraConta(): void
    {
        $outra = $this->criarPasta($this->contaA, 'PASTA ANTERIOR');
        $outra->setResponsavel($this->donoA);
        $this->em()->flush();
        $this->gravarConvite($this->bia, $outra);

        $this->entrar($this->donoA, $this->contaA);
        $recentes = $this->janela()['recentes'];
        self::assertSame([['id' => $this->bia->getId(), 'nome' => 'Bia Parceira', 'interno' => false, 'conta' => $this->contaB->getName()]], $recentes);

        $this->republicar(['destinoId' => $this->bia->getId()]);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->janela()['recentes'], 'quem tem convite esperando sai do 1 clique');
    }

    #[TestDox('quem recusou não volta ao 1 clique nem recebe convite novo por ele')]
    public function testQuemRecusouSaiDoUmClique(): void
    {
        $outra = $this->criarPasta($this->contaA, 'PASTA ANTERIOR');
        $outra->setResponsavel($this->donoA);
        $this->em()->flush();
        $id = $this->gravarConvite($this->bia, $outra);
        $this->entrar($this->bia, $this->contaB);
        $this->responder($id, 'recusar', $this->recebidas()['token']);
        self::assertResponseIsSuccessful();

        $this->entrar($this->donoA, $this->contaA);
        self::assertSame([], $this->janela()['recentes']);
        self::assertSame(CompartilhamentoRecusadoException::CODIGO_INVALIDO, $this->republicar(['destinoId' => $this->bia->getId()])['erro']);
    }

    #[TestDox('o acesso restrito alcança a caixa Recebidas (é como o parceiro recebe pasta de outra conta)')]
    public function testRestritoVeRecebidas(): void
    {
        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $this->bia, 'tenant' => $this->contaB]);
        $vinculo?->getTenantRole()?->restringirAsPastasSobResponsabilidade('Parceiro');
        $this->em()->flush();
        $this->gravarConvite($this->bia);

        $this->entrar($this->bia, $this->contaB);
        $caixa = $this->recebidas();
        self::assertSame(1, $caixa['pendentes']);
        $this->responder($caixa['itens'][0]['id'], 'aceitar', $caixa['token']);
        self::assertResponseIsSuccessful();
    }

    /** O aviso fica no escritório da Bia (conta B); o filtro do escritório da última requisição ainda está ligado. */
    private function avisoDaBia(): ?Notificacao
    {
        if ($this->em()->getFilters()->isEnabled('tenant')) {
            $this->em()->getFilters()->disable('tenant');
        }

        return $this->em()->getRepository(Notificacao::class)->findOneBy(['usuario' => $this->bia->getId(), 'tipo' => Notificacao::TIPO_PASTA_RECEBIDA]);
    }

    private function codigoDe(User $pessoa, ?Tenant $tenant = null): string
    {
        $this->entrar($pessoa, $tenant ?? $this->contaB);

        return (string) $this->recebidas()['meuCodigo'];
    }

    private function gravarConvite(User $destino, ?Pasta $pasta = null, ?\DateTimeImmutable $validoAte = null): int
    {
        $c = PastaCompartilhamento::externo($pasta ?? $this->pasta, $this->donoA, $destino, new PermissoesDoCompartilhamento(), null, null, new \DateTimeImmutable('-2 days'));
        if ($validoAte !== null) {
            (new \ReflectionProperty(PastaCompartilhamento::class, 'validoAte'))->setValue($c, $validoAte);
        }
        $this->em()->persist($c);
        $this->em()->flush();

        return (int) $c->getId();
    }

    /** @return array<string, mixed> */
    private function recebidas(): array
    {
        $this->client->request('GET', '/expediente/recebidas');
        self::assertResponseIsSuccessful();

        return json_decode($this->conteudo(), true, flags: JSON_THROW_ON_ERROR);
    }

    private function responder(int $id, string $acao, string $token): void
    {
        $this->postar('/expediente/recebidas/' . $id . '/' . $acao, [], $token);
    }

    /** @return array<string, mixed> */
    private function janela(): array
    {
        $this->client->request('GET', '/pasta/' . $this->pasta->getId() . '/compartilhamento');
        self::assertResponseIsSuccessful();

        return json_decode($this->conteudo(), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $dados
     *
     * @return array<string, mixed>
     */
    private function republicar(array $dados): array
    {
        $token = $this->janela()['token'];
        $this->postar('/pasta/' . $this->pasta->getId() . '/compartilhamento', ['outraConta' => true] + $dados, $token);

        return json_decode($this->conteudo(), true, flags: JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $dados */
    private function postar(string $url, array $dados, string $token): void
    {
        $this->client->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token, 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], content: json_encode($dados, JSON_THROW_ON_ERROR));
    }

    private function erro(): ?string
    {
        $json = json_decode($this->conteudo(), true);

        return is_array($json) && is_string($json['erro'] ?? null) ? $json['erro'] : null;
    }

    private function conteudo(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }
}
