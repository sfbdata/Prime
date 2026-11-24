<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Comercial\Envio\CarteiroInterface;
use App\Comercial\Envio\MensagemDeEmail;
use App\Comercial\Envio\ResultadoDoEnvio;
use App\Entity\Audit\AuditLog;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Controller\ConveniosController;
use App\Master\DTO\DadosDoConvenio;
use App\Master\Entity\Convenio;
use App\Master\Entity\ConvenioVinculo;
use App\Master\Enum\AbrangenciaDoConvenio;
use App\Master\Enum\BeneficioDoConvenio;
use App\Master\Enum\MotivoDoFimDoVinculo;
use App\Master\Enum\OrigemDoVinculo;
use App\Master\Service\AvisoDeConvenio;
use App\Master\Service\FimDoConvenio;
use App\Master\UseCase\ConsultarConveniosUseCase;
use App\Master\UseCase\DesligarConvenioUseCase;
use App\Master\UseCase\SalvarConvenioUseCase;
use App\Master\UseCase\SincronizarVinculosDeConvenioUseCase;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * Lista de convênios do Master (spec docs/specs/convenios.md, §6.2 e §7, PR 4): acesso só do Master, criar e editar com
 * código de convite, desligar com aviso (30 dias com benefício real, na hora só gratuidade), cancelar o fim, desligar
 * por instituição numa transação, falha ao enfileirar desfaz, Trilha no escritório e o fim no dia pela tarefa diária.
 */
#[CoversClass(ConveniosController::class)]
#[CoversClass(ConsultarConveniosUseCase::class)]
#[CoversClass(SalvarConvenioUseCase::class)]
#[CoversClass(DesligarConvenioUseCase::class)]
#[CoversClass(FimDoConvenio::class)]
#[CoversClass(AvisoDeConvenio::class)]
final class ConveniosControllerTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_' . ConveniosController::CSRF;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private \DateTimeImmutable $hoje;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->hoje = \DateTimeImmutable::createFromInterface(static::getContainer()->get(ClockInterface::class)->now())->setTime(0, 0);
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

    #[TestDox('Master lê a lista (OAB primeiro, contas e e-mails); outros 403; CSRF errado 403')]
    public function testListaEAcesso(): void
    {
        $this->entrarComoMaster();
        $convenio = $this->convenio('Caixa A', 'Caixa de Assistencia', BeneficioDoConvenio::Gratuidade);
        $this->vincular($convenio, $this->escritorioComPrimario());

        $this->client->request('GET', '/master/convenios/lista');

        self::assertResponseIsSuccessful();
        $json = $this->json();
        self::assertSame(Convenio::CHAVE_OAB, $json['convenios'][0]['chave']);
        self::assertTrue($json['convenios'][0]['oab']);
        $linha = $this->linha($convenio);
        self::assertSame([1, 1, false], [$linha['contas'], $linha['emails'], $linha['beneficioReal']]);
        self::assertSame(1, $json['instituicoes']['caixa-de-assistencia']['contas']);
        self::assertSame($this->hoje->modify('+30 days')->format('Y-m-d'), $json['prazoMinimo']);

        $this->postar('/master/convenios/' . $convenio->getId() . '/ligar', ['ligado' => false, 'encerraEm' => $this->hoje->format('Y-m-d')], 'errado');
        self::assertResponseStatusCodeSame(403);

        $this->entrarComoPrimarioDeEscritorio();
        $this->client->request('GET', '/master/convenios/lista');
        self::assertResponseStatusCodeSame(403);
        $this->postar('/master/convenios/salvar', $this->pedido());
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('cria por código (chave do nome, código de 8 sem 0/O/1/I), edita, gera outro; no OAB a abrangência não muda')]
    public function testCriaEEdita(): void
    {
        $this->entrarComoMaster();

        $this->postar('/master/convenios/salvar', $this->pedido());
        self::assertResponseIsSuccessful();
        $id = $this->json()['id'];
        $criado = array_column($this->json()['convenios'], null, 'id')[$id];
        self::assertSame(['caasp', 'codigo', 'uf', 'SP', 10], [$criado['chave'], $criado['prova'], $criado['abrangencia'], $criado['uf'], $criado['beneficioValor']]);
        self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $criado['codigoConvite']);
        self::assertSame('Convênio CAASP criado.', $this->json()['mensagem']);

        $this->postar('/master/convenios/salvar', ['id' => $id, 'gerarOutroCodigo' => true, 'nome' => 'CAASP 2027'] + $this->pedido());
        self::assertResponseIsSuccessful();
        $editado = array_column($this->json()['convenios'], null, 'id')[$id];
        self::assertSame(['caasp', 'CAASP 2027'], [$editado['chave'], $editado['nome']]);
        self::assertNotSame($criado['codigoConvite'], $editado['codigoConvite']);

        $oab = $this->oab();
        $this->postar('/master/convenios/salvar', ['id' => $oab->getId(), 'nome' => 'Convênio OAB', 'instituicao' => 'OAB', 'abrangencia' => 'uf', 'uf' => 'SP', 'beneficio' => 'gratuidade', 'textoSelo' => 'CONVÊNIO OAB', 'validoDe' => '2026-10-26']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('No convênio OAB, a abrangência e o benefício não mudam por aqui.', $this->json()['erro']);
    }

    #[TestDox('gratuidade desliga na hora: convênio desligado, vínculo fechado com "convenio_desligado", um aviso')]
    public function testGratuidadeDesligaNaHora(): void
    {
        $this->entrarComoMaster();
        $convenio = $this->convenio('Caixa B', 'Caixa B', BeneficioDoConvenio::Gratuidade);
        $vinculo = $this->vincular($convenio, $tenant = $this->escritorioComPrimario());

        $this->postar('/master/convenios/' . $convenio->getId() . '/ligar', ['ligado' => false, 'encerraEm' => $this->hoje->format('Y-m-d'), 'motivo' => 'Fim da parceria']);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->json()['avisados']);
        $this->em->clear();
        self::assertFalse($this->recarregar($convenio)->isAtivo());
        $vinculo = $this->em->find(ConvenioVinculo::class, $vinculo->getId());
        self::assertSame(MotivoDoFimDoVinculo::ConvenioDesligado, $vinculo?->getMotivoFim());
        self::assertSame(1, $this->avisos($tenant));
    }

    #[TestDox('benefício real: antes de 30 dias recusa; com 30, marca o fim, avisa e grava a Trilha no escritório; religar cancela e avisa')]
    public function testBeneficioRealComAviso(): void
    {
        $this->entrarComoMaster();
        $convenio = $this->convenio('Caixa C', 'Caixa C', BeneficioDoConvenio::DescontoPercentual, 15);
        $vinculo = $this->vincular($convenio, $tenant = $this->escritorioComPrimario());
        $url = '/master/convenios/' . $convenio->getId() . '/ligar';

        $this->postar($url, ['ligado' => false, 'encerraEm' => $this->hoje->modify('+10 days')->format('Y-m-d')]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame(0, $this->avisos($tenant));

        $prazo = $this->hoje->modify('+30 days')->format('Y-m-d');
        $this->postar($url, ['ligado' => false, 'encerraEm' => $prazo]);
        self::assertResponseIsSuccessful();
        self::assertSame([true, $prazo], [$this->linha($convenio)['ativo'], $this->linha($convenio)['encerraEm']]);
        $this->em->clear();
        $vinculo = $this->em->find(ConvenioVinculo::class, $vinculo->getId());
        self::assertSame($prazo, $vinculo?->getFimPrevisto()?->format('Y-m-d'));
        self::assertNotNull($vinculo->getAvisoEnviadoEm());
        self::assertTrue($vinculo->estaAberto());
        self::assertSame(1, $this->avisos($tenant));
        $trilha = $this->em->getRepository(AuditLog::class)->findOneBy(['entityClass' => ConvenioVinculo::class, 'entityId' => (string) $vinculo->getId()], ['id' => 'DESC']);
        self::assertSame($tenant->getId(), $trilha?->getTenantId());

        $this->postar($url, ['ligado' => true]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->linha($convenio)['encerraEm']);
        self::assertSame(2, $this->avisos($tenant));
        $this->em->clear();
        self::assertNull($this->em->find(ConvenioVinculo::class, $vinculo->getId())?->getFimPrevisto());
    }

    #[TestDox('por instituição: todos os ligados numa transação; benefício real em um deles exige 30 dias para todos')]
    public function testInstituicao(): void
    {
        $this->entrarComoMaster();
        $gratis = $this->convenio('Clube D', 'Instituicao D', BeneficioDoConvenio::Gratuidade);
        $espaco = $this->convenio('Clube D espaço', 'Instituicao D', BeneficioDoConvenio::EspacoExtra, 5);
        $this->vincular($espaco, $this->escritorioComPrimario());
        $url = '/master/convenios/instituicao/instituicao-d/desligar';

        $this->postar($url, ['encerraEm' => $this->hoje->format('Y-m-d')]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('30 dias', $this->json()['erro']);
        $this->client->request('GET', '/master/convenios/lista');
        self::assertNull($this->linha($gratis)['encerraEm'], 'nada mudou em nenhum');

        $prazo = $this->hoje->modify('+30 days')->format('Y-m-d');
        $this->postar($url, ['encerraEm' => $prazo]);
        self::assertResponseIsSuccessful();
        self::assertSame([$prazo, $prazo], [$this->linha($gratis)['encerraEm'], $this->linha($espaco)['encerraEm']]);
        self::assertSame(1, $this->json()['avisados']);
    }

    #[TestDox('falha ao enfileirar o aviso desfaz: 500, sem fim marcado, nenhum aviso')]
    public function testFalhaDesfaz(): void
    {
        $this->entrarComoMaster();
        $convenio = $this->convenio('Caixa E', 'Caixa E', BeneficioDoConvenio::Gratuidade);
        $this->vincular($convenio, $tenant = $this->escritorioComPrimario());
        static::getContainer()->set(CarteiroInterface::class, new class () implements CarteiroInterface {
            public function enviar(MensagemDeEmail $mensagem): ResultadoDoEnvio
            {
                throw new \RuntimeException('fila fora');
            }
        });

        $this->postar('/master/convenios/' . $convenio->getId() . '/ligar', ['ligado' => false, 'encerraEm' => $this->hoje->modify('+5 days')->format('Y-m-d')]);

        self::assertResponseStatusCodeSame(500);
        self::assertSame('Não foi possível enfileirar os avisos. Nada foi alterado.', $this->json()['erro']);
        $this->em->clear();
        self::assertNull($this->recarregar($convenio)->getEncerraEm());
        self::assertSame(0, $this->avisos($tenant));
    }

    #[TestDox('no dia do fim, a tarefa diária desliga o convênio e fecha o vínculo com "convenio_desligado"')]
    public function testFimNoDiaPelaTarefa(): void
    {
        $convenio = $this->convenio('Caixa F', 'Caixa F', BeneficioDoConvenio::Gratuidade);
        $vinculo = $this->vincular($convenio, $this->escritorioComPrimario());
        $convenio->marcarFim($this->hoje, null, null, $this->hoje);
        $this->em->flush();

        static::getContainer()->get(SincronizarVinculosDeConvenioUseCase::class)->executar();

        $this->em->clear();
        self::assertFalse($this->recarregar($convenio)->isAtivo());
        self::assertSame(MotivoDoFimDoVinculo::ConvenioDesligado, $this->em->find(ConvenioVinculo::class, $vinculo->getId())?->getMotivoFim());
    }

    /** @return array<string, mixed> */
    private function pedido(): array
    {
        return ['nome' => 'CAASP', 'instituicao' => 'Caixa de Assistência dos Advogados de SP', 'abrangencia' => 'uf', 'uf' => 'SP', 'beneficio' => 'desconto_percentual', 'beneficioValor' => 10, 'textoSelo' => 'CONVÊNIO CAASP', 'validoDe' => $this->hoje->format('Y-m-d')];
    }

    private function convenio(string $nome, string $instituicao, BeneficioDoConvenio $beneficio, int $valor = 0): Convenio
    {
        $chaveDaInstituicao = strtolower(str_replace(' ', '-', $instituicao));
        $dados = new DadosDoConvenio($nome, $instituicao, $chaveDaInstituicao, AbrangenciaDoConvenio::Nacional, null, $beneficio, $valor, 'CONVÊNIO', $this->hoje->modify('-1 day'), null, null);
        $convenio = Convenio::novoPorCodigo('t-' . substr(uniqid(), -10), $dados, strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ'), 0, 8)), null, $this->hoje);
        $this->em->persist($convenio);
        $this->em->flush();

        return $convenio;
    }

    private function vincular(Convenio $convenio, Tenant $tenant): ConvenioVinculo
    {
        $vinculo = new ConvenioVinculo($tenant, $convenio, OrigemDoVinculo::Codigo, $this->hoje->modify('-1 day'));
        $this->em->persist($vinculo);
        $this->em->flush();

        return $vinculo;
    }

    private function oab(): Convenio
    {
        $oab = $this->em->getRepository(Convenio::class)->findOneBy(['chave' => Convenio::CHAVE_OAB]);
        self::assertNotNull($oab);

        return $oab;
    }

    private function recarregar(Convenio $convenio): Convenio
    {
        $recarregado = $this->em->find(Convenio::class, $convenio->getId());
        self::assertNotNull($recarregado);

        return $recarregado;
    }

    /** @return array<string, mixed> a linha do convênio na última resposta */
    private function linha(Convenio $convenio): array
    {
        return array_column($this->json()['convenios'], null, 'id')[$convenio->getId()];
    }

    private function avisos(Tenant $tenant): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM comercial_envio e JOIN \"user\" u ON LOWER(u.email) = e.destinatario JOIN user_tenant ut ON ut.user_id = u.id WHERE e.origem = 'convenio' AND ut.tenant_id = ?",
            [$tenant->getId()],
        );
    }

    private function escritorioComPrimario(): Tenant
    {
        $tenant = (new Tenant())->setName('Conta do convênio ' . uniqid())->setEstado('SP');
        $this->em->persist($tenant);
        $papel = (new TenantRole())->setTenant($tenant)->setName('Administrador do Escritório')->setIsSystem(true);
        $this->em->persist($papel);
        $this->em->persist((new UserTenant($this->usuario(['ROLE_USER']), $tenant))->setTenantRole($papel));
        $this->em->flush();

        return $tenant;
    }

    private function entrarComoMaster(): void
    {
        $this->client->loginUser($this->usuario(['ROLE_SUPER_ADMIN', 'ROLE_MASTER_PRIMARIO']));
        $this->marcarTermosAceitos($this->client);
    }

    private function entrarComoPrimarioDeEscritorio(): void
    {
        $tenant = $this->escritorioComPrimario();
        $user = $this->usuario(['ROLE_USER']);
        $this->em->persist((new UserTenant($user, $tenant))->setTenantRole($this->em->getRepository(TenantRole::class)->findOneBy(['tenant' => $tenant])));
        $tenant->setCriadoPor($user);
        $this->em->flush();
        $this->logarComTenant($this->client, $user, $tenant);
    }

    /** @param list<string> $papeis */
    private function usuario(array $papeis): User
    {
        $u = new User();
        $u->setEmail('convenios_' . uniqid() . '@teste.com');
        $u->setFullName('Usuário dos Convênios');
        $u->setPassword('x');
        $u->setRoles($papeis);
        $u->setIsActive(true);
        $this->em->persist($u);
        $this->em->flush();

        return $u;
    }

    /** @param array<string, mixed> $corpo */
    private function postar(string $url, array $corpo, string $token = self::TOKEN): void
    {
        $this->client->request('POST', $url, server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $token,
        ], content: (string) json_encode($corpo));
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 16, \JSON_THROW_ON_ERROR);
    }
}
