<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\Entity\Pasta;
use App\Pasta\Entity\PastaDocumento;
use App\Pasta\Entity\PastaSecao;
use App\Pasta\Service\SugestorDeDocumentos;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * DOC-106: a seção "Possíveis arquivos duplicados" do painel "Documentos sugeridos" (dc
 * L2157-2159; bj-docsug.js L107-109) — ícone `bi-files`, título em negrito, uma linha por par no
 * formato "a  ≈  b (N% parecido; confira se é o mesmo documento)".
 *
 * Arranjo pelo combinador de filho direto: a seção é filha do corpo do painel, logo depois do
 * último grupo de status e logo antes de "Organização sugerida para esta fase", como no desenho.
 *
 * O par é sobre TODOS os arquivos vivos da pasta (qualquer seção); a lixeira e o arquivo de outro
 * escritório não entram, e sem par a seção não existe.
 */
#[CoversClass(SugestorDeDocumentos::class)]
final class PastaDocumentosSugeridosDuplicadosTest extends JusPrimeWebTestCase
{
    private int $ordem = 0;

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{User, Tenant} */
    private function criarUsuarioAdmin(string $sufixo = ''): array
    {
        $em = $this->em();

        $tenant = new Tenant();
        $tenant->setName('Tenant DocDup ' . $sufixo . uniqid());
        $em->persist($tenant);

        $user = new User();
        $user->setEmail('test_docdup_' . $sufixo . uniqid() . '@test.com');
        $user->setFullName('Admin DocDup');
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setIsActive(true);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, 'senha123'));
        $em->persist($user);
        $em->persist(new UserTenant($user, $tenant));
        $em->flush();

        return [$user, $tenant];
    }

    private function criarPasta(Tenant $tenant, ?string $nomeAcao): Pasta
    {
        $pasta = new Pasta();
        $pasta->setNup('TEST-DOCDUP-' . uniqid());
        $pasta->setTenant($tenant);
        $pasta->setNomeAcao($nomeAcao);
        $this->em()->persist($pasta);
        $this->em()->flush();

        return $pasta;
    }

    private function criarSecao(Pasta $pasta, Tenant $tenant, string $nome): PastaSecao
    {
        $secao = new PastaSecao();
        $secao->setPasta($pasta);
        $secao->setTenant($tenant);
        $secao->setNome($nome);
        $secao->setOrdem(1);
        $this->em()->persist($secao);
        $this->em()->flush();

        return $secao;
    }

    private function anexar(Pasta $pasta, Tenant $tenant, string $nomeOriginal, ?PastaSecao $secao = null): PastaDocumento
    {
        // Depois de `abrir()` (que faz clear) a pasta e o escritório ficam desanexados: religa por referência.
        $em     = $this->em();
        $pasta  = $em->contains($pasta) ? $pasta : $em->getReference(Pasta::class, $pasta->getId());
        $tenant = $em->contains($tenant) ? $tenant : $em->getReference(Tenant::class, $tenant->getId());
        if ($secao !== null && !$em->contains($secao)) {
            $secao = $em->getReference(PastaSecao::class, $secao->getId());
        }
        $doc = (new PastaDocumento())
            ->setTenant($tenant)
            ->setPasta($pasta)
            ->setTitulo($nomeOriginal)
            ->setCategoria(PastaDocumento::CATEGORIA_DEMAIS)
            ->setCaminhoArquivo(bin2hex(random_bytes(16)) . '.pdf')
            ->setNomeOriginal($nomeOriginal)
            ->setMimeType('application/pdf')
            ->setTamanhoBytes(10);
        $doc->setSecao($secao);
        // A coleção vem por `ordem` ASC: ordem crescente deixa o "a antes de b" determinístico.
        $doc->setOrdem(++$this->ordem);
        $this->em()->persist($doc);
        $this->em()->flush();

        return $doc;
    }

    private function abrir(KernelBrowser $client, int $pastaId): Crawler
    {
        // Sem o clear a pasta segue no cache do Doctrine sem os documentos recém-anexados.
        $this->em()->clear();
        $crawler = $client->request('GET', "/pasta/{$pastaId}");
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @return list<string> */
    private static function pares(Crawler $crawler): array
    {
        return $crawler->filter('#documentosSugeridosCorpo > .ds-sug-dup > .ds-sug-dup-par')
            ->each(static fn (Crawler $c): string => (string) preg_replace('/\s+/u', ' ', trim($c->text())));
    }

    #[TestDox('o par ≥ 85% aparece com ícone, título e o texto do desenho; arquivo de outra seção também conta')]
    public function testParApareceComOTextoDoDesenho(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, 'Indenização');
        $secao           = $this->criarSecao($pasta, $tenant, 'Procurações');
        $this->anexar($pasta, $tenant, 'PROCURAÇÃO_GLEISSON_BRUNO.pdf');
        $this->anexar($pasta, $tenant, 'Petição inicial.pdf');
        $this->anexar($pasta, $tenant, 'procuracao_gleisson_assinada.pdf', $secao);
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, (int) $pasta->getId());

        $titulo = $crawler->filter('#documentosSugeridosCorpo > .ds-sug-dup > .ds-sug-dup-titulo');
        self::assertSame(1, $titulo->count());
        self::assertSame('Possíveis arquivos duplicados', trim($titulo->text()));
        self::assertSame(1, $titulo->filter('i.bi.bi-files')->count(), 'ícone do desenho (bi-files)');
        self::assertSame(
            ['PROCURAÇÃO_GLEISSON_BRUNO.pdf ≈ procuracao_gleisson_assinada.pdf (100% parecido; confira se é o mesmo documento)'],
            self::pares($crawler),
            'a pasta inteira entra, não só o nível aberto',
        );
    }

    #[TestDox('arranjo: filha do corpo, logo depois do último grupo e logo antes da organização sugerida; o rodapé vem depois')]
    public function testArranjoDaSecao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, 'Indenização');
        $this->anexar($pasta, $tenant, 'Contrato social da empresa.pdf');
        $this->anexar($pasta, $tenant, 'Contrato social da empresa assinado.pdf');
        $this->logarComTenant($client, $user, $tenant);

        $crawler = $this->abrir($client, (int) $pasta->getId());

        self::assertSame(1, $crawler->filter('#documentosSugeridos .ds-sug-dup')->count(), 'uma seção só');
        self::assertSame(1, $crawler->filter('#documentosSugeridosCorpo > .ds-sug-grupo + .ds-sug-dup')->count(), 'logo depois do último grupo de status');
        self::assertSame(0, $crawler->filter('#documentosSugeridosCorpo > .ds-sug-dup ~ .ds-sug-grupo')->count(), 'nenhum grupo depois dela');
        self::assertSame(1, $crawler->filter('#documentosSugeridosCorpo > .ds-sug-dup + .ds-sug-org')->count(), 'logo antes de "Organização sugerida" (dc L2160)');
        self::assertSame(1, $crawler->filter('#documentosSugeridosCorpo > .ds-sug-dup ~ .ds-sug-rodape')->count());
    }

    #[TestDox('sem par não há seção: nomes diferentes, nome idêntico (é o mesmo nome, não par) e pasta vazia')]
    public function testSemParNaoHaSecao(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $diferentes      = $this->criarPasta($tenant, 'Indenização');
        $this->anexar($diferentes, $tenant, 'Petição inicial.pdf');
        $this->anexar($diferentes, $tenant, 'Comprovante de residência.pdf');
        $identicos       = $this->criarPasta($tenant, 'Indenização');
        $this->anexar($identicos, $tenant, 'Contrato de honorários.pdf');
        $this->anexar($identicos, $tenant, 'CONTRATO DE HONORÁRIOS.pdf');
        $vazia           = $this->criarPasta($tenant, 'Indenização');
        $this->logarComTenant($client, $user, $tenant);

        foreach ([$diferentes, $identicos, $vazia] as $pasta) {
            $crawler = $this->abrir($client, (int) $pasta->getId());
            self::assertSame(1, $crawler->filter('#documentosSugeridos')->count(), 'premissa: o painel existe');
            self::assertSame(0, $crawler->filter('#documentosSugeridos .ds-sug-dup')->count());
            self::assertStringNotContainsString('Possíveis arquivos duplicados', $crawler->filter('#documentosSugeridos')->text());
        }
    }

    #[TestDox('a lixeira fica fora: o par com um arquivo excluído não aparece; o par entre vivos, sim')]
    public function testLixeiraFicaFora(): void
    {
        $client          = static::createClient();
        [$user, $tenant] = $this->criarUsuarioAdmin();
        $pasta           = $this->criarPasta($tenant, 'Indenização');
        $this->anexar($pasta, $tenant, 'Procuração Maria Silva.pdf');
        $excluido = $this->anexar($pasta, $tenant, 'procuracao maria silva assinada.pdf');
        $excluido->marcarExcluido($user, new \DateTimeImmutable('-1 hour'));
        $this->em()->flush();
        $this->logarComTenant($client, $user, $tenant);

        self::assertSame([], self::pares($this->abrir($client, (int) $pasta->getId())));

        // Prova do guarda: o MESMO par, com os dois vivos, aparece.
        $this->anexar($pasta, $tenant, 'procuracao maria silva final.pdf');
        self::assertSame(
            ['Procuração Maria Silva.pdf ≈ procuracao maria silva final.pdf (100% parecido; confira se é o mesmo documento)'],
            self::pares($this->abrir($client, (int) $pasta->getId())),
        );
    }

    #[TestDox('isolamento: documento de outro escritório pendurado na pasta não forma par')]
    public function testDocumentoDeOutroEscritorioNaoFormaPar(): void
    {
        $client          = static::createClient();
        [$user, $tA]     = $this->criarUsuarioAdmin('a');
        [, $tB]          = $this->criarUsuarioAdmin('b');
        $pasta           = $this->criarPasta($tA, 'Indenização');
        $this->anexar($pasta, $tA, 'Laudo médico ortopedia.pdf');
        $this->anexar($pasta, $tB, 'laudo medico ortopedia assinado.pdf');
        $this->logarComTenant($client, $user, $tA);

        $crawler = $this->abrir($client, (int) $pasta->getId());
        self::assertSame(1, $crawler->filter('#documentosSugeridos')->count(), 'premissa: o painel existe');
        self::assertSame([], self::pares($crawler));

        // Prova do guarda: o par existe quando os dois são do escritório.
        $this->anexar($pasta, $tA, 'laudo medico ortopedia copia.pdf');
        self::assertSame(
            ['Laudo médico ortopedia.pdf ≈ laudo medico ortopedia copia.pdf (100% parecido; confira se é o mesmo documento)'],
            self::pares($this->abrir($client, (int) $pasta->getId())),
        );
    }
}
