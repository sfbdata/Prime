<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Entity\RecursoEstado;
use App\Pasta\Service\ContadorDePaginasPorGhostscript;
use App\Shared\Armazenamento\ArmazenamentoDeArquivos;
use App\Shared\Armazenamento\CategoriaDeArquivo;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\EscopoDeArquivo;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Armazenamento\ChavesDoVoto;
use App\Voto\Controller\EditalDaAssembleiaController;
use App\Voto\DTO\ResumoDoResultado;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\ParticipacaoNaPauta;
use App\Voto\Repository\OpcaoDaPautaRepository;
use App\Voto\Service\PdfDoResumoDoResultado;
use App\Voto\UseCase\GerarResumoDoResultadoUseCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Twig\Environment;
use Zenstruck\Foundry\Test\Factories;

/**
 * Edital em PDF da assembleia (menu do cartão): guarda e posse como os vizinhos, CSRF, anexar, baixar, trocar e remover,
 * bloqueio depois do resultado e o PDF do resultado com as páginas do edital ao fim (e a linha com o hash).
 */
#[CoversClass(EditalDaAssembleiaController::class)]
final class EditalDaAssembleiaControllerTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;
    private string $dirTemp;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = TenantFactory::createOne(['name' => 'Condomínio do Edital ' . uniqid()])->_real();
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
        }
        $this->dirTemp = sys_get_temp_dir() . '/voto-edital-ft-' . bin2hex(random_bytes(6));
        mkdir($this->dirTemp, 0o700, true);
    }

    protected function tearDown(): void
    {
        $armazenamento = static::getContainer()->get(ArmazenamentoDeArquivos::class);
        foreach ($this->em->getRepository(Assembleia::class)->findAll() as $a) {
            if ($a->temEdital()) {
                $armazenamento->excluir(ChavesDoVoto::edital($a));
            }
        }
        foreach (glob($this->dirTemp . '/*') ?: [] as $arquivo) {
            @unlink($arquivo);
        }
        @rmdir($this->dirTemp);
        parent::tearDown();
    }

    #[TestDox('Sem liberação do escritório: baixar, anexar e remover dão 403')]
    public function testSemLiberacao403(): void
    {
        $this->entrar(false);
        $a = AssembleiaFactory::new(['tenant' => $this->escritorio])->create()->_real();

        $this->client->request('GET', $this->url($a));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $this->url($a), ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $this->url($a) . '/remover', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('Outro escritório: 404 em baixar, anexar e remover, e nada é gravado na assembleia alheia')]
    public function testOutroEscritorio404(): void
    {
        $this->entrar();
        $alheia = AssembleiaFactory::new()->create()->_real();
        $token = $this->tokenValido((int) $alheia->getId());

        $this->client->request('GET', $this->url($alheia));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $this->url($alheia), ['_token' => $token], ['edital' => $this->upload($this->pdf(2))]);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', $this->url($alheia) . '/remover', ['_token' => $token]);
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->em->getConnection()->fetchOne('SELECT edital_chave FROM voto.assembleia WHERE id = ?', [$alheia->getId()]) ?: null);
    }

    #[TestDox('Anexar, baixar, trocar e remover: o fluxo completo pelo menu do cartão')]
    public function testFluxoCompleto(): void
    {
        $this->entrar();
        $a = AssembleiaFactory::new(['tenant' => $this->escritorio])->create()->_real();
        $id = (int) $a->getId();

        // Sem edital: o menu oferece "Anexar edital" e o formulário escondido com o seletor de PDF.
        $painel = $this->client->request('GET', '/voto?aba=ges');
        self::assertSame('Anexar edital', trim($painel->filter('#assembleia-' . $id . ' [data-voto-edital-escolher]')->text()));
        self::assertCount(1, $painel->filter('#edital-form-' . $id . '[hidden][enctype="multipart/form-data"] input[type=file][name=edital][accept*="pdf"]'));
        self::assertCount(0, $painel->filter('#assembleia-' . $id . ' a[href$="/edital"]'));

        $token = $this->tokenValido($id);
        $pdf = $this->pdf(4);
        $this->client->request('POST', $this->url($a), ['_token' => $token], ['edital' => $this->upload($pdf, 'Edital 2026.pdf')]);
        self::assertResponseRedirects('/voto?aba=ges#assembleia-' . $id);
        $painel = $this->client->followRedirect();
        self::assertStringContainsString('Edital anexado.', $painel->filter('[data-voto-toasts]')->text());
        $a = $this->recarregar($a);
        self::assertSame('Edital 2026.pdf', $a->getEditalNome());
        self::assertSame(hash('sha256', $pdf), $a->getEditalSha256());

        // Com edital: "Baixar edital" (link), "Trocar edital" e "Remover edital" (com confirmação na janela do Voto).
        $menu = $painel->filter('#assembleia-' . $id . ' .vKm');
        self::assertSame(['Baixar edital', 'Trocar edital', 'Remover edital'], array_values(array_filter(
            $menu->filter('[role=menuitem]')->each(static fn ($c): string => trim($c->text())),
            static fn (string $t): bool => str_contains($t, 'edital'),
        )));
        self::assertCount(1, $painel->filter('#assembleia-' . $id . ' form[data-voto-confirmar][action$="/edital/remover"]'));

        $this->client->request('GET', $this->url($a));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertSame($pdf, $this->client->getResponse() instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse
            ? file_get_contents($this->client->getResponse()->getFile()->getPathname())
            : $this->client->getInternalResponse()->getContent());

        // Trocar.
        $antiga = ChavesDoVoto::edital($a);
        $this->client->request('POST', $this->url($a), ['_token' => $token], ['edital' => $this->upload($this->pdf(2), 'Novo.pdf')]);
        $painel = $this->client->followRedirect();
        self::assertStringContainsString('Edital trocado.', $painel->filter('[data-voto-toasts]')->text());
        $a = $this->recarregar($a);
        self::assertSame('Novo.pdf', $a->getEditalNome());
        $armazenamento = static::getContainer()->get(ArmazenamentoDeArquivos::class);
        self::assertFalse($armazenamento->existe($antiga), 'o arquivo antigo saiu depois do COMMIT');

        // Remover.
        $atual = ChavesDoVoto::edital($a);
        $this->client->request('POST', $this->url($a) . '/remover', ['_token' => $token]);
        $painel = $this->client->followRedirect();
        self::assertStringContainsString('Edital removido.', $painel->filter('[data-voto-toasts]')->text());
        $a = $this->recarregar($a);
        self::assertFalse($a->temEdital());
        self::assertFalse($armazenamento->existe($atual));
        $this->client->request('GET', $this->url($a));
        self::assertResponseStatusCodeSame(404);
    }

    #[TestDox('CSRF inválido, arquivo ausente e arquivo que não é PDF: aviso e nada é anexado')]
    public function testRecusas(): void
    {
        $this->entrar();
        $a = AssembleiaFactory::new(['tenant' => $this->escritorio])->create()->_real();
        $id = (int) $a->getId();
        $token = $this->tokenValido($id);

        $this->client->request('POST', $this->url($a), ['_token' => 'invalido'], ['edital' => $this->upload($this->pdf(1))]);
        $painel = $this->client->followRedirect();
        self::assertStringContainsString('A sessão expirou', $painel->filter('[data-voto-toasts]')->text());

        $this->client->request('POST', $this->url($a), ['_token' => $token]);
        $painel = $this->client->followRedirect();
        self::assertStringContainsString('Escolha o arquivo PDF do edital.', $painel->filter('[data-voto-toasts]')->text());

        $this->client->request('POST', $this->url($a), ['_token' => $token], ['edital' => $this->upload('<?php phpinfo();', 'edital.pdf')]);
        $painel = $this->client->followRedirect();
        self::assertStringContainsString('deve ser um arquivo PDF', $painel->filter('[data-voto-toasts]')->text());

        $this->client->request('GET', $this->url($a));
        self::assertResponseStatusCodeSame(404);
        $a = $this->recarregar($a);
        self::assertFalse($a->temEdital());
    }

    #[TestDox('Depois do resultado: sem item de anexar no menu, POST recusado com aviso e o edital fica fixo, só baixa')]
    public function testFixoDepoisDoResultado(): void
    {
        $this->entrar();
        [$a] = $this->cenarioComResultado();
        $id = (int) $a->getId();

        $painel = $this->client->request('GET', '/voto?aba=ges');
        $menu = $painel->filter('#assembleia-' . $id . ' .vKm [role=menuitem]')->each(static fn ($c): string => trim($c->text()));
        self::assertContains('Baixar edital', $menu);
        self::assertNotContains('Trocar edital', $menu);
        self::assertNotContains('Remover edital', $menu);
        self::assertCount(0, $painel->filter('#edital-form-' . $id));

        $token = $this->tokenValido($id);
        $antes = $a->getEditalSha256();
        $this->client->request('POST', $this->url($a), ['_token' => $token], ['edital' => $this->upload($this->pdf(1), 'outro.pdf')]);
        $painel = $this->client->followRedirect();
        self::assertStringContainsString('O resultado já saiu', $painel->filter('[data-voto-toasts]')->text());
        $this->client->request('POST', $this->url($a) . '/remover', ['_token' => $token]);
        $painel = $this->client->followRedirect();
        self::assertStringContainsString('O resultado já saiu', $painel->filter('[data-voto-toasts]')->text());
        $a = $this->recarregar($a);
        self::assertSame($antes, $a->getEditalSha256());

        $this->client->request('GET', $this->url($a));
        self::assertResponseIsSuccessful();
    }

    #[TestDox('PDF do resultado com edital: as páginas do edital vão ao fim (4 a mais) e a folha traz nome e SHA-256 do edital')]
    public function testPdfDoResultadoLevaOEdital(): void
    {
        $this->entrar();
        [$a, $sha] = $this->cenarioComResultado();

        $this->client->request('GET', '/voto/assembleias/' . $a->getId() . '/relatorio/resumo.pdf');
        self::assertResponseIsSuccessful();
        $comEdital = $this->client->getResponse()->getContent();
        self::assertIsString($comEdital);
        self::assertStringStartsWith('%PDF', $comEdital);

        $container = static::getContainer();
        $resumo = $container->get(GerarResumoDoResultadoUseCase::class)->executar($this->escritorio, (int) $a->getId());
        self::assertSame($sha, $resumo->editalSha256);
        $semEdital = $container->get(PdfDoResumoDoResultado::class)->gerar($this->semEdital($resumo));

        self::assertSame($this->paginas($semEdital) + 4, $this->paginas($comEdital));

        $html = $container->get(Environment::class)->render('voto/assembleia/resumo_resultado_pdf.html.twig', ['resumo' => $resumo, 'qr' => 'data:image/png;base64,', 'logo' => null]);
        self::assertStringContainsString('Edital anexo: Edital 2026.pdf, SHA-256 ' . $sha, $html);
        self::assertStringNotContainsString('Edital anexo', $container->get(Environment::class)->render('voto/assembleia/resumo_resultado_pdf.html.twig', ['resumo' => $this->semEdital($resumo, true), 'qr' => 'data:image/png;base64,', 'logo' => null]));
    }

    #[TestDox('Se o edital não puder ser juntado (arquivo sumiu), o PDF do resultado sai igual, só com a linha do hash')]
    public function testPdfDoResultadoSaiSemOEditalSeAJuncaoFalhar(): void
    {
        $this->entrar();
        [$a] = $this->cenarioComResultado();
        $container = static::getContainer();
        $resumo = $container->get(GerarResumoDoResultadoUseCase::class)->executar($this->escritorio, (int) $a->getId());
        $sumido = new ChaveDeArquivo(EscopoDeArquivo::deTenant((int) $this->escritorio->getId()), CategoriaDeArquivo::VOTO_EDITAL, str_repeat('e', 32) . '.pdf');
        $comChaveSumida = new ResumoDoResultado($resumo->assembleiaId, $resumo->titulo, $resumo->link, $resumo->codigoDeConferencia, $resumo->emitidoEm, $resumo->hash, $resumo->pautas, $resumo->cenario, $resumo->entidade, $resumo->editalNome, $resumo->editalSha256, $sumido);
        $servico = $container->get(PdfDoResumoDoResultado::class);

        $pdf = $servico->gerar($comChaveSumida);

        self::assertStringStartsWith('%PDF', $pdf);
        self::assertSame($this->paginas($servico->gerar($this->semEdital($resumo))), $this->paginas($pdf));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Assembleia publicada com o edital de 4 páginas anexado pela rota (antes do resultado) e uma pauta encerrada e apurada.
     *
     * @return array{0: Assembleia, 1: string} a assembleia e o sha256 do edital
     */
    private function cenarioComResultado(): array
    {
        $a = AssembleiaFactory::new(['tenant' => $this->escritorio])->publicada()->create()->_real();
        $id = (int) $a->getId();
        $pdf = $this->pdf(4);
        $this->client->request('POST', $this->url($a), ['_token' => $this->tokenValido($id)], ['edital' => $this->upload($pdf, 'Edital 2026.pdf')]);
        $a = $this->recarregar($a);
        self::assertTrue($a->temEdital());

        $unidades = [];
        foreach (['T101', 'T102'] as $identificacao) {
            $unidades[] = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => $identificacao, 'responsavel' => 'Responsável ' . $identificacao, 'email' => strtolower($identificacao) . '@edital.test'])->_real();
        }
        $pauta = PautaDaAssembleiaFactory::new(['assembleia' => $a, 'ordem' => 1, 'titulo' => 'Pauta encerrada'])->comOpcoesPadrao()->encerrada()->create()->_real();
        $this->em->refresh($a);
        $opcoes = static::getContainer()->get(OpcaoDaPautaRepository::class);
        $sim = array_values(array_filter($pauta->getOpcoes()->toArray(), static fn (OpcaoDaPauta $o): bool => 1 === $o->getNumero()));
        foreach ($unidades as $unidade) {
            $this->em->persist(new ParticipacaoNaPauta($unidade, $pauta));
            $opcoes->somarVotos($sim, $unidade->pesoDoVoto());
        }
        $this->em->flush();

        return [$a, hash('sha256', $pdf)];
    }

    private function semEdital(ResumoDoResultado $r, bool $semNome = false): ResumoDoResultado
    {
        return new ResumoDoResultado($r->assembleiaId, $r->titulo, $r->link, $r->codigoDeConferencia, $r->emitidoEm, $r->hash, $r->pautas, $r->cenario, $r->entidade, $semNome ? null : $r->editalNome, $semNome ? null : $r->editalSha256, null);
    }

    private function paginas(string $pdf): int
    {
        $caminho = $this->dirTemp . '/conta-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($caminho, $pdf);
        $n = (new ContadorDePaginasPorGhostscript('/usr/bin/gs', new NullLogger()))->contar($caminho);
        self::assertNotNull($n);

        return $n;
    }

    /** Os requests limpam o EntityManager: a assembleia é lida de novo pelo id. */
    private function recarregar(Assembleia $a): Assembleia
    {
        $this->em->clear();

        return $this->em->find(Assembleia::class, $a->getId()) ?? throw new \LogicException('assembleia sumiu');
    }

    private function url(Assembleia $a): string
    {
        return '/voto/assembleias/' . $a->getId() . '/edital';
    }

    private function upload(string $conteudo, string $nome = 'edital.pdf'): UploadedFile
    {
        $caminho = $this->dirTemp . '/' . bin2hex(random_bytes(6));
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, $nome, null, null, true);
    }

    /** Um PDF válido (com xref) de $paginas páginas em branco. */
    private function pdf(int $paginas): string
    {
        $objetos = [1 => '<< /Type /Catalog /Pages 2 0 R >>'];
        $kids = [];
        for ($i = 0; $i < $paginas; ++$i) {
            $kids[] = (3 + $i) . ' 0 R';
            $objetos[3 + $i] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 300] >>';
        }
        $objetos[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $paginas . ' >>';
        ksort($objetos);
        $saida = "%PDF-1.4\n";
        $posicoes = [];
        foreach ($objetos as $n => $corpo) {
            $posicoes[$n] = \strlen($saida);
            $saida .= $n . " 0 obj\n" . $corpo . "\nendobj\n";
        }
        $xref = \strlen($saida);
        $saida .= 'xref
0 ' . (\count($objetos) + 1) . "\n0000000000 65535 f \n";
        foreach ($posicoes as $p) {
            $saida .= sprintf("%010d 00000 n \n", $p);
        }

        return $saida . 'trailer
<< /Size ' . (\count($objetos) + 1) . ' /Root 1 0 R >>
startxref
' . $xref . "\n%%EOF\n";
    }

    /** Grava na sessão um token CSRF conhecido, como o SessionTokenStorage (ver AssembleiaGestorTest::tokenValido). */
    private function tokenValido(int $id): string
    {
        $this->client->request('GET', '/voto?aba=ele');
        $sessao = $this->client->getRequest()->getSession();
        $sessao->set('_csrf/voto_assembleia_' . $id, 'token-de-teste-' . $id);
        $sessao->save();

        return 'token-de-teste-' . $id;
    }

    private function entrar(bool $liberar = true): void
    {
        if ($liberar) {
            $liberacao = new LiberacaoDoVoto($this->escritorio);
            $liberacao->liberar();
            $this->em->persist($liberacao);
        }
        $user = UserFactory::createOne(['fullName' => 'Síndica Teste', 'roles' => ['ROLE_USER']])->_real();
        \assert($user instanceof User);
        $papel = (new TenantRole())->setTenant($this->escritorio)->setName('Perfil do Voto ' . uniqid())->setIsSystem(true);
        $this->em->persist($papel);
        $this->em->persist((new UserTenant($user, $this->escritorio))->setTenantRole($papel));
        $this->em->flush();
        $this->logarComTenant($this->client, $user, $this->escritorio);
    }
}
