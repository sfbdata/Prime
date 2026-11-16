<?php

declare(strict_types=1);

namespace App\Tests\Voto\Functional;

use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Entity\RecursoEstado;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Factory\Tenant\TenantFactory;
use App\Tests\Factory\Voto\AssembleiaFactory;
use App\Tests\Factory\Voto\PautaDaAssembleiaFactory;
use App\Tests\Factory\Voto\UnidadeDaAssembleiaFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Voto\Controller\MinutaDaAtaController;
use App\Voto\Entity\Assembleia;
use App\Voto\Entity\LiberacaoDoVoto;
use App\Voto\Entity\OpcaoDaPauta;
use App\Voto\Entity\ParticipacaoNaPauta;
use App\Voto\Entity\PautaDaAssembleia;
use App\Voto\Repository\OpcaoDaPautaRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;

/**
 * Minuta de ata em Word (com papel timbrado opcional) e em PDF (desenho 18, W2-6): guarda e posse como os vizinhos (403
 * sem liberação, 404 de outro escritório e de rascunho), download com tipo e nome certos, conteúdo da ata (retirada e
 * cancelada incluídas), modelo .docx aplicado ou recusado com a mensagem do desenho, só com votação apurada.
 */
#[CoversClass(MinutaDaAtaController::class)]
final class MinutaDaAtaControllerTest extends JusPrimeWebTestCase
{
    use Factories;

    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $escritorio;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->escritorio = TenantFactory::createOne(['name' => 'Condomínio da Minuta ' . uniqid()])->_real();
        $estado = $this->em->getRepository(RecursoEstado::class)->findOneBy(['chave' => 'voto']);
        if (null !== $estado) {
            $estado->reativar(null, new \DateTimeImmutable());
            $this->em->flush();
        }
    }

    #[TestDox('Sem liberação do escritório: Word e PDF dão 403')]
    public function testSemLiberacaoRecebe403(): void
    {
        $this->entrar();
        $a = AssembleiaFactory::new(['tenant' => $this->escritorio])->publicada()->create()->_real();

        foreach (['docx', 'pdf'] as $formato) {
            $this->client->request('POST', $this->url($a, $formato), ['_token' => 'qualquer']);
            self::assertResponseStatusCodeSame(403, $formato);
        }
    }

    #[TestDox('Outro escritório e assembleia em rascunho: 404 no Word e no PDF')]
    public function testOutroEscritorioERascunhoDao404(): void
    {
        $this->liberar();
        $this->entrar();
        $alheia = AssembleiaFactory::new()->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $alheia])->comOpcoesPadrao()->encerrada()->create();
        $rascunho = AssembleiaFactory::new(['tenant' => $this->escritorio])->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $rascunho])->comOpcoesPadrao()->create();

        foreach ([$alheia, $rascunho] as $a) {
            foreach (['docx', 'pdf'] as $formato) {
                $this->baixar($a, $formato, $this->token($a));
                self::assertResponseStatusCodeSame(404, $formato);
            }
        }
    }

    #[TestDox('Word: .docx como download, com o conteúdo da ata (retirada e cancelada) e os campos para completar destacados')]
    public function testDocxComOConteudoDaAta(): void
    {
        [$a] = $this->cenario();

        $this->baixar($a, 'docx', $this->token($a));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', self::DOCX);
        self::assertStringContainsString('attachment; filename="minuta-ata-' . $a->getId() . '.docx"', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $documento = $this->arquivosDoZip((string) $this->client->getResponse()->getContent())['word/document.xml'];
        $texto = $this->textoDe($documento);
        self::assertStringContainsString('ATA DA ASSEMBLEIA DA MINUTA', $texto);
        self::assertStringContainsString('Minuta gerada pelo BlueJus Voto. Confira e complete os campos destacados antes de assinar.', $texto);
        self::assertStringContainsString('reuniram-se os participantes de Condomínio Modelo, em 1ª convocação', $texto);
        self::assertStringContainsString('1. Pauta encerrada', $texto);
        self::assertStringContainsString('Retirada da ordem do dia: Pauta retirada, por decisão registrada em', $texto);
        self::assertStringContainsString('Motivo: Matéria adiada. Não houve deliberação sobre essa matéria.', $texto);
        self::assertMatchesRegularExpression('/2\. Pauta cancelada: Votação cancelada pela mesa em \d\d\/\d\d\/\d{4} \d\d:\d\d:\d\d\. Motivo: Convocação irregular\. Os votos recebidos ficaram lacrados e não foram apurados\./', $texto);
        self::assertStringContainsString('Resultado: Aprovada', $texto);
        self::assertStringContainsString('1 Sim', $texto);
        self::assertStringContainsString('Abstenções', $texto);
        self::assertStringContainsString('O voto foi secreto', $texto);
        self::assertMatchesRegularExpression('/<w:highlight w:val="yellow"\/><w:sz w:val="22"\/><\/w:rPr><w:t xml:space="preserve">\[nome do presidente\]</', $documento);
        self::assertStringContainsString('nome do presidente, Presidência da assembleia', $texto);
        self::assertStringNotContainsString('Responsável T101', $texto, 'sem a relação nominal de votantes');
        self::assertStringNotContainsString('@minuta.test', $texto);
        self::assertLessThan(strpos($documento, '<w:sectPr'), strpos($documento, '2. Pauta cancelada'));
    }

    #[TestDox('Word com papel timbrado: o corpo entra no body do modelo e o cabeçalho do modelo fica no arquivo')]
    public function testDocxNoPapelTimbrado(): void
    {
        [$a] = $this->cenario();
        $modelo = $this->modelo(['word/header1.xml' => '<w:hdr>TIMBRE-DO-ESCRITORIO</w:hdr>']);

        $this->baixar($a, 'docx', $this->token($a), $modelo);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', self::DOCX);
        $arquivos = $this->arquivosDoZip((string) $this->client->getResponse()->getContent());
        self::assertSame('<w:hdr>TIMBRE-DO-ESCRITORIO</w:hdr>', $arquivos['word/header1.xml']);
        self::assertStringNotContainsString('TEXTO ANTIGO DO MODELO', $arquivos['word/document.xml']);
        self::assertMatchesRegularExpression('#<w:body><w:p><w:pPr>.*ATA DA ASSEMBLEIA DA MINUTA.*</w:p><w:sectPr><w:headerReference#s', $arquivos['word/document.xml']);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function modelosQueNaoServem(): iterable
    {
        yield 'não é zip' => ['isto não é um docx', 'Modelo .docx inválido.'];
        yield 'zip sem document.xml' => ['ZIP:word/header1.xml', 'Modelo sem word/document.xml.'];
        yield 'document.xml sem body' => ['DOC:<w:document xmlns:w="' . self::W . '"/>', 'Modelo .docx não reconhecido.'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('modelosQueNaoServem')]
    #[TestDox('Modelo que não serve: volta ao painel com a mensagem do desenho e nada é gerado')]
    public function testModeloInvalido(string $conteudo, string $mensagem): void
    {
        [$a] = $this->cenario();
        $arquivo = str_starts_with($conteudo, 'ZIP:') ? $this->modelo([], false) : (str_starts_with($conteudo, 'DOC:') ? $this->modelo([], true, substr($conteudo, 4)) : $this->arquivoTemporario($conteudo));

        $this->baixar($a, 'docx', $this->token($a), $arquivo);

        self::assertResponseRedirects('/voto?aba=ges#assembleia-' . $a->getId());
        self::assertStringContainsString($mensagem, $this->client->followRedirect()->filter('[data-voto-toasts]')->text());
    }

    #[TestDox('PDF: application/pdf como download, sem modelo')]
    public function testPdf(): void
    {
        [$a] = $this->cenario();

        $this->baixar($a, 'pdf', $this->token($a), $this->modelo());

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringContainsString('attachment; filename="minuta-ata-' . $a->getId() . '.pdf"', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());
    }

    #[TestDox('Sem votação apurada: volta ao painel com o aviso, sem gerar arquivo')]
    public function testSemVotacaoApurada(): void
    {
        $this->liberar();
        $this->entrar();
        $a = AssembleiaFactory::new(['tenant' => $this->escritorio])->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $a, 'titulo' => 'Pauta aguardando'])->comOpcoesPadrao()->create();
        $this->em->refresh($a);

        foreach (['docx', 'pdf'] as $formato) {
            $this->baixar($a, $formato, $this->token($a));
            self::assertResponseRedirects('/voto?aba=ges#assembleia-' . $a->getId());
            self::assertStringContainsString('Ainda não há votação apurada para a minuta de ata.', $this->client->followRedirect()->filter('[data-voto-toasts]')->text());
        }
    }

    #[TestDox('Token inválido: volta ao painel com o aviso de sessão, sem gerar arquivo')]
    public function testTokenInvalido(): void
    {
        [$a] = $this->cenario();

        $this->baixar($a, 'docx', 'token-errado');

        self::assertResponseRedirects('/voto?aba=ges#assembleia-' . $a->getId());
    }

    #[TestDox('GET não existe: a minuta só sai por POST, com o token')]
    public function testSoPorPost(): void
    {
        [$a] = $this->cenario();

        $this->client->request('GET', $this->url($a, 'docx'));

        self::assertResponseStatusCodeSame(405);
    }

    #[TestDox('Cartão: com votação apurada tem o formulário com Minuta de ata, PDF e o rótulo do papel timbrado; sem, não tem')]
    public function testBotaoNoCartao(): void
    {
        [$a] = $this->cenario();
        $vazia = AssembleiaFactory::new(['tenant' => $this->escritorio])->publicada()->create()->_real();
        PautaDaAssembleiaFactory::new(['assembleia' => $vazia])->comOpcoesPadrao()->create();

        $painel = $this->client->request('GET', '/voto?aba=ges');

        $form = $painel->filter('#assembleia-' . $a->getId() . ' .voto-acoes > form.voto-minuta[data-voto-minuta][enctype="multipart/form-data"]');
        self::assertCount(1, $form);
        self::assertSame($this->url($a, 'docx'), $form->attr('action'));
        self::assertSame('Minuta de ata', trim($form->filter('button.vB.vP')->text()));
        self::assertCount(1, $form->filter('button.vB.vP > i.bi-file-earmark-word'));
        self::assertSame($this->url($a, 'pdf'), $form->filter('button[formaction]')->attr('formaction'));
        self::assertSame('Papel timbrado para ata', trim($form->filter('label.vB > span')->text()));
        self::assertSame('Modelo .docx com o papel timbrado para as minutas de ata', $form->filter('label.vB')->attr('title'));
        self::assertSame('.docx', $form->filter('label.vB > input[type="file"][name="timbre"]')->attr('accept'));
        self::assertCount(0, $painel->filter('#assembleia-' . $vazia->getId() . ' form.voto-minuta'));
    }

    /**
     * Assembleia publicada de 3 unidades com: pauta encerrada (3 votaram Sim), pauta cancelada e pauta retirada.
     *
     * @return array{0: Assembleia}
     */
    private function cenario(): array
    {
        $this->liberar();
        $this->entrar();
        $a = AssembleiaFactory::new(['tenant' => $this->escritorio, 'titulo' => 'Assembleia da Minuta', 'entidadeNome' => 'Condomínio Modelo'])->publicada()->create()->_real();
        $unidades = [];
        foreach (['T101', 'T102', 'T103'] as $identificacao) {
            $unidades[] = UnidadeDaAssembleiaFactory::createOne(['assembleia' => $a, 'identificacao' => $identificacao, 'responsavel' => 'Responsável ' . $identificacao, 'email' => strtolower($identificacao) . '@minuta.test'])->_real();
        }
        $encerrada = PautaDaAssembleiaFactory::new(['assembleia' => $a, 'ordem' => 1, 'titulo' => 'Pauta encerrada'])->comOpcoesPadrao()->encerrada()->create()->_real();
        $cancelada = PautaDaAssembleiaFactory::new(['assembleia' => $a, 'ordem' => 2, 'titulo' => 'Pauta cancelada'])->comOpcoesPadrao()->aberta()->create()->_real();
        $retirada = PautaDaAssembleiaFactory::new(['assembleia' => $a, 'ordem' => 3, 'titulo' => 'Pauta retirada'])->comOpcoesPadrao()->create()->_real();
        $cancelada->cancelar('Convocação irregular', 'Mesa');
        $retirada->retirarDePauta('Matéria adiada', 'Mesa');
        $this->em->flush();
        $this->em->refresh($a);
        $this->votar($encerrada, $unidades);

        return [$a];
    }

    private function votar(PautaDaAssembleia $pauta, array $unidades): void
    {
        $opcoes = static::getContainer()->get(OpcaoDaPautaRepository::class);
        $sim = array_values(array_filter($pauta->getOpcoes()->toArray(), static fn (OpcaoDaPauta $o): bool => 1 === $o->getNumero()));
        foreach ($unidades as $unidade) {
            $this->em->persist(new ParticipacaoNaPauta($unidade, $pauta));
            $opcoes->somarVotos($sim, $unidade->pesoDoVoto());
        }
        $this->em->flush();
    }

    private function url(Assembleia $a, string $formato): string
    {
        return '/voto/assembleias/' . $a->getId() . '/minuta/' . $formato;
    }

    private function baixar(Assembleia $a, string $formato, string $token, ?UploadedFile $timbre = null): void
    {
        $this->client->request('POST', $this->url($a, $formato), ['_token' => $token], null === $timbre ? [] : ['timbre' => $timbre]);
    }

    /** Grava na sessão um token CSRF conhecido, como o SessionTokenStorage (ver CicloDaPautaControllerTest::tokenValido). */
    private function token(Assembleia $a): string
    {
        $id = 'voto_assembleia_' . $a->getId();
        $this->client->request('GET', '/voto?aba=ges');
        $sessao = $this->client->getRequest()->getSession();
        $sessao->set('_csrf/' . $id, 'token-de-teste-' . $id);
        $sessao->save();

        return 'token-de-teste-' . $id;
    }

    /**
     * Um modelo .docx de verdade (zip com [Content_Types].xml, document.xml com body e sectPr de cabeçalho) como upload.
     *
     * @param array<string, string> $extras
     */
    private function modelo(array $extras = [], bool $comDocumento = true, ?string $documento = null): UploadedFile
    {
        $arquivos = ['[Content_Types].xml' => '<Types/>'] + $extras;
        if ($comDocumento) {
            $arquivos['word/document.xml'] = $documento ?? '<?xml version="1.0"?><w:document xmlns:w="' . self::W . '" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><w:body><w:p><w:r><w:t>TEXTO ANTIGO DO MODELO</w:t></w:r></w:p><w:sectPr><w:headerReference w:type="default" r:id="rId1"/></w:sectPr></w:body></w:document>';
        }
        $caminho = (string) tempnam(sys_get_temp_dir(), 'modelo');
        $zip = new \ZipArchive();
        $zip->open($caminho, \ZipArchive::OVERWRITE);
        foreach ($arquivos as $nome => $conteudo) {
            $zip->addFromString($nome, $conteudo);
        }
        $zip->close();

        return new UploadedFile($caminho, 'timbre.docx', null, null, true);
    }

    private function arquivoTemporario(string $conteudo): UploadedFile
    {
        $caminho = (string) tempnam(sys_get_temp_dir(), 'modelo');
        file_put_contents($caminho, $conteudo);

        return new UploadedFile($caminho, 'timbre.docx', null, null, true);
    }

    /** @return array<string, string> */
    private function arquivosDoZip(string $bytes): array
    {
        $caminho = (string) tempnam(sys_get_temp_dir(), 'saida');
        file_put_contents($caminho, $bytes);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($caminho, \ZipArchive::RDONLY), 'a resposta é um .docx (zip)');
        $arquivos = [];
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $arquivos[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($caminho);

        return $arquivos;
    }

    /** O texto corrido do document.xml: runs juntos por parágrafo, parágrafos separados por espaço. */
    private function textoDe(string $documento): string
    {
        $doc = new \DOMDocument();
        $doc->loadXML($documento);
        $partes = [];
        foreach ($doc->getElementsByTagNameNS(self::W, 'p') as $p) {
            $partes[] = $p->textContent;
        }

        return implode(' ', $partes);
    }

    private function liberar(): void
    {
        $liberacao = new LiberacaoDoVoto($this->escritorio);
        $liberacao->liberar();
        $this->em->persist($liberacao);
        $this->em->flush();
    }

    private function entrar(): void
    {
        $user = UserFactory::createOne(['fullName' => 'Síndica Teste', 'roles' => ['ROLE_USER']])->_real();
        $papel = (new TenantRole())->setTenant($this->escritorio)->setName('Perfil do Voto ' . uniqid())->setIsSystem(true);
        $this->em->persist($papel);
        $this->em->persist((new UserTenant($user, $this->escritorio))->setTenantRole($papel));
        $this->em->flush();
        $this->logarComTenant($this->client, $user, $this->escritorio);
    }
}
