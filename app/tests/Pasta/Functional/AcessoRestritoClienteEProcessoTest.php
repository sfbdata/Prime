<?php

declare(strict_types=1);

namespace App\Tests\Pasta\Functional;

use App\Cliente\Entity\Cliente;
use App\Cliente\Entity\ClienteDocumento;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Tenant\Tenant;
use App\Pasta\EventListener\BarreiraDoAcessoRestritoListener;
use App\Pasta\Entity\Pasta;
use App\Pasta\Service\EscopoDePastas;
use App\Processo\Entity\Processo;
use App\Tests\Factory\Cliente\ClientePFFactory;
use App\Tests\Functional\JusPrimeWebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Spec acesso-restrito-e-compartilhar, fatia 2b: o restrito só alcança o cliente que está numa pasta sob a
 * responsabilidade dele e o processo ligado a uma pasta dele. Cliente e processo alheios (inclusive o processo sem
 * pasta) dão 404 em toda rota por id, e a pasta alheia some das telas do cliente e do processo.
 */
#[CoversClass(EscopoDePastas::class)]
#[CoversClass(BarreiraDoAcessoRestritoListener::class)]
final class AcessoRestritoClienteEProcessoTest extends JusPrimeWebTestCase
{
    use CriaCenarioDoCadeadoFuncionalTrait;

    private const PERMISSOES = [
        'modules.expediente.view', 'modules.pastas.view', 'modules.clientes.view', 'modules.processos.view',
        'resources.pasta.view', 'resources.pasta.edit', 'resources.cliente.view', 'resources.cliente.edit',
        'resources.processo.view', 'resources.processo.edit',
    ];

    private Tenant $tenant;
    private User $restrito;
    private Cliente $clienteMeu;
    private Cliente $clienteAlheio;
    private Processo $processoMeu;
    private Processo $processoAlheio;
    private Processo $processoSemPasta;
    private Pasta $minha;
    private Pasta $alheia;
    private ClienteDocumento $documentoAlheio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCliente();
        [$primario, $this->tenant] = $this->criarEscritorio();
        $this->restrito = $this->criarColaborador($this->tenant, 'Rita Restrita', self::PERMISSOES);
        $vinculo = $this->em()->getRepository(UserTenant::class)->findOneBy(['user' => $this->restrito, 'tenant' => $this->tenant]);
        $vinculo?->getTenantRole()?->restringirAsPastasSobResponsabilidade('Parceiro');

        $this->clienteMeu    = ClientePFFactory::createOne(['tenant' => $this->tenant, 'nomeCompleto' => 'CLIENTE DA RITA', 'cpf' => '52998224725'])->_real();
        $this->clienteAlheio = ClientePFFactory::createOne(['tenant' => $this->tenant, 'nomeCompleto' => 'CLIENTE DO PRIMARIO', 'cpf' => '11144477735'])->_real();
        $this->processoMeu      = $this->processo('0700001-11.2026.8.07.0001');
        $this->processoAlheio   = $this->processo('0700002-11.2026.8.07.0001');
        $this->processoSemPasta = $this->processo('0700003-11.2026.8.07.0001');

        $this->minha  = $this->pasta($this->restrito, 'CLIENTE DA RITA', $this->clienteMeu, $this->processoMeu);
        $this->alheia = $this->pasta($primario, 'CLIENTE DO PRIMARIO', $this->clienteAlheio, $this->processoAlheio);
        // O processo meu também está numa pasta alheia: abre, mas a pasta alheia não aparece nele.
        $this->alheia->vincularProcesso($this->processoMeu);
        $this->documentoAlheio = $this->documento($this->clienteAlheio);
        $this->em()->flush();
    }

    /** @return iterable<string, array{string, string, string}> rota => [método, caminho com {cliente}, {processo} ou {documento}] */
    public static function rotasPorId(): iterable
    {
        yield 'cliente_show' => ['GET', '/clientes/{cliente}'];
        yield 'cliente_edit' => ['GET', '/clientes/{cliente}/editar'];
        yield 'cliente_cadastro' => ['GET', '/clientes/{cliente}/cadastro'];
        yield 'cliente_cadastro_documento' => ['GET', '/clientes/{cliente}/cadastro/documento'];
        yield 'cliente_cadastro_salvar' => ['POST', '/clientes/{cliente}/cadastro'];
        yield 'cliente_contatos' => ['POST', '/clientes/{cliente}/contatos'];
        yield 'cliente_delete' => ['POST', '/clientes/{cliente}/deletar'];
        yield 'cliente_documento_upload' => ['POST', '/clientes/{cliente}/documento/upload'];
        yield 'cliente_foto' => ['GET', '/clientes/{cliente}/foto/x.jpg'];
        yield 'cliente_resumo' => ['GET', '/clientes/{cliente}/resumo'];
        yield 'cliente_inteligencia_cadastral' => ['GET', '/cliente/{cliente}/inteligencia-cadastral'];
        yield 'cliente_documento_view' => ['GET', '/clientes/documento/{documento}/visualizar'];
        yield 'cliente_documento_download' => ['GET', '/clientes/documento/{documento}/download'];
        yield 'cliente_documento_edit' => ['POST', '/clientes/documento/{documento}/editar'];
        yield 'cliente_documento_delete' => ['POST', '/clientes/documento/{documento}/deletar'];
        yield 'processo_show' => ['GET', '/processos/{processo}'];
        yield 'processo_edit' => ['GET', '/processos/{processo}/editar'];
        yield 'processo_delete' => ['POST', '/processos/{processo}/deletar'];
        yield 'processo_nota_tecnica_criar' => ['POST', '/processos/{processo}/nota-tecnica'];
        yield 'processo_nota_tecnica_editar' => ['POST', '/processos/{processo}/nota-tecnica/1/editar'];
        yield 'processo_nota_tecnica_excluir' => ['POST', '/processos/{processo}/nota-tecnica/1/excluir'];
    }

    #[DataProvider('rotasPorId')]
    #[TestDox('$metodo $caminho: cliente, documento ou processo alheio dá 404 para o restrito')]
    public function testAlheioDa404(string $metodo, string $caminho): void
    {
        $this->entrar($this->restrito, $this->tenant);
        $urls = $this->urlsAlheias($caminho);
        self::assertNotSame([], $urls);
        foreach ($urls as $url) {
            $this->client->request($metodo, $url);
            self::assertResponseStatusCodeSame(404, $metodo . ' ' . $url);
        }
    }

    #[TestDox('o restrito abre o próprio cliente e o próprio processo; a pasta alheia não aparece em nenhum dos dois')]
    public function testProprioAbreSemAPastaAlheia(): void
    {
        $this->entrar($this->restrito, $this->tenant);

        $this->client->request('GET', '/clientes/' . $this->clienteMeu->getId());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString((string) $this->minha->getNup(), (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/processos/' . $this->processoMeu->getId());
        self::assertResponseIsSuccessful();
        $corpo = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString((string) $this->minha->getNup(), $corpo);
        self::assertStringNotContainsString((string) $this->alheia->getNup(), $corpo);
        self::assertStringNotContainsString('CLIENTE DO PRIMARIO', $corpo);
    }

    #[TestDox('a lista de processos do restrito não traz o processo alheio nem o processo sem pasta')]
    public function testListaDeProcessos(): void
    {
        $this->entrar($this->restrito, $this->tenant);

        $this->client->request('GET', '/processos/');
        self::assertResponseIsSuccessful();
        $corpo = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('0700001-11.2026.8.07.0001', $corpo);
        self::assertStringNotContainsString('0700002-11.2026.8.07.0001', $corpo);
        self::assertStringNotContainsString('0700003-11.2026.8.07.0001', $corpo);
    }

    #[TestDox('buscar cliente para vincular na própria pasta só acha os clientes das pastas do restrito')]
    public function testBuscaDeClienteParaVincular(): void
    {
        $this->entrar($this->restrito, $this->tenant);

        $this->client->request('GET', '/pasta/' . $this->minha->getId() . '/clientes/buscar?incluirVinculados=1&q=CLIENTE');
        self::assertResponseIsSuccessful();
        $corpo = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('CLIENTE DA RITA', $corpo);
        self::assertStringNotContainsString('CLIENTE DO PRIMARIO', $corpo);
    }

    /** @return list<string> o caminho com cada cliente, documento ou processo alheio no lugar da marca */
    private function urlsAlheias(string $caminho): array
    {
        $marcas = [
            '{cliente}'   => [$this->clienteAlheio->getId()],
            '{documento}' => [$this->documentoAlheio->getId()],
            '{processo}'  => [$this->processoAlheio->getId(), $this->processoSemPasta->getId()],
        ];
        $urls = [];
        foreach ($marcas as $marca => $ids) {
            if (str_contains($caminho, $marca)) {
                foreach ($ids as $id) {
                    $urls[] = str_replace($marca, (string) $id, $caminho);
                }
            }
        }

        return $urls;
    }

    private function processo(string $numero): Processo
    {
        $processo = new Processo();
        $processo->setNumeroProcesso($numero);
        $processo->setSiglaTribunal('TJDFT');
        $processo->setOrgaoJulgador('1a Vara');
        $processo->setClasseProcessual('Procedimento comum');
        $processo->setAssuntoProcessual('Teste');
        $processo->setSituacaoProcesso('Em Andamento');
        $processo->setInstancia('1a Instância');
        $processo->setTenant($this->tenant);
        $this->em()->persist($processo);

        return $processo;
    }

    private function pasta(User $responsavel, string $nome, Cliente $cliente, Processo $processo): Pasta
    {
        $pasta = $this->criarPasta($this->tenant, $nome);
        $pasta->setResponsavel($responsavel);
        $pasta->addCliente($cliente);
        $pasta->vincularProcesso($processo);
        $this->em()->flush();

        return $pasta;
    }

    private function documento(Cliente $cliente): ClienteDocumento
    {
        $documento = (new ClienteDocumento())
            ->setTitulo('RG')->setCategoria('identidade')->setCaminhoArquivo('x/rg.pdf')
            ->setNomeOriginal('rg.pdf')->setMimeType('application/pdf')->setTamanhoBytes(10);
        $documento->setCliente($cliente);
        $documento->setTenant($this->tenant);
        $this->em()->persist($documento);

        return $documento;
    }
}
