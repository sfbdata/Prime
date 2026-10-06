<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaChecklistItem;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Twig\DocumentosSugeridosExtension;
use App\Processo\Entity\Processo;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Csrf\TokenStorage\ClearableTokenStorageInterface;
use Zenstruck\Foundry\Test\Factories;

/**
 * Painel "Documentos sugeridos" da aba Documentos da pasta: aparece onde o desenho manda (dentro
 * do cartão do checklist, aberto pelo botão do cabeçalho dele), com rótulo honesto, e o "Adicionar
 * faltantes" grava DE VERDADE pelo endpoint do checklist, com o token que o próprio painel entrega.
 *
 * Os textos gravados passam pelos setters das entidades, que gravam em MAIÚSCULAS: as asserções
 * comparam com o getter, nunca com o texto digitado.
 */
#[CoversClass(DocumentosSugeridosExtension::class)]
final class PastaDocumentosSugeridosTest extends JusPrimeWebTestCase
{
    use Factories;

    private bool $csrfInstalado = false;

    /** Mesma receita do `PastaChecklistModeloControllerTest`: uma vez só, antes dos dois escritórios. */
    private function instalarCsrfStorage(): void
    {
        if ($this->csrfInstalado) {
            return;
        }
        $this->csrfInstalado = true;

        $storage = new class implements ClearableTokenStorageInterface {
            public function getToken(string $tokenId): string { return 'TOKEN_' . $tokenId; }
            public function setToken(string $tokenId, string $token): void {}
            public function removeToken(string $tokenId): ?string { return null; }
            public function hasToken(string $tokenId): bool { return true; }
            public function clear(): void {}
        };

        static::getContainer()->set('security.csrf.token_storage', $storage);
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{User, Tenant} */
    private function criarUsuarioAdmin(string $sufixo = ''): array
    {
        $this->instalarCsrfStorage();

        $em     = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $tenant = new Tenant();
        $tenant->setName('Tenant DocSug ' . $sufixo . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_docsug_' . $sufixo . uniqid() . '@test.com');
        $user->setFullName('Admin DocSug');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant, ?string $nomeAcao): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('TEST-DOCSUG-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setNomeAcao($nomeAcao);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function anexar(Pasta $pasta, Tenant $tenant, string $titulo, string $categoria = PastaDocumento::CATEGORIA_DEMAIS): void
    {
        $doc = (new PastaDocumento())
            ->setTenant($tenant)
            ->setPasta($pasta)
            ->setTitulo($titulo)
            ->setCategoria($categoria)
            ->setCaminhoArquivo(bin2hex(random_bytes(16)) . '.pdf')
            ->setNomeOriginal('arquivo.pdf')
            ->setMimeType('application/pdf')
            ->setTamanhoBytes(10);
        $this->em()->persist($doc);
        $this->em()->flush();
    }

    private function vincularProcesso(Pasta $pasta, Tenant $tenant, string $classe): void
    {
        $processo = new Processo();
        $processo->setNumeroProcesso('0001234-56.2026.8.07.' . random_int(1000, 9999));
        $processo->setClasseProcessual($classe);
        $processo->setTenant($tenant);
        $this->em()->persist($processo);
        $this->em()->persist($pasta->vincularProcesso($processo));
        $this->em()->flush();
    }

    /**
     * Títulos gravados, lidos pelo SQL cru: o TenantFilter fica fora da conferência (no teste
     * cross-tenant ele esconderia justamente a linha que se quer provar que NÃO existe).
     *
     * @return list<string>
     */
    private function titulosDoChecklist(int $pastaId): array
    {
        return array_values(array_map('strval', $this->em()->getConnection()->fetchFirstColumn(
            'SELECT titulo FROM pasta_checklist_item WHERE pasta_id = :pasta ORDER BY ordem, id',
            ['pasta' => $pastaId],
        )));
    }

    private function abrir(KernelBrowser $client, int $pastaId): Crawler
    {
        // Sem o clear a pasta segue no cache do Doctrine sem os documentos recém-anexados
        // (lado inverso da relação) e a tela leria uma coleção velha.
        $this->em()->clear();
        $crawler = $client->request('GET', "/pasta/{$pastaId}");
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    #[TestDox('o painel aparece com o rótulo de catálogo, a fase e os faltantes; o que já está na pasta não é faltante')]
    public function testPainelApareceComFaltantes(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, 'Ação de indenização por danos morais');
        $this->anexar($pasta, $tenant, 'Procuração ad judicia');
        $pastaId  = (int) $pasta->getId();
        $nomeAcao = (string) $pasta->getNomeAcao();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, $pastaId);
        $painel  = $crawler->filter('#documentosSugeridos');

        self::assertSame(1, $painel->count());
        self::assertStringContainsString('Sugestões pelo catálogo de documentos da fase e do tipo de ação', $painel->text());
        self::assertStringNotContainsString('IA', $painel->filter('.ds-sug-cab')->text(), 'o rótulo não pode vender isto como inteligência artificial');
        self::assertSame('AÇÃO DE INDENIZAÇÃO POR DANOS MORAIS', $nomeAcao, 'premissa: o setter grava em maiúsculas');
        self::assertStringContainsString('Ação: ' . mb_strtolower($nomeAcao), $painel->filter('.ds-sug-resumo')->text());
        self::assertSame('Fase inicial', trim($painel->filter('.ds-sug-fase')->text()));

        self::assertSame(1, $painel->filter('.ds-sug-grupo[data-status="existe"] .ds-sug-item[data-chave="procuracao"]')->count());

        $faltantes = json_decode((string) $painel->filter('#btnDocumentosSugeridosFaltantes')->attr('data-titulos'), true);
        self::assertSame(
            ['Documento de identidade', 'Comprovante de residência', 'Petição inicial', 'Provas do fato (fotos, vídeos, laudos)'],
            $faltantes,
        );
        self::assertStringContainsString('Adicionar 4 faltante(s) ao checklist', $painel->filter('#btnDocumentosSugeridosFaltantes')->text());
    }

    #[TestDox('"Adicionar faltantes" grava pelo endpoint do checklist com o token do painel; depois, nada mais falta')]
    public function testAdicionarFaltantesCriaItensNoChecklist(): void
    {
        $client          = static::createClient();
        // GET + 5 POSTs + GET: sem isto o kernel reinicia entre as requisições e o
        // armazenamento falso de CSRF some depois da primeira.
        $client->disableReboot();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, 'Indenização');
        $pastaId         = (int) $pasta->getId();
        $this->logarComTenant($client, $user, $tenant);

        $painel  = $this->abrir($client, $pastaId)->filter('#documentosSugeridos');
        $url     = (string) $painel->attr('data-url-adicionar');
        $token   = (string) $painel->attr('data-csrf');
        $titulos = json_decode((string) $painel->filter('#btnDocumentosSugeridosFaltantes')->attr('data-titulos'), true);

        self::assertSame("/pasta/{$pastaId}/checklist", $url);
        self::assertNotSame('', $token);
        self::assertCount(5, $titulos);

        foreach ($titulos as $titulo) {
            $client->request('POST', $url, ['titulo' => $titulo, '_token' => $token], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
            self::assertResponseStatusCodeSame(201);
        }

        $gravados  = $this->titulosDoChecklist($pastaId);
        $esperados = array_map(
            static fn (string $t): string => (new PastaChecklistItem())->setTitulo($t)->getTitulo(),
            $titulos,
        );
        self::assertSame($esperados, $gravados, 'um item por faltante, na ordem do painel (o setter grava em maiúsculas)');

        $painelDepois = $this->abrir($client, $pastaId)->filter('#documentosSugeridos');
        self::assertSame(0, $painelDepois->filter('#btnDocumentosSugeridosFaltantes')->count(), 'nada mais falta');
        self::assertSame(5, $painelDepois->filter('.ds-sug-no-ck')->count(), 'cada faltante agora aparece como "já no checklist"');
        self::assertSame(0, $painelDepois->filter('.ds-sug-grupo[data-status="req"] .ds-sug-add-um, .ds-sug-grupo[data-status="rec"] .ds-sug-add-um')->count());
    }

    #[TestDox('sem o token do checklist o POST é recusado e nada é gravado')]
    public function testSemTokenNaoGrava(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, 'Indenização');
        $pastaId         = (int) $pasta->getId();
        $this->logarComTenant($client, $user, $tenant);

        $client->request('POST', "/pasta/{$pastaId}/checklist", ['titulo' => 'Procuração', '_token' => 'errado']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->titulosDoChecklist($pastaId));
    }

    #[TestDox('pasta sem ação e sem processo: o painel diz que não há catálogo e não oferece nada para adicionar')]
    public function testSemCatalogo(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, null);
        $this->logarComTenant($client, $user, $tenant);

        $painel = $this->abrir($client, (int) $pasta->getId())->filter('#documentosSugeridos');

        self::assertSame(1, $painel->filter('.ds-sug-sem-catalogo')->count());
        self::assertStringContainsString('Não há catálogo de documentos para esta pasta', $painel->text());
        self::assertSame(0, $painel->filter('.ds-sug-item')->count());
        self::assertSame(0, $painel->filter('#btnDocumentosSugeridosFaltantes')->count());
    }

    #[TestDox('processo vinculado com classe de cumprimento muda o catálogo para a fase de cumprimento')]
    public function testClasseDoProcessoEscolheAFase(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, null);
        $this->vincularProcesso($pasta, $tenant, 'Cumprimento de sentença');
        $this->logarComTenant($client, $user, $tenant);

        $painel = $this->abrir($client, (int) $pasta->getId())->filter('#documentosSugeridos');

        self::assertSame('Cumprimento de sentença', trim($painel->filter('.ds-sug-fase')->text()));
        self::assertSame(1, $painel->filter('.ds-sug-grupo[data-status="req"] .ds-sug-item[data-chave="calculo"]')->count());
        self::assertSame(1, $painel->filter('.ds-sug-grupo[data-status="na"] .ds-sug-item[data-chave="acordao"]')->count());
    }

    #[TestDox('pasta de outro escritório: a tela responde 404 e o POST do checklist também, sem gravar')]
    public function testPastaDeOutroEscritorio(): void
    {
        $client       = static::createClient();
        $client->disableReboot();
        [$user, $tA]  = $this->criarUsuarioAdmin('a');
        [, $tB]       = $this->criarUsuarioAdmin('b');
        $pastaB       = $this->criarPasta($tB, 'Indenização');
        $idPastaB     = (int) $pastaB->getId();
        $this->logarComTenant($client, $user, $tA);

        $this->em()->clear();
        $client->request('GET', "/pasta/{$idPastaB}");
        self::assertResponseStatusCodeSame(404);

        $this->em()->clear();
        $client->request('POST', "/pasta/{$idPastaB}/checklist", [
            'titulo' => 'Procuração',
            '_token' => 'TOKEN_checklist_pasta_' . $idPastaB,
        ]);
        self::assertResponseStatusCodeSame(404);

        self::assertSame([], $this->titulosDoChecklist($idPastaB));
    }

    // ── Arranjo (combinador de filho direto) ────────────────────────────────────

    #[TestDox('o painel mora DENTRO do cartão do checklist, 1º filho do corpo (dc L2112) — e não existe outro fora dele')]
    public function testPainelDentroDoCartaoDoChecklist(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, 'Indenização');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, (int) $pasta->getId());

        self::assertSame(1, $crawler->filter('#pexChecklist > .pex-ck-corpo > #documentosSugeridos')->count(), 'o painel abre dentro do cartão do checklist, como no desenho');
        self::assertSame(1, $crawler->filter('#pexChecklist > .pex-ck-corpo > #documentosSugeridos:first-child')->count(), 'logo abaixo do cabeçalho, antes da faixa de adicionar e dos itens');
        self::assertSame(1, $crawler->filter('#documentosSugeridos')->count(), 'um painel só na página: 0 fora do checklist');
        self::assertSame(0, $crawler->filter('#documentos > #documentosSugeridos')->count(), 'o cartão próprio acima do explorador acabou');
        self::assertSame(1, $crawler->filter('#pexChecklist > .pex-ck-corpo > #documentosSugeridos ~ #checklistLista')->count(), 'os itens vêm depois do painel');
    }

    #[TestDox('"Sugerir documentos" fica no cabeçalho do checklist, com rótulo neutro; o painel nasce fechado, com Atualizar e fechar no cabeçalho dele')]
    public function testArranjoInternoDoPainel(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, 'Indenização');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, (int) $pasta->getId());

        $botao = $crawler->filter('#pexChecklist > .pex-ck-cab > #btnDocumentosSugeridos');
        self::assertSame(1, $botao->count(), 'o botão é do cabeçalho do checklist (dc L2089)');
        self::assertSame('Sugerir documentos', trim($botao->text()));
        self::assertSame(1, $botao->filter('i.bi-list-check')->count(), 'ícone neutro (S-4), não o ✦');
        self::assertSame('documentosSugeridos', $botao->attr('aria-controls'));
        self::assertSame('false', $botao->attr('aria-expanded'));
        self::assertSame(0, $crawler->filter('#pexChecklist .pex-ck-cab .pex-ck-acao#btnDocumentosSugeridos')->count(), 'não é uma das três ações de ícone (modelos, editar, adicionar)');

        $painel = $crawler->filter('#documentosSugeridos');
        self::assertStringContainsString('d-none', (string) $painel->attr('class'), 'nasce fechado: quem abre é "Sugerir documentos"');
        self::assertSame(1, $crawler->filter('#documentosSugeridos > .ds-sug-cab > #btnDocumentosSugeridosAtualizar')->count(), '"Atualizar" do desenho (L2127)');
        self::assertSame(1, $crawler->filter('#documentosSugeridos > .ds-sug-cab > #btnDocumentosSugeridosFechar')->count());

        $corpo = $crawler->filter('#documentosSugeridos > #documentosSugeridosCorpo');
        self::assertSame(1, $corpo->count());
        self::assertSame(1, $crawler->filter('#documentosSugeridosCorpo > .ds-sug-rodape > #btnDocumentosSugeridosFaltantes')->count());
        self::assertGreaterThan(0, $crawler->filter('#documentosSugeridosCorpo > .ds-sug-grupo > .ds-sug-item')->count());
        self::assertSame(
            $crawler->filter('#documentosSugeridosCorpo .ds-sug-item')->count(),
            $crawler->filter('#documentosSugeridosCorpo > .ds-sug-grupo > .ds-sug-item')->count(),
            'todo item mora num grupo de status',
        );
        self::assertSame(1, $crawler->filter('#documentosSugeridosCorpo > .ds-sug-grupo[data-status="req"] > .ds-sug-item[data-chave="procuracao"] > .ds-sug-add-um')->count());
    }

    #[TestDox('nenhum "IA", "BlueJus IA", ✦ nem "Análise documental" no cartão do checklist: o que roda é regra')]
    public function testCartaoSemRotuloDeInteligencia(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, 'Indenização');
        $this->anexar($pasta, $tenant, 'Procuração');
        $this->logarComTenant($client, $user, $tenant);

        $cartao = $this->abrir($client, (int) $pasta->getId())->filter('#pexChecklist');
        self::assertSame(1, $cartao->count());

        $texto = $cartao->text();
        self::assertDoesNotMatchRegularExpression('/\bIA\b/u', $texto, 'regra de catálogo não é inteligência artificial');
        self::assertStringNotContainsString('✦', $cartao->html());
        self::assertStringNotContainsString('Análise documental', $texto);
        self::assertStringContainsString('por regras', $texto, 'o rótulo diz o que é');
        self::assertSame(0, $cartao->filter('.bi-stars')->count());
    }

    #[TestDox('organização sugerida: lista da fase, por regras, nasce fechada; muda com a fase do processo')]
    public function testOrganizacaoSugeridaPorFase(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $inicial         = $this->criarPasta($tenant, 'Indenização');
        $cumprimento     = $this->criarPasta($tenant, null);
        $this->vincularProcesso($cumprimento, $tenant, 'Cumprimento de sentença');
        $idInicial       = (int) $inicial->getId();
        $idCumprimento   = (int) $cumprimento->getId();
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, $idInicial);
        $botao   = $crawler->filter('#documentosSugeridosCorpo > .ds-sug-org > #btnDocumentosSugeridosOrganizacao');
        self::assertSame(1, $botao->count());
        self::assertStringContainsString('Organização sugerida para esta fase', $botao->text());
        self::assertStringContainsString('por regras', $botao->text());
        self::assertSame('false', $botao->attr('aria-expanded'));

        $pastas = $crawler->filter('#documentosSugeridosCorpo > .ds-sug-org > #documentosSugeridosPastas');
        self::assertNotNull($pastas->attr('hidden'), 'nasce fechada');
        self::assertSame(
            ['01 Processo', '02 Petições', '03 Decisões', '04 Documentos das partes', '05 Provas', '06 Prazos', 'Encerramento'],
            $pastas->filter('.ds-sug-org-pasta')->each(static fn (Crawler $c): string => trim($c->text())),
        );

        $doCumprimento = $this->abrir($client, $idCumprimento)->filter('#documentosSugeridosPastas .ds-sug-org-pasta')
            ->each(static fn (Crawler $c): string => trim($c->text()));
        self::assertContains('07 Cálculos', $doCumprimento);
        self::assertContains('09 Pagamentos', $doCumprimento);
    }

    #[TestDox('pasta sem catálogo não mostra organização sugerida')]
    public function testSemCatalogoSemOrganizacao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, null);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, (int) $pasta->getId());

        self::assertSame(0, $crawler->filter('#documentosSugeridos .ds-sug-org')->count());
    }

    #[TestDox('arquivo com outro número CNJ acende o aviso âmbar dentro do painel; só com o número do cadastro, não')]
    public function testConflitoDeNumeroDeProcesso(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $comConflito     = $this->criarPasta($tenant, 'Indenização');
        $semConflito     = $this->criarPasta($tenant, 'Indenização');
        $this->vincularProcesso($comConflito, $tenant, 'Procedimento comum');
        $this->vincularProcesso($semConflito, $tenant, 'Procedimento comum');
        $this->anexar($comConflito, $tenant, 'Sentença 0009999-11.2024.8.26.0100');
        $this->anexar($semConflito, $tenant, 'Procuração');
        $idCom = (int) $comConflito->getId();
        $idSem = (int) $semConflito->getId();
        $this->logarComTenant($client, $user, $tenant);

        $aviso = $this->abrir($client, $idCom)->filter('#documentosSugeridosCorpo > .ds-sug-conflito');
        self::assertSame(1, $aviso->count());
        self::assertStringContainsString('0009999-11.2024.8.26.0100', $aviso->text());
        self::assertStringContainsString('confirme qual é o processo desta pasta', $aviso->text());

        self::assertSame(0, $this->abrir($client, $idSem)->filter('#documentosSugeridos .ds-sug-conflito')->count());
    }
}
