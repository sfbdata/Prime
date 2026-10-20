<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Pasta\Controller\PeticionarController;
use App\Pasta\DTO\ExploradorDeDocumentosOutput;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;

/**
 * A tela do Peticionar depois do desenho 07 (P1 e P2): "Voltar à pasta", menu da linha com os
 * data-* das rotas que já existem, tamanho e ícone por tipo, barra do selecionado, dica da lista
 * vazia. O comportamento (clique, menu, soltar arquivo) é do peticionar-documentos.js; aqui se
 * prova o que o servidor entrega para ele, e o arranjo por filho direto.
 */
#[CoversClass(PeticionarController::class)]
final class PeticionarDocumentosTelaTest extends JusPrimeWebTestCase
{
    #[TestDox('"Voltar à pasta" aponta para a pasta, e a tela inclui o JS e o CSS do desenho 07')]
    public function testVoltarAPastaEAssets(): void
    {
        [$client, $pasta] = $this->cenario();

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");

        self::assertResponseIsSuccessful();
        $voltar = $crawler->filter('.petic-main > a.petic-voltar-pasta');
        self::assertCount(1, $voltar);
        self::assertSame("/pasta/{$pasta->getId()}", $voltar->attr('href'));
        self::assertSame('Voltar à pasta', trim($voltar->text()));
        self::assertCount(1, $voltar->filter('i.bi-arrow-left'));

        self::assertCount(1, $crawler->filter('script[src*="/js/peticionar-documentos.js"]'));
        self::assertCount(1, $crawler->filter('link[href*="/css/peticionar-documentos.css"]'));
    }

    #[TestDox('cada linha tem os três pontos e os data-* das rotas de renomear, classificar, baixar e excluir')]
    public function testLinhaTemMenuEDataAttributesDasRotas(): void
    {
        [$client, $pasta, $tenant] = $this->cenario();
        $pdf = $this->documento($pasta, $tenant, 'Petição Inicial.pdf', 'application/pdf', 1024, 'desc da peça', '001/2026');
        $png = $this->documento($pasta, $tenant, 'foto.png', 'image/png', 2621440);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");
        self::assertResponseIsSuccessful();

        $lista = $crawler->filter('.petic-timeline > #timelineList');
        self::assertCount(1, $lista);
        self::assertSame('1', $lista->attr('data-pode-editar'));
        self::assertSame("/pasta/{$pasta->getId()}/documentos/excluir-lote", $lista->attr('data-url-excluir'));
        self::assertTokenValido('pex_lote_' . $pasta->getId(), $lista->attr('data-csrf-lote'));
        self::assertSame("/pasta/{$pasta->getId()}/peticionar/upload", $lista->attr('data-url-upload'));
        self::assertTokenValido('peticionar_upload_' . $pasta->getId(), $lista->attr('data-csrf-upload'));
        self::assertSame('/pasta/documento/__ID__/editar', $lista->attr('data-url-editar-tpl'));
        self::assertSame(
            array_keys(ExploradorDeDocumentosOutput::CATEGORIAS),
            json_decode((string) $lista->attr('data-classificaveis'), true),
            'Classificar só oferece o que o pasta_documento_edit aceita',
        );

        foreach ([$pdf, $png] as $doc) {
            $id  = (int) $doc->getId();
            $row = $this->linha($crawler, $id);

            self::assertSame("/pasta/documento/{$id}/editar", $row->attr('data-editar-documento-url'));
            self::assertTokenValido('edit_documento_' . $id, $row->attr('data-csrf-documento'));
            self::assertSame("/pasta/documento/{$id}/download", $row->attr('data-download-url'));
            self::assertSame("/pasta/documento/{$id}/visualizar", $row->attr('data-view-url'));

            $mais = $row->filter('.petic-tl-row > .petic-tl-card > button.petic-doc-mais');
            self::assertCount(1, $mais, 'os três pontos ficam no cartão da linha');
            self::assertSame('Mais opções', $mais->attr('title'));
            self::assertCount(1, $mais->filter('i.bi-three-dots-vertical'));
        }

        $rowPdf = $this->linha($crawler, (int) $pdf->getId());
        self::assertSame('Petição Inicial.pdf', $rowPdf->attr('data-titulo'), 'o nome é o da aba Documentos (o que o Renomear grava)');
        self::assertSame('desc da peça', $rowPdf->attr('data-descricao'), 'o Renomear reenvia a descrição: sem ela, viraria NULL');
        self::assertSame('001/2026', $rowPdf->attr('data-numero'));
        self::assertSame(PastaDocumento::CATEGORIA_PECA, $rowPdf->attr('data-categoria'));
        self::assertMatchesRegularExpression('~^\d{2}/\d{2}/\d{4} \d{2}:\d{2}$~', (string) $rowPdf->attr('data-carregado-em'));
    }

    #[TestDox('a linha mostra o tamanho do arquivo e o ícone do tipo (PDF, imagem, peça de texto)')]
    public function testTamanhoEIconePorTipo(): void
    {
        [$client, $pasta, $tenant] = $this->cenario();
        $pdf   = $this->documento($pasta, $tenant, 'contrato.pdf', 'application/pdf', 1024);
        $png   = $this->documento($pasta, $tenant, 'foto.png', 'image/png', 2621440);
        $texto = $this->documento($pasta, $tenant, 'Minha Petição.html', 'text/html', 300);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");
        self::assertResponseIsSuccessful();

        $casos = [
            [$pdf, 'bi-file-earmark-pdf-fill', '1 KB', '1 KB'],
            [$png, 'bi-file-earmark-image-fill', '2,5 MB', '2,5 MB'],
            [$texto, 'bi-file-earmark-text-fill', 'Texto', '1 KB'],
        ];
        foreach ($casos as [$doc, $icone, $rotulo, $tamanhoBarra]) {
            $row = $this->linha($crawler, (int) $doc->getId());

            $i = $row->filter('.petic-tl-card > .petic-tl-card-top > i.petic-doc-icone');
            self::assertCount(1, $i);
            self::assertStringContainsString($icone, (string) $i->attr('class'));

            $tamanho = $row->filter('.petic-tl-card > .petic-tl-card-bottom > .petic-doc-tamanho');
            self::assertCount(1, $tamanho);
            self::assertSame($rotulo, trim($tamanho->text()));
            self::assertSame($tamanhoBarra, $row->attr('data-tamanho-texto'));
        }

        // A peça de texto aparece sem o ".html" que o editor acrescenta ao nome.
        self::assertSame('Minha Petição', $this->linha($crawler, (int) $texto->getId())->attr('data-titulo'));
    }

    #[TestDox('a barra do documento selecionado fica no topo do preview, com Baixar e Excluir')]
    public function testBarraDoSelecionado(): void
    {
        [$client, $pasta, $tenant] = $this->cenario();
        $this->documento($pasta, $tenant, 'contrato.pdf', 'application/pdf', 1024);

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");
        self::assertResponseIsSuccessful();

        $barra = $crawler->filter('.petic-layout > #previewPanel > #peticSelbar');
        self::assertCount(1, $barra);
        self::assertCount(1, $barra->filter('#peticSelbar > b.petic-selbar-nome[title="Clique duas vezes para renomear"]'));
        self::assertCount(1, $barra->filter('#peticSelbar > .petic-selbar-sub'));
        self::assertSame('Baixar', $barra->filter('#peticSelbar > button[data-acao="baixar"]')->attr('title'));
        self::assertSame('Excluir peça', $barra->filter('#peticSelbar > button[data-acao="excluir"]')->attr('title'));
    }

    #[TestDox('lista vazia mostra a dica do desenho')]
    public function testDicaDaListaVazia(): void
    {
        [$client, $pasta] = $this->cenario();

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");
        self::assertResponseIsSuccessful();

        $vazio = $crawler->filter('#timelineList > #tlInner > .petic-tl-vazio');
        self::assertCount(1, $vazio);
        self::assertStringContainsString('Nenhum documento na pasta.', $vazio->text());
        self::assertCount(1, $vazio->filter('.petic-tl-vazio > i.bi-folder2-open'));
        self::assertSame(
            'Use "Adicionar peça" ou arraste arquivos para cá.',
            trim($vazio->filter('.petic-tl-vazio > .petic-tl-vazio-dica')->text()),
        );
    }

    #[TestDox('o modal "Adicionar peça" continua com os mesmos formatos aceitos')]
    public function testFormatosDoModalNaoMudaram(): void
    {
        [$client, $pasta] = $this->cenario();

        $crawler = $client->request('GET', "/pasta/{$pasta->getId()}/peticionar");
        self::assertResponseIsSuccessful();

        self::assertSame(
            '.pdf,.png,.jpg,.jpeg,.doc,.docx,.xls,.xlsx,.xml,.kml',
            $crawler->filter('#uploadArquivo')->attr('accept'),
        );
        $icones = json_decode((string) $crawler->filter('#timelineList')->attr('data-icones'), true);
        self::assertSame('bi-file-earmark-word-fill', $icones['docx']['icone'] ?? null, 'a linha nova usa o ícone do arquivo_icone()');
    }

    // ----------------------------------------------------------------- helpers

    private function linha(Crawler $crawler, int $id): Crawler
    {
        $row = $crawler->filter(sprintf('#tlInner > .petic-tl-row[data-doc-id="%d"]', $id));
        self::assertCount(1, $row, "linha do documento {$id}");

        return $row;
    }

    /** @return array{KernelBrowser, Pasta, Tenant, User} */
    private function cenario(): array
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->instalarCsrfStorage();

        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant PET07 ' . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('pet07_' . uniqid() . '@test.com');
        $user->setFullName('Gestor Peticionar');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($container->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Gestor ' . uniqid());
        $role->setIsSystem(true);
        $em->persist($role);

        $vinculo = new UserTenant($user, $tenant);
        $vinculo->setTenantRole($role);
        $em->persist($vinculo);

        $pasta = new Pasta();
        $pasta->setNup('PET07-' . uniqid());
        $pasta->setTenant($tenant);
        $em->persist($pasta);
        $em->flush();

        $this->logarComTenant($client, $user, $tenant);

        return [$client, $pasta, $tenant, $user];
    }

    private function documento(Pasta $pasta, Tenant $tenant, string $nome, string $mime, int $bytes, ?string $descricao = null, ?string $numero = null): PastaDocumento
    {
        $em  = static::getContainer()->get(EntityManagerInterface::class);
        $doc = new PastaDocumento();
        $doc->setPasta($pasta);
        $doc->setTenant($tenant);
        $doc->setTitulo($mime === 'text/html' ? substr($nome, 0, -5) : $nome);
        $doc->setNomeOriginal($nome);
        $doc->setCategoria(PastaDocumento::CATEGORIA_PECA);
        $doc->setCaminhoArquivo('pet07_' . uniqid() . '.bin');
        $doc->setMimeType($mime);
        $doc->setTamanhoBytes($bytes);
        $doc->setDescricao($descricao);
        $doc->setNumero($numero);
        $pasta->addDocumento($doc);
        $em->persist($doc);
        $em->flush();

        return $doc;
    }

    private function instalarCsrfStorage(): void
    {
        $storage = new class () implements ClearableTokenStorageInterface {
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
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    /** O gerenciador real mascara o valor (BREACH); a prova é ele aceitar o token para aquele id. */
    private static function assertTokenValido(string $idDoToken, mixed $valor): void
    {
        self::assertIsString($valor);
        self::assertNotSame('', $valor);
        self::assertTrue(
            static::getContainer()->get('security.csrf.token_manager')->isTokenValid(new CsrfToken($idDoToken, $valor)),
            "token da tela não vale para '{$idDoToken}'",
        );
    }
}
