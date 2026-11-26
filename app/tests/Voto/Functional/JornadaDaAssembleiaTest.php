<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Entity\RecursoEstado;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\AssembleiaController;
use App\Voto\Controller\CedulaController;
use App\Voto\Controller\PortalDaAssembleiaController;
use App\Voto\Entity\LiberacaoDoVoto;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;

/**
 * Jornada completa de uma assembleia de condomínio, contada como as pessoas fazem, só pelo HTTP (sem navegador):
 * a síndica cria a assembleia, importa as unidades, monta a eleição com a foto do candidato, publica e abre a pauta;
 * a moradora entra pelo portal público, recebe o código por e-mail, vê a foto na cédula, vota e guarda o comprovante;
 * a síndica encerra e confere o telão e a apuração.
 */
#[CoversClass(AssembleiaController::class)]
#[CoversClass(PortalDaAssembleiaController::class)]
#[CoversClass(CedulaController::class)]
final class JornadaDaAssembleiaTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private string $dirTemp;
    /** @var list<string> */
    private array $fotosGravadas = [];
    private ?int $sindicaId = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->dirTemp = sys_get_temp_dir() . '/voto-jornada-' . bin2hex(random_bytes(6));
        mkdir($this->dirTemp, 0o700, true);
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
        }
        $this->escritorio = TenantFactory::createOne(['name' => 'Escritório da Jornada ' . uniqid()])->_real();
        $liberacao = new LiberacaoDoVoto($this->escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dirTemp . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        @rmdir($this->dirTemp);
        $pastaDeFotos = rtrim((string) static::getContainer()->getParameter('uploads_dir'), '/') . '/voto-fotos/';
        foreach ($this->fotosGravadas as $nome) {
            @unlink($pastaDeFotos . $nome);
        }
        parent::tearDown();
    }

    #[TestDox('Jornada: criar, importar unidades, eleição com foto, publicar, abrir, eleitor entra e vota, encerrar, telão e apuração')]
    public function testJornadaCompletaDaAssembleia(): void
    {
        // 1. A síndica entra no escritório e cria a assembleia pelo formulário.
        $this->entrarComoSindica();
        $form = $this->client->request('GET', '/voto/assembleias/nova')->selectButton('Continuar')->form(['titulo' => 'AGO Jornada 2026', 'peso' => 'fracao']);
        $this->client->submit($form);
        self::assertSame(1, preg_match('#/voto/assembleias/(\d+)/pautas$#', (string) $this->client->getResponse()->headers->get('Location'), $m), 'Continuar leva ao passo 2 (Cargos)');
        $id = (int) $m[1];

        // 2. Importa a lista de unidades colando o texto na tela.
        $unidades = $this->client->request('GET', '/voto/assembleias/' . $id . '/unidades');
        $this->client->submit($unidades->selectButton('Importar lista')->form(['lista' => implode("\n", [
            'unidade;bloco;situacao;fracao;responsavel;email',
            '101;A;;0,40;Ana Souza;ana@exemplo.com',
            '102;A;;0,30;Bruno Lima;bruno@exemplo.com',
            '103;B;;0,30;Carla Dias;carla@exemplo.com',
        ])]));
        $unidades = $this->client->followRedirect();
        self::assertStringContainsString('3 registros importados', $unidades->filter('.voto-toast')->text());
        self::assertSame('3 aptos e 0 não aptos importados.', trim($unidades->filter('.voto-estado')->text()));

        // 3. Cria a eleição pelo atalho "Síndico(a)", preenche as candidaturas e envia a foto da primeira.
        $pautas = $this->client->request('GET', '/voto/assembleias/' . $id . '/pautas');
        $this->client->submit($pautas->filter('.voto-atalhos button[value="Síndico(a)"]')->form());
        $pautas = $this->client->followRedirect();
        $pautaId = (int) $this->em->getConnection()->fetchOne('SELECT id FROM voto.assembleia_pauta WHERE assembleia_id = ?', [$id]);
        $formDaPauta = $pautas->filter('#pauta-' . $pautaId)->form();
        $valores = $formDaPauta->getPhpValues();
        $valores['opcao_rotulo'] = ['Marta Alves', 'Paulo Reis'];
        $arquivos = ['opcao_foto_arquivo' => [0 => $this->fotoEnviada()]];
        $this->client->request('POST', $formDaPauta->getUri(), $valores, $arquivos, ['HTTP_X-Requested-With' => 'XMLHttpRequest']);
        $foto = $this->em->getConnection()->fetchOne('SELECT foto FROM voto.assembleia_opcao WHERE pauta_id = ? AND numero = 1', [$pautaId]);
        if (\is_string($foto)) {
            $this->fotosGravadas[] = $foto; // antes das asserções: o tearDown apaga o arquivo mesmo se algo falhar
        }
        self::assertResponseStatusCodeSame(200);
        self::assertIsString($foto, 'a foto da primeira candidatura foi gravada');
        $pautas = $this->client->request('GET', '/voto/assembleias/' . $id . '/pautas');
        $urlDaFotoNaTela = $pautas->filter('#pauta-' . $pautaId . ' label.vFotoU img')->attr('src');
        self::assertSame('/voto/assembleias/' . $id . '/fotos/' . pathinfo($foto, \PATHINFO_FILENAME), $urlDaFotoNaTela, 'a tela da síndica mostra a foto sem extensão');

        // 4. Publica pela conferência e abre a pauta pelo painel.
        $revisao = $this->client->request('GET', '/voto/assembleias/' . $id . '/publicar');
        self::assertResponseIsSuccessful();
        $this->client->submit($revisao->filter('form[data-voto-confirmar-publicacao]')->form());
        self::assertResponseRedirects('/voto/assembleias/' . $id . '/pautas?publicada=1');
        $painel = $this->client->request('GET', '/voto?aba=ges');
        $this->client->submit($painel->filter('#pauta-' . $pautaId . ' form[data-mesa="abrir"]')->form(['convocacao' => '1', 'duracao' => 'sem']));
        $painel = $this->client->followRedirect();
        self::assertSame('Votação aberta pela mesa.', trim($painel->filter('.voto-toast')->text()));

        // 5. A moradora (sem login) entra pelo portal público e se identifica.
        $this->client->getCookieJar()->clear();
        $codigoPublico = (string) $this->em->getConnection()->fetchOne('SELECT codigo_publico FROM voto.assembleia WHERE id = ?', [$id]);
        $portal = '/voto/v/' . $codigoPublico;
        $crawler = $this->client->request('GET', $portal);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1. Localizar seu nome', $crawler->text());
        $this->client->submit($crawler->selectButton('Continuar')->form(['nome' => 'Ana Souza', 'unidade' => '101', 'bloco' => 'A']));
        self::assertResponseRedirects($portal, 303);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Confirme sua identidade', $crawler->text());
        self::assertStringContainsString('Ana Souza', $crawler->filter('.vpIdentidade')->text());
        $this->client->submit($crawler->selectButton('Receber código por e-mail')->form(['email' => 'ana@exemplo.com']));
        self::assertResponseRedirects($portal, 303);
        self::assertEmailCount(1);
        $email = self::getMailerMessage(0);
        self::assertNotNull($email);
        self::assertEmailHeaderSame($email, 'To', 'ana@exemplo.com');
        self::assertSame(1, preg_match('/letter-spacing:6px">([0-9]{6})</', (string) $email->getHtmlBody(), $c), 'o e-mail traz o código de 6 números');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('o código de 6 números foi enviado', $crawler->text());
        $this->client->submit($crawler->selectButton('Confirmar código')->form(['codigo_acesso' => $c[1]]));
        self::assertResponseStatusCodeSame(303);
        $local = (string) $this->client->getResponse()->headers->get('Location');
        self::assertMatchesRegularExpression('#^/votar/[0-9a-f]+$#', $local, 'o código confirmado leva à cédula da unidade');

        // 6. Na cédula, a candidatura traz a foto pela rota da urna (sem extensão) e a rota entrega a imagem.
        $cedula = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Síndico(a)', $cedula->text());
        $dados = json_decode((string) $cedula->filter('[data-vc-cabine-dados]')->attr('data-vc-cabine-dados'), true, 512, \JSON_THROW_ON_ERROR);
        $opcoes = [];
        foreach ($dados['simulacao'][0]['opcoes'] as $opcao) {
            $opcoes[$opcao['rotulo']] = $opcao;
        }
        self::assertSame($local . '/foto/' . $opcoes['Marta Alves']['id'], $opcoes['Marta Alves']['foto']);
        self::assertDoesNotMatchRegularExpression('#\.(jpe?g|png|webp)$#', (string) $opcoes['Marta Alves']['foto']);
        self::assertNull($opcoes['Paulo Reis']['foto'], 'quem não enviou foto não tem imagem');
        $this->client->request('GET', (string) $opcoes['Marta Alves']['foto']);
        self::assertResponseStatusCodeSame(200);
        self::assertStringStartsWith('image/', (string) $this->client->getResponse()->headers->get('Content-Type'));

        // 7. Vota em Marta Alves (o botão do formulário da cédula) e recebe o comprovante.
        $botao = $cedula->filter('form[data-vc-votar] button[name="opcao"][value="' . $opcoes['Marta Alves']['id'] . '"]');
        self::assertCount(1, $botao);
        $this->client->submit($botao->form());
        self::assertResponseRedirects($local);
        $depois = $this->client->followRedirect();
        self::assertSame('Voto registrado.', $depois->filter('body > .vcToast[data-vc-toast]')->text());
        self::assertStringStartsWith('Você já votou nesta pauta.', $depois->filter('.vcPauta [data-vc-votou]')->text());
        self::assertSame(1, preg_match('/BJV-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}/', (string) $this->client->getResponse()->getContent(), $comprovante), 'a cédula mostra o código do comprovante');
        $crawler = $this->client->request('GET', $portal);
        $conferido = $this->client->submit($crawler->filter('form[data-vp-comprovante]')->form(['comprovante' => $comprovante[0]]));
        self::assertCount(1, $conferido->filter('[data-vp-comprovante-ok]'), 'o portal reconhece o comprovante');

        // 8. A síndica volta, encerra a pauta e confere o telão e a apuração.
        $this->client->getCookieJar()->clear();
        $this->entrarComoSindica();
        $painel = $this->client->request('GET', '/voto?aba=ges');
        $this->client->submit($painel->filter('#pauta-' . $pautaId . ' form[data-mesa="encerrar"]')->form());
        $painel = $this->client->followRedirect();
        self::assertSame('Votação encerrada.', trim($painel->filter('.voto-toast')->text()));
        $telao = $this->client->request('GET', '/voto/assembleias/' . $id . '/telao?pauta=' . $pautaId);
        self::assertResponseIsSuccessful();
        $linhas = $telao->filter('.vAoM[data-telao-pauta="' . $pautaId . '"] .vAoRes .vAoIt')->each(static fn (Crawler $l): string => preg_replace('/\s+/u', ' ', trim($l->text())) ?? '');
        self::assertContains('1 Marta Alves1', $linhas, 'o telão mostra Marta Alves com 1 voto');
        self::assertContains('2 Paulo Reis0', $linhas);
        $apuracao = $this->client->request('GET', '/voto?aba=tra');
        $cartao = $apuracao->filter('#assembleia-' . $id);
        $resultado = $cartao->filter('.voto-resultado-opcao')->each(static fn (Crawler $o): array => [
            preg_replace('/\s+/u', ' ', trim($o->filter('.voto-resultado-nome')->text())),
            trim($o->filter('.voto-resultado-valor')->text()),
        ]);
        self::assertSame('1 Marta Alves', $resultado[0][0] ?? null, 'a apuração lista Marta Alves primeiro');
        self::assertStringStartsWith('1 un', $resultado[0][1] ?? '', 'Marta Alves com 1 voto');
        self::assertSame('2 Paulo Reis', $resultado[1][0] ?? null);
        self::assertStringStartsWith('0 un', $resultado[1][1] ?? '', 'Paulo Reis sem voto');
    }

    private function fotoEnviada(): UploadedFile
    {
        $caminho = $this->dirTemp . '/' . bin2hex(random_bytes(6));
        ob_start();
        imagejpeg(imagecreatetruecolor(6, 8), null, 88);
        file_put_contents($caminho, (string) ob_get_clean());

        return new UploadedFile($caminho, 'marta.jpg', null, null, true);
    }

    private function entrarComoSindica(): void
    {
        if (null !== $this->sindicaId) {
            // Volta ao sistema: a mesma pessoa entra de novo.
            $this->logarComTenant($this->client, $this->em->find(User::class, $this->sindicaId), $this->em->find(Tenant::class, $this->escritorio->getId()));

            return;
        }
        $user = UserFactory::createOne(['fullName' => 'Síndica Teste', 'roles' => ['ROLE_USER']])->_real();
        $this->sindicaId = $user->getId();
        $papel = (new TenantRole())->setTenant($this->escritorio)->setName('Perfil do Voto ' . uniqid())->setIsSystem(true);
        $this->em->persist($papel);
        $this->em->persist((new UserTenant($user, $this->escritorio))->setTenantRole($papel));
        $this->em->flush();
        $this->logarComTenant($this->client, $user, $this->escritorio);
    }
}
