<?php

declare(strict_types=1);

namespace App\Tests\Master\Functional;

use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Master\Entity\LancamentoDeCusto;
use App\Master\Enum\CategoriaDeCusto;
use App\Master\Enum\OrigemDoCusto;
use App\Tests\Factory\Auth\UserFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Test\Factories;

/** Gravação da seção Custos (spec master-custos PR 3 e 4): só Master, CSRF, validação, copiar, excluir, importar e exportar. */
final class CustosMasterAcoesControllerTest extends JusPrimeWebTestCase
{
    use Factories;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[TestDox('quem não é Master recebe 403')]
    public function testUsuarioComumNaoEntra(): void
    {
        $this->entrar(['ROLE_USER']);

        $this->enviar('/master/custos/lancamentos', [], 'qualquer');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('sem token CSRF válido recusa e não grava')]
    public function testCsrfInvalido(): void
    {
        $this->entrar(['ROLE_SUPER_ADMIN']);
        $antes = $this->em->getRepository(LancamentoDeCusto::class)->count([]);

        $this->enviar('/master/custos/lancamentos', $this->corpo(), 'errado');

        self::assertResponseStatusCodeSame(403);
        self::assertSame($antes, $this->em->getRepository(LancamentoDeCusto::class)->count([]));
    }

    #[TestDox('lança um custo (valor em reais vira centavos) e exclui')]
    public function testLancarEExcluir(): void
    {
        $token = $this->entrar(['ROLE_SUPER_ADMIN']);

        $r = $this->enviar('/master/custos/lancamentos', $this->corpo(), $token);
        self::assertResponseStatusCodeSame(201);
        $this->em->clear();
        $lancamento = $this->em->find(LancamentoDeCusto::class, $r['id']);
        self::assertSame(123456, $lancamento->getValorCentavos());
        self::assertSame('AWS', $lancamento->getFornecedor());

        $this->enviar('/master/custos/lancamentos/' . $r['id'] . '/excluir', [], $token);
        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertNull($this->em->find(LancamentoDeCusto::class, $r['id']));

        $this->enviar('/master/custos/lancamentos/' . $r['id'] . '/excluir', [], $token);
        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('sem valor devolve 422 com a mensagem do desenho')]
    public function testSemValor(): void
    {
        $token = $this->entrar(['ROLE_SUPER_ADMIN']);

        $r = $this->enviar('/master/custos/lancamentos', ['valor' => ''] + $this->corpo(), $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Informe o valor mensal.', $r['erro']);
    }

    #[TestDox('copia o mês anterior: medido vira estimado; sem lançamento no anterior dá 422')]
    public function testCopiar(): void
    {
        $token = $this->entrar(['ROLE_SUPER_ADMIN']);
        $this->em->persist(new LancamentoDeCusto('2032-05', CategoriaDeCusto::Backups, 1000, OrigemDoCusto::Medido, 'B2', null, new \DateTimeImmutable()));
        $this->em->persist(new LancamentoDeCusto('2032-05', CategoriaDeCusto::Outros, 500, OrigemDoCusto::Importado, null, null, new \DateTimeImmutable()));
        $this->em->flush();

        $r = $this->enviar('/master/custos/copiar', ['mes' => '2032-06'], $token);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(2, $r['copiados']);
        $copias = $this->em->getRepository(LancamentoDeCusto::class)->doMes('2032-06');
        $origens = array_map(static fn (LancamentoDeCusto $l): string => $l->getOrigem()->value, $copias);
        sort($origens);
        self::assertSame(['estimado', 'importado'], $origens);

        $this->enviar('/master/custos/copiar', ['mes' => '2032-08'], $token);
        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('importa o CSV como importado e conta as linhas ignoradas; sem linha válida dá 422')]
    public function testImportar(): void
    {
        $token = $this->entrar(['ROLE_SUPER_ADMIN']);
        $antes = $this->em->getRepository(LancamentoDeCusto::class)->count(['mes' => '2033-03']);

        $r = $this->importar("Mês;Categoria;Fornecedor;Valor\n2033-03;Armazenamento;B2;12,50\n2033-03;Outros;Zero;0\n", $token);

        self::assertResponseStatusCodeSame(201);
        self::assertSame(['importados' => 1, 'ignoradas' => 1], $r);
        $this->em->clear();
        $novos = $this->em->getRepository(LancamentoDeCusto::class)->doMes('2033-03');
        self::assertCount($antes + 1, $novos);
        self::assertSame(OrigemDoCusto::Importado, $novos[0]->getOrigem());
        self::assertSame(1250, $novos[0]->getValorCentavos());

        $this->importar("Mês;Categoria;Fornecedor;Valor\n", $token);
        self::assertResponseStatusCodeSame(422);
    }

    #[TestDox('importar sem token CSRF válido recusa')]
    public function testImportarSemCsrf(): void
    {
        $this->entrar(['ROLE_SUPER_ADMIN']);

        $this->importar("Mês;Categoria;Fornecedor;Valor\n2033-03;Outros;X;1\n", 'errado');

        self::assertResponseStatusCodeSame(403);
    }

    #[TestDox('exporta CSV com BOM e os lançamentos; quem não é Master recebe 403')]
    public function testExportar(): void
    {
        $this->entrar(['ROLE_SUPER_ADMIN']);
        $this->em->persist(new LancamentoDeCusto('2034-01', CategoriaDeCusto::Trafego, 7500, OrigemDoCusto::Estimado, 'CDN', null, new \DateTimeImmutable()));
        $this->em->flush();

        $this->client->request('GET', '/master/custos/exportar');

        self::assertResponseIsSuccessful();
        $corpo = (string) $this->client->getResponse()->getContent();
        self::assertStringStartsWith("\u{FEFF}", $corpo);
        self::assertStringContainsString('"2034-01";"Tráfego";"CDN";"75,00";"Estimado";""', $corpo);
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));

        $this->entrar(['ROLE_USER']);
        $this->client->request('GET', '/master/custos/exportar');
        self::assertResponseStatusCodeSame(403);
    }

    /** @return array<string, mixed> */
    private function importar(string $conteudo, string $token): array
    {
        $caminho = tempnam(sys_get_temp_dir(), 'cst');
        file_put_contents((string) $caminho, $conteudo);
        $this->client->request('POST', '/master/custos/importar', ['mes' => '2033-03'], ['arquivo' => new UploadedFile((string) $caminho, 'custos.csv', 'text/csv', null, true)], ['HTTP_X-CSRF-Token' => $token]);
        $dados = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($dados) ? $dados : [];
    }

    /** @return array<string, string> */
    private function corpo(): array
    {
        return ['mes' => '2032-01', 'categoria' => 'armazenamento', 'valor' => '1.234,56', 'origem' => 'importado', 'fornecedor' => 'AWS', 'observacao' => ''];
    }

    /**
     * @param list<string> $papeis
     * @return string o token CSRF da seção (lido da tela do painel, como o JS faz)
     */
    private function entrar(array $papeis): string
    {
        $user = UserFactory::createOne(['fullName' => 'Fulano', 'roles' => $papeis, 'isActive' => true])->_real();
        $this->logarComTenant($this->client, $user, $this->contaDe($user));
        if (!in_array('ROLE_SUPER_ADMIN', $papeis, true)) {
            return '';
        }
        $crawler = $this->client->request('GET', '/master');

        return (string) $crawler->filter('[data-sec-externa="custos"] .cst')->attr('data-token');
    }

    /**
     * @param array<string, mixed> $corpo
     * @return array<string, mixed>
     */
    private function enviar(string $url, array $corpo, string $token): array
    {
        $this->client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X-CSRF-Token' => $token], content: json_encode($corpo, \JSON_THROW_ON_ERROR));
        $dados = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($dados) ? $dados : [];
    }

    private function contaDe(User $user): Tenant
    {
        $conta = (new Tenant())->setName('Conta ' . uniqid())->setCriadoPor($user);
        $this->em->persist($conta);
        $perfil = (new TenantRole())->setTenant($conta)->setName('Administrador do Escritório')->setIsSystem(true);
        $this->em->persist($perfil);
        $this->em->persist((new UserTenant($user, $conta))->setTenantRole($perfil));
        $this->em->flush();

        return $conta;
    }
}
