<?php

declare(strict_types=1);

namespace App\Tests\Expediente\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\DTO\ResumoSemProcesso;
use App\Pasta\Entity\EnvioDoRelatorioSemProcesso;
use App\Expediente\Controller\RelatorioDeProcessosController;
use App\Pasta\Entity\Pasta;
use App\Pasta\UseCase\GerarRelatorioSemProcessoUseCase;
use App\Pasta\Service\PastasVisiveisNoRelatorio;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Pasta\Functional\CriaEscritorioSemProcessoTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Relatório geral de processos (página, fragmento e PDF) e "Enviar à Controladoria agora"
 * (spec relatorio-processos-controladoria §3-4, D4).
 *
 * Escritório: Paula (primária), Carla (Controladoria), Rui (secundário com Expediente e edição de
 * pasta), Vera (secundária só com Expediente e acesso item a item à 1181) e Nina (secundária sem o
 * módulo). Pastas 1180 (do Rui, com número CNJ no registro), 1181 (da Vera, sem conteúdo) e 1182
 * (excluída: não entra). Outro escritório tem a pasta 7777.
 */
#[CoversClass(RelatorioDeProcessosController::class)]
#[CoversClass(GerarRelatorioSemProcessoUseCase::class)]
#[CoversClass(PastasVisiveisNoRelatorio::class)]
#[Group('expediente')]
final class RelatorioDeProcessosControllerTest extends JusPrimeWebTestCase
{
    use CriaEscritorioSemProcessoTrait;

    private const URL = '/expediente/relatorio-processos';

    private KernelBrowser $client;
    private Tenant $tenant;
    private TenantRole $secundarioQueEdita;
    private User $paula;
    private User $carla;
    private User $rui;
    private User $vera;
    private User $nina;
    private Pasta $p1180;
    private Pasta $p1181;
    private Pasta $excluida;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->tenant = $this->novoEscritorio();
        $primario     = $this->novoPerfil($this->tenant, true);
        $this->secundarioQueEdita = $this->novoPerfil($this->tenant, false, ['modules.expediente.view', 'resources.pasta.view', 'resources.pasta.edit']);
        $soExpediente = $this->novoPerfil($this->tenant, false, ['modules.expediente.view']);

        $this->paula = $this->novaPessoa($this->tenant, 'Paula Primária', $primario);
        $this->carla = $this->novaPessoa($this->tenant, 'Carla Controladoria', $soExpediente, controladoria: true);
        $this->rui   = $this->novaPessoa($this->tenant, 'Rui Responsável', $this->secundarioQueEdita);
        $this->vera  = $this->novaPessoa($this->tenant, 'Vera Restrita', $soExpediente);
        $this->nina  = $this->novaPessoa($this->tenant, 'Nina Sem Módulo', $this->novoPerfil($this->tenant, false, ['resources.pasta.view']));

        $this->p1180 = $this->novaPasta($this->tenant, '1180', $this->rui);
        $this->registrar($this->p1180, $this->rui, 'Processo 0701134-57.2025.8.07.0007 distribuído');
        $this->p1181 = $this->novaPasta($this->tenant, '1181', $this->vera);
        $this->darAcessoAPasta($this->vera, $this->p1181);
        $this->excluida = $this->novaPasta($this->tenant, '1182', $this->rui);
        $this->em()->getConnection()->executeStatement('UPDATE pasta SET excluida_em = NOW() WHERE id = ?', [$this->excluida->getId()]);
        $this->em()->refresh($this->excluida);

        $outro = $this->novoEscritorio('Outro');
        $this->novaPasta($outro, '7777');
    }

    private function abrir(User $quem, string $sufixo = '?fragmento=1'): string
    {
        $this->logarComTenant($this->client, $quem, $this->tenant);
        $this->client->request('GET', self::URL . $sufixo);

        return (string) $this->client->getResponse()->getContent();
    }

    #[TestDox('Fragmento (200): a folha com título, cartões, as pastas do escritório e sem a excluída nem a de outro escritório')]
    public function testFragmentoDoPrimario(): void
    {
        $html = $this->abrir($this->paula);

        self::assertResponseIsSuccessful();
        $crawler = $this->client->getCrawler();
        self::assertCount(1, $crawler->filter('.rp-documento > main.rp-folha'));
        self::assertStringStartsWith('Relatório geral · Processos das pastas · ', (string) $crawler->filter('main.rp-folha')->attr('data-rp-titulo'));
        self::assertSame(
            ['Pastas', 'Sem processo', 'Deveriam ter processo', 'Administrativas', 'Rever cadastro'],
            $crawler->filter('.rp-corpo > .rsp-cartoes-moldura > table.rsp-cartoes td.rsp-cartao > .rsp-cartao-rotulo')->each(static fn ($n): string => trim($n->text())),
        );
        self::assertSame('2', trim($crawler->filter('.rsp-cartao')->first()->filter('.rsp-cartao-valor')->text()));
        self::assertStringContainsString('Deveriam ter processo: cadastrar (1)', $html);
        self::assertStringContainsString('Vincular o processo 0701134-57.2025.8.07.0007 citado em registro de rui responsável.', $html);
        self::assertStringContainsString('Sem classificação: vincular processo ou marcar como administrativa (1)', $html);
        self::assertStringNotContainsString('7777', $html, 'pasta de outro escritório');
        self::assertStringNotContainsString('1182', $html, 'pasta excluída');
        self::assertStringNotContainsString('<script', $html, 'o fragmento não leva script');
    }

    #[TestDox('Página própria (link do aviso): barra com Baixar PDF e a mesma folha')]
    public function testPaginaPropria(): void
    {
        $this->abrir($this->carla, '');

        self::assertResponseIsSuccessful();
        $crawler = $this->client->getCrawler();
        self::assertCount(1, $crawler->filter('body > .rp-barra a[href="' . self::URL . '/pdf"]'));
        self::assertCount(1, $crawler->filter('body > main.rp-folha'));
    }

    #[TestDox('Visibilidade: Controladoria vê todas; quem só tem acesso item a item vê só as dele')]
    public function testVisibilidadePorUsuario(): void
    {
        $daControladoria = $this->abrir($this->carla);
        self::assertStringContainsString('1180', $daControladoria);
        self::assertStringContainsString('1181', $daControladoria);

        $daVera = $this->abrir($this->vera);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('1181', $daVera);
        self::assertStringNotContainsString('>1180<', $daVera);
        self::assertStringNotContainsString('rui responsável', mb_strtolower($daVera));
    }

    #[TestDox('Sem o módulo Expediente: 403')]
    public function testSemModulo403(): void
    {
        $this->abrir($this->nina);

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('PDF: 200, application/pdf, anexo com o nome do desenho')]
    public function testPdf(): void
    {
        $this->abrir($this->paula, '/pdf?cabecalho=0&fonte=12');

        self::assertResponseIsSuccessful();
        $resposta = $this->client->getResponse();
        self::assertSame('application/pdf', $resposta->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $resposta->getContent());
        self::assertStringContainsString('attachment', (string) $resposta->headers->get('Content-Disposition'));
        self::assertStringContainsString('BlueJus Relat', (string) $resposta->headers->get('Content-Disposition'));
    }

    #[TestDox('PDF de outro usuário sem o módulo: 403')]
    public function testPdfSemModulo403(): void
    {
        $this->abrir($this->nina, '/pdf');

        self::assertResponseStatusCodeSame(403);
    }

    private function enviar(User $quem, Pasta $pasta, ?string $token = null, bool $xhr = true): void
    {
        $this->csrfPrevisivel();
        $this->logarComTenant($this->client, $quem, $this->tenant);
        $this->client->request('POST', self::URL . '/enviar', [
            '_token' => $token ?? 'TOKEN_' . RelatorioDeProcessosController::CSRF_ENVIAR,
            'pasta'  => (string) $pasta->getId(),
        ], [], $xhr ? ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'] : []);
    }

    #[TestDox('Enviar: quem edita a pasta envia; Controladoria e primária recebem, quem enviou não')]
    public function testEnviar(): void
    {
        $this->enviar($this->rui, $this->p1180);

        self::assertResponseIsSuccessful();
        $dados = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertTrue($dados['sucesso']);
        self::assertSame('Relatório enviado à Controladoria', $dados['mensagem']);

        $avisos = $this->avisosDoRelatorio($this->tenant);
        self::assertArrayHasKey((int) $this->carla->getId(), $avisos);
        self::assertArrayHasKey((int) $this->paula->getId(), $avisos);
        self::assertArrayNotHasKey((int) $this->rui->getId(), $avisos, 'quem enviou não recebe, nem como responsável');
        self::assertArrayHasKey((int) $this->vera->getId(), $avisos, 'a Vera é responsável pela 1181, sem classificação');
    }

    #[TestDox('Enviar sem XHR: volta para a aba Processo da pasta com a mensagem')]
    public function testEnviarSemXhr(): void
    {
        $this->enviar($this->rui, $this->p1180, xhr: false);

        self::assertResponseRedirects('/pasta/' . $this->p1180->getId() . '#processo');
    }

    #[TestDox('Enviar com CSRF inválido: 403 e nenhum aviso')]
    public function testEnviarCsrfInvalido(): void
    {
        $this->enviar($this->rui, $this->p1180, 'errado');

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->avisosDoRelatorio($this->tenant));
    }

    #[TestDox('Enviar sem EDIT na pasta: 403 e nenhum aviso')]
    public function testEnviarSemEdicao(): void
    {
        $this->enviar($this->vera, $this->p1181);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->avisosDoRelatorio($this->tenant));
    }

    #[TestDox('Enviar a partir de pasta de outro escritório: 404')]
    public function testEnviarPastaDeOutroEscritorio(): void
    {
        $alheia = $this->novaPasta($this->novoEscritorio('Alheio'), '8888');

        $this->enviar($this->paula, $alheia);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->avisosDoRelatorio($this->tenant));
    }

    #[TestDox('Enviar a partir de pasta excluída (lápide): 404 e nenhum aviso')]
    public function testEnviarPastaExcluida(): void
    {
        $this->enviar($this->paula, $this->excluida);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->avisosDoRelatorio($this->tenant));
    }

    #[TestDox('Teto: o segundo envio manual em menos de 30 minutos dá 429 com a mensagem')]
    public function testEnviarDuasVezesDa429(): void
    {
        $this->client->disableReboot();
        $this->enviar($this->rui, $this->p1180);
        self::assertResponseIsSuccessful();

        $this->client->request('POST', self::URL . '/enviar', [
            '_token' => 'TOKEN_' . RelatorioDeProcessosController::CSRF_ENVIAR,
            'pasta'  => (string) $this->p1180->getId(),
        ], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);

        self::assertResponseStatusCodeSame(429);
        $dados = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('O relatório já foi enviado há pouco. Tente de novo em alguns minutos.', $dados['erro']);
    }

    #[TestDox('Leitor com resources.pasta.view no perfil (sem acesso item a item) vê todas as pastas')]
    public function testLeitorComPermissaoDeVerTodas(): void
    {
        $leitor = $this->novaPessoa($this->tenant, 'Lia Leitora', $this->novoPerfil($this->tenant, false, ['modules.expediente.view', 'resources.pasta.view']));

        $html = $this->abrir($leitor);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('>1180<', $html);
        self::assertStringContainsString('>1181<', $html);
    }

    #[TestDox('Evolução: aparece para quem vê o escritório inteiro e fica escondida para quem vê um recorte')]
    public function testEvolucaoSoParaQuemVeTudo(): void
    {
        $this->em()->persist(new EnvioDoRelatorioSemProcesso($this->tenant, new ResumoSemProcesso(5, 3, 1, 2, 0), new \DateTimeImmutable('2026-09-07 10:05'), true));
        $this->em()->flush();

        $daPrimaria = $this->abrir($this->paula);
        self::assertStringContainsString('class="rsp-h3 rsp-h3--evolucao">Evolução<', $daPrimaria);
        self::assertStringContainsString('09/2026', $daPrimaria);

        $daVera = $this->abrir($this->vera);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('Evolução', $daVera);
        self::assertStringNotContainsString('09/2026', $daVera);
    }
}
