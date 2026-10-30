<?php

declare(strict_types=1);

namespace App\Tests\Calculo\Functional;

use App\AtualizacaoMonetaria\Entity\IndiceMonetario;
use App\AtualizacaoMonetaria\Enum\SerieIndice;
use App\AtualizacaoMonetaria\Service\ClienteSgsBcbInterface;
use App\Calculo\Controller\CalculoController;
use App\Calculo\Entity\CalculoEmitido;
use App\Calculo\UseCase\ConsultarStatusDosIndicesUseCase;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Tests\Calculo\CalculoDeFixture;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Rotas do Cálculo pela HTTP: porta de permissão, CSRF, cálculo do exemplo do PDF, PDF e "Atualizar índices".
 * As séries do BCB (fixture) são gravadas no banco de teste, que o DAMA desfaz ao fim de cada teste.
 */
#[CoversClass(CalculoController::class)]
final class CalculoControllerTest extends JusPrimeWebTestCase
{
    private const TOKEN = 'TOKEN_calculo';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        // o cache da aplicação sobrevive entre execuções: a trava de 1 minuto do "Atualizar índices" não pode vazar para o teste
        static::getContainer()->get(CacheInterface::class)->delete(ConsultarStatusDosIndicesUseCase::CHAVE_ULTIMA_TENTATIVA);
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

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** Usuário de um escritório novo, com `modules.calculo.view` (ou sem ela). */
    private function entrar(bool $comPermissao): User
    {
        $em = $this->em();
        $tenant = new Tenant();
        $tenant->setName('Escritório Cálculo ' . uniqid());
        $em->persist($tenant);

        $perfil = new TenantRole();
        $perfil->setTenant($tenant);
        $perfil->setName('Perfil Cálculo ' . uniqid());
        $em->persist($perfil);

        if ($comPermissao) {
            $permissao = $em->getRepository(Permission::class)->findOneBy(['code' => 'modules.calculo.view']);
            if ($permissao === null) {
                $permissao = (new Permission())->setCode('modules.calculo.view')->setDescription('Acesso ao módulo Cálculo')->setGroup('modules');
                $em->persist($permissao);
            }
            $concessao = new TenantRolePermission();
            $concessao->setTenantRole($perfil);
            $concessao->setPermission($permissao);
            $em->persist($concessao);
            $perfil->getTenantRolePermissions()->add($concessao);
        }

        $user = new User();
        $user->setEmail('calculo_' . uniqid() . '@test.com');
        $user->setFullName('Advogada Cálculo');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $em->persist($user);
        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($perfil);
        $em->persist($vinculo);
        $em->flush();

        $this->logarComTenant($this->client, $user, $tenant);

        return $user;
    }

    private function gravarIndicesDaFixture(): void
    {
        $agora = new \DateTimeImmutable('2026-10-30 12:00:00');
        $bruto = json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/series-bcb-30-10-2026.json'), true, 512, \JSON_THROW_ON_ERROR);
        foreach (['INPC', 'IPCA', 'SELIC'] as $nome) {
            $serie = SerieIndice::from($nome);
            foreach ($bruto[$nome] as $competencia => $valor) {
                $this->em()->persist(new IndiceMonetario($serie, new \DateTimeImmutable($competencia . '-01'), (string) $valor, $serie->fonte(), $agora));
            }
        }
        $this->em()->flush();
    }

    /** @return array<string, mixed> o exemplo do PDF (valor de R$ 100 + 12 parcelas de R$ 1.000, multa 20%, honorários 15%, art. 523) */
    private function exemploDoPdf(): array
    {
        $caso = CalculoDeFixture::casosDeReferencia()['casos'][0];
        self::assertSame('exemplo_pdf', $caso['nome']);

        return $caso['entrada'];
    }

    private function postarJson(string $url, array $corpo, ?string $token = self::TOKEN): void
    {
        $this->client->request('POST', $url, [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => (string) $token], json_encode($corpo, \JSON_THROW_ON_ERROR));
    }

    public function testPaginaSegueOArranjoDoDesenhoESemTextoSemTraducao(): void
    {
        $this->entrar(true);

        $crawler = $this->client->request('GET', '/calculo');
        self::assertResponseIsSuccessful();

        $raiz = '.calc-page > main.calc-main > section.calc-sec';
        self::assertSame(3, $crawler->filter($raiz)->count(), 'Três seções, filhos diretos do main');
        foreach (['calcBtnCalcular', 'calcBtnLimpar', 'calcBtnIndices', 'calcBtnPdf', 'calcIndicesSelo', 'calcErros'] as $id) {
            self::assertSame(1, $crawler->filter('#' . $id)->count(), $id);
        }
        self::assertSame(0, $crawler->filter('.calc-page .card, .calc-page .modal, .calc-page .select2')->count(), 'Sem componente do sistema');

        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('calculo.tela.', $html, 'Chave de tradução sem texto');
        self::assertStringNotContainsString('calculo.pagina.', $html);
        self::assertDoesNotMatchRegularExpression('/[—–]/u', $html);
        self::assertSame(1, $crawler->filter('a[href="/calculo"]')->count(), 'Link no menu lateral');
    }

    public function testMenuLateralNaoMostraCalculoSemPermissao(): void
    {
        $this->entrar(false);

        $crawler = $this->client->request('GET', '/');
        self::assertSame(0, $crawler->filter('a[href="/calculo"]')->count());
    }

    public function testSemPermissaoTodasAsRotasNegam(): void
    {
        $this->entrar(false);

        $this->client->request('GET', '/calculo');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->postarJson('/calculo/calcular', $this->exemploDoPdf());
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->postarJson('/calculo/indices/atualizar', []);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);

        $this->client->request('POST', '/calculo/pdf', ['_token' => self::TOKEN, 'dados' => json_encode($this->exemploDoPdf())]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testSemTokenCsrfNega(): void
    {
        $this->entrar(true);

        $this->postarJson('/calculo/calcular', $this->exemploDoPdf(), 'token-errado-mas-o-storage-aceita-tudo');
        // o storage falso devolve sempre TOKEN_<id>: qualquer outro valor tem de ser recusado
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testCalculaOExemploDoPdfPelaHttp(): void
    {
        $this->entrar(true);
        $this->gravarIndicesDaFixture();

        $this->postarJson('/calculo/calcular', $this->exemploDoPdf());

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($json['ok']);
        self::assertSame('30/10/2026', $json['ate']);
        self::assertCount(13, $json['linhas']);
        self::assertSame('1,004974', $json['linhas'][0]['f']);
        $resumo = array_column($json['resumo'], 'v', 'r');
        self::assertSame('R$ 12.134,40', $resumo['Subtotal corrigido com juros']);
        self::assertSame('R$ 16.745,47', $resumo['Débito']);
        self::assertSame('R$ 20.094,57', $resumo['TOTAL DEVIDO']);
        self::assertTrue($json['linhas'][12]['proj'], 'a última parcela (25/07/2027) é PROJEÇÃO');
        self::assertFalse($json['linhas'][0]['proj']);
        self::assertNotSame('', $json['aviso']);
    }

    public function testEntradaIncompletaDevolve422ComOCampo(): void
    {
        $this->entrar(true);
        $this->gravarIndicesDaFixture();

        $this->postarJson('/calculo/calcular', ['ate' => '2026-10-30', 'vals' => []]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertFalse($json['ok']);
        self::assertSame('nvV', $json['erros'][0]['campo']);
        self::assertSame('Ir para Valores', $json['erros'][0]['irTxt']);
    }

    public function testSemIndicesNoBancoOCalculoAvisa(): void
    {
        $this->entrar(true);

        $this->postarJson('/calculo/calcular', $this->exemploDoPdf());

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertStringContainsString('Atualizar índices', $json['erros'][0]['msg']);
    }

    public function testGeraOPdfParaDownload(): void
    {
        $this->entrar(true);
        $this->gravarIndicesDaFixture();
        $dados = $this->exemploDoPdf();
        $dados['proc'] = '0701234-56.2026.8.07.0001';

        $this->client->request('POST', '/calculo/pdf', ['_token' => self::TOKEN, 'dados' => json_encode($dados, \JSON_THROW_ON_ERROR)]);

        self::assertResponseIsSuccessful();
        $resposta = $this->client->getResponse();
        self::assertSame('application/pdf', $resposta->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $resposta->getContent());
        self::assertStringContainsString('attachment', (string) $resposta->headers->get('Content-Disposition'));
        self::assertStringContainsString('0701234-56.2026.8.07.0001', (string) $resposta->headers->get('Content-Disposition'));

        // o PDF emitido fica registrado: código no formato do rodapé e hash dos bytes entregues
        $emitidos = $this->em()->getRepository(CalculoEmitido::class)->findAll();
        self::assertCount(1, $emitidos);
        self::assertMatchesRegularExpression('/^BJC-[A-Z0-9]{8}$/', $emitidos[0]->getCodigo());
        self::assertSame('20094.57', $emitidos[0]->getTotal());
        self::assertTrue($emitidos[0]->confereComOHash(hash('sha256', (string) $resposta->getContent())));
    }

    public function testAtualizarIndicesGravaAsTresSeriesEAtualizaOSelo(): void
    {
        $this->entrar(true);
        static::getContainer()->set(ClienteSgsBcbInterface::class, new class () implements ClienteSgsBcbInterface {
            public function baixarSerie(SerieIndice $serie): array
            {
                return ['2026-08-01' => '0.100000', '2026-09-01' => '0.200000'];
            }
        });

        $this->postarJson('/calculo/indices/atualizar', []);

        self::assertResponseIsSuccessful();
        $json = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($json['ok'], (string) $json['erro']);
        self::assertSame('ok', $json['status']['estado']);
        self::assertSame('Índices até 09/2026', $json['status']['texto']);
        self::assertSame(6, $this->em()->getRepository(IndiceMonetario::class)->count([]));
    }
}
