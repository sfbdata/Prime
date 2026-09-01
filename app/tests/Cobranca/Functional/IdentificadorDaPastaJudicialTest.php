<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Cobranca\Enum\StatusCaso;
use App\Cobranca\Service\ResolvedorIdentificadorDaPasta;
use App\Pasta\Entity\Pasta;
use App\Tests\Factory\Cliente\ClientePJFactory;
use App\Tests\Factory\Cobranca\PessoaFactory;
use App\Tests\Factory\Pasta\PastaFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * O identificador da pasta judicializada pela cobrança é DERIVADO — `<fantasia do credor> - <pessoa
 * cobrada>`, lido na hora, nunca gravado (spec `pasta-prefixo-do-credor-derivado.md`).
 *
 * As pastas que não vieram da cobrança seguem com o identificador de texto livre; é o escopo estreito
 * que a spec §1 exige, e cada teste aqui existe para provar um dos dois lados.
 */
#[CoversClass(ResolvedorIdentificadorDaPasta::class)]
final class IdentificadorDaPastaJudicialTest extends CobrancaWebTestCase
{
    private function resolvedor(): ResolvedorIdentificadorDaPasta
    {
        return static::getContainer()->get(ResolvedorIdentificadorDaPasta::class);
    }

    #[TestDox('Pasta de caso judicializado: o identificador é montado do credor + devedor')]
    public function testPastaDeCobrancaTemIdentificadorDerivado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);

        $credor = ClientePJFactory::createOne(['tenant' => $tenant, 'nomeFantasia' => 'APLC TOP LIFE 1']);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'CLAUDIO SILVA DA CRUZ'])->_real();
        // Texto gravado propositalmente QUEBRADO: é o estado real das pastas 1255 e 1259 em produção.
        // O derivado tem de vencê-lo — é isso que dispensa a correção manual das duas (spec §3).
        $pasta = PastaFactory::createOne(['tenant' => $tenant, 'nomeCliente' => 'APLC TOP LIFE 1 -'])->_real();
        $this->semearGrafo(
            $tenant,
            ['pessoaCobradaAtual' => $pessoa, 'status' => StatusCaso::Judicializado, 'pastaJudicial' => $pasta],
            ['cliente' => $credor],
        );

        self::assertSame(
            'APLC TOP LIFE 1 - CLAUDIO SILVA DA CRUZ',
            $this->resolvedor()->para($pasta),
        );
    }

    #[TestDox('Pasta sem cobrança: mantém o identificador de texto livre, intocada')]
    public function testPastaSemCobrancaMantemOTextoLivre(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);

        $pasta = PastaFactory::createOne(['tenant' => $tenant, 'nomeCliente' => 'CONDOMINIO QUALQUER'])->_real();

        self::assertSame('CONDOMINIO QUALQUER', $this->resolvedor()->para($pasta));
    }

    #[TestDox('Trocar a pessoa cobrada muda o identificador junto')]
    public function testTrocarAPessoaCobradaMudaOIdentificador(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);

        $credor = ClientePJFactory::createOne(['tenant' => $tenant, 'nomeFantasia' => 'APLC TOP LIFE 1']);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'CLAUDIO SILVA DA CRUZ'])->_real();
        $pasta = PastaFactory::createOne(['tenant' => $tenant])->_real();
        [, $caso] = $this->semearGrafo(
            $tenant,
            ['pessoaCobradaAtual' => $pessoa, 'status' => StatusCaso::Judicializado, 'pastaJudicial' => $pasta],
            ['cliente' => $credor],
        );

        $outra = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'MARIA DE SOUSA'])->_real();
        $caso->setPessoaCobradaAtual($outra);
        $em = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        $em->flush();

        // Memória por requisição: sem esquecer, um resolvedor já consultado devolveria o valor velho.
        $this->resolvedor()->esquecer();

        self::assertSame('APLC TOP LIFE 1 - MARIA DE SOUSA', $this->resolvedor()->para($pasta));
    }

    #[TestDox('Pasta SEM cobrança ao lado de uma COM: cada uma recebe o seu, sem contaminar')]
    public function testPastaSemCobrancaNaoHerdaOCredorDaVizinha(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);

        // O cenário do IRMÃO, no MESMO escritório — é o único que prova o meu código. Um teste
        // cross-tenant aqui ficaria verde por causa do TenantFilter global, e não do mapa em lote:
        // provaria outra barreira.
        $credor = ClientePJFactory::createOne(['tenant' => $tenant, 'nomeFantasia' => 'APLC TOP LIFE 1']);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'CLAUDIO SILVA DA CRUZ'])->_real();
        $comCobranca = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $this->semearGrafo(
            $tenant,
            ['pessoaCobradaAtual' => $pessoa, 'status' => StatusCaso::Judicializado, 'pastaJudicial' => $comCobranca],
            ['cliente' => $credor],
        );

        $semCobranca = PastaFactory::createOne(['tenant' => $tenant, 'nomeCliente' => 'TEXTO LIVRE'])->_real();

        $resolvedor = $this->resolvedor();
        $resolvedor->primeParaPastas([$comCobranca, $semCobranca]);

        self::assertSame('APLC TOP LIFE 1 - CLAUDIO SILVA DA CRUZ', $resolvedor->para($comCobranca));
        self::assertSame('TEXTO LIVRE', $resolvedor->para($semCobranca), 'a vizinha sem cobrança fica com o texto dela');
    }

    #[TestDox('A listagem do Expediente mostra o identificador derivado, na tabela E no cartão')]
    public function testListagemDoExpedienteMostraODerivado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);

        $credor = ClientePJFactory::createOne(['tenant' => $tenant, 'nomeFantasia' => 'APLC TOP LIFE 1']);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'CLAUDIO SILVA DA CRUZ'])->_real();
        $nup = 'IDENT-' . strtoupper(uniqid());
        // Texto gravado quebrado, como em produção: o derivado tem de vencê-lo NA TELA.
        $pasta = PastaFactory::createOne(['tenant' => $tenant, 'nup' => $nup, 'nomeCliente' => 'APLC TOP LIFE 1 -'])->_real();
        $this->semearGrafo(
            $tenant,
            ['pessoaCobradaAtual' => $pessoa, 'status' => StatusCaso::Judicializado, 'pastaJudicial' => $pasta],
            ['cliente' => $credor],
        );

        $crawler = $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?busca=' . rawurlencode($nup));
        self::assertResponseIsSuccessful();

        // ⚠️ Ancorar em CADA modo: o fragmento traz tabela e cartões juntos, e uma asserção no corpo
        // inteiro fica verde com um dos dois errado — já aconteceu nesta frente.
        self::assertSame(
            'APLC TOP LIFE 1 - CLAUDIO SILVA DA CRUZ',
            trim($crawler->filter('#tabelaPastas tbody tr td')->eq(1)->text()),
            'a tabela mostra o identificador derivado',
        );
        self::assertSame(
            'APLC TOP LIFE 1 - CLAUDIO SILVA DA CRUZ',
            trim($crawler->filter('.pasta-card-cliente')->first()->text()),
            'o cartão mostra o mesmo',
        );
    }

    #[TestDox('Editar uma pasta de cobrança: o identificador é texto fixo, com o caminho para mudá-lo')]
    public function testEditarPastaDeCobrancaNaoOfereceOCampoEditavel(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);

        $credor = ClientePJFactory::createOne(['tenant' => $tenant, 'nomeFantasia' => 'APLC TOP LIFE 1']);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'CLAUDIO SILVA DA CRUZ'])->_real();
        $pasta = PastaFactory::createOne(['tenant' => $tenant])->_real();
        $this->semearGrafo(
            $tenant,
            ['pessoaCobradaAtual' => $pessoa, 'status' => StatusCaso::Judicializado, 'pastaJudicial' => $pasta],
            ['cliente' => $credor],
        );

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());
        self::assertResponseIsSuccessful();

        $formulario = $crawler->filter('form[action$="/editar"]');

        self::assertCount(
            0,
            $formulario->filter('input[name="nome_cliente"]'),
            'numa pasta de cobrança o identificador não se edita aqui — muda-se o cadastro',
        );
        self::assertStringContainsString(
            'APLC TOP LIFE 1 - CLAUDIO SILVA DA CRUZ',
            $formulario->text(),
            'mas ele aparece, senão o gestor não sabe como a pasta se chama',
        );
        // Campo que some sem explicação vira chamado de suporte: a tela tem de dizer ONDE mudar.
        self::assertStringContainsString('cadastro do cliente', $formulario->text());
    }

    #[TestDox('Pasta SEM cobrança continua com o identificador editável')]
    public function testEditarPastaSemCobrancaContinuaEditavel(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);

        $pasta = PastaFactory::createOne(['tenant' => $tenant, 'nomeCliente' => 'TEXTO LIVRE'])->_real();

        $crawler = $client->request('GET', '/pasta/' . $pasta->getId());

        self::assertSame(
            'TEXTO LIVRE',
            $crawler->filter('form[action$="/editar"] input[name="nome_cliente"]')->attr('value'),
            'as 1.093 pastas sem cobrança não mudam em nada',
        );
    }

    #[TestDox('Buscar pela fantasia do credor acha a pasta, mesmo sem o texto estar gravado nela')]
    public function testBuscaPelaFantasiaDoCredorAchaAPasta(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);

        $marca = 'APLC' . strtoupper(uniqid());
        $credor = ClientePJFactory::createOne(['tenant' => $tenant, 'nomeFantasia' => $marca]);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'CLAUDIO SILVA DA CRUZ'])->_real();
        // 🔑 O texto GRAVADO não contém a marca. Sem isso, a busca antiga (que só lê o campo) já
        // acharia a pasta e o teste ficaria verde provando nada.
        $pasta = PastaFactory::createOne(['tenant' => $tenant, 'nomeCliente' => 'TEXTO ANTIGO SEM A MARCA'])->_real();
        $this->semearGrafo(
            $tenant,
            ['pessoaCobradaAtual' => $pessoa, 'status' => StatusCaso::Judicializado, 'pastaJudicial' => $pasta],
            ['cliente' => $credor],
        );

        $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?busca=' . rawurlencode($marca));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            (string) $pasta->getNup(),
            (string) $client->getResponse()->getContent(),
            'buscar pela fantasia do credor tem de achar a pasta judicializada',
        );
    }

    #[TestDox('Ordenar por Cliente segue o identificador derivado, não o texto gravado')]
    public function testOrdenarPorClienteSegueODerivado(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);

        // Cruzado de propósito: o texto gravado e o derivado ordenam em sentidos OPOSTOS. É o único
        // arranjo que distingue as duas precedências.
        $sufixo = strtoupper(uniqid());
        $primeira = $this->pastaDeCobranca($tenant, 'AAA' . $sufixo, 'ZZZ TEXTO GRAVADO', 'ORD-A-' . $sufixo);
        $segunda = $this->pastaDeCobranca($tenant, 'ZZZ' . $sufixo, 'AAA TEXTO GRAVADO', 'ORD-Z-' . $sufixo);

        $client->xmlHttpRequest('GET', '/expediente/painel/acervo-geral?busca=' . rawurlencode($sufixo) . '&ordenar=cliente&direcao=asc');

        self::assertResponseIsSuccessful();
        $corpo = (string) $client->getResponse()->getContent();

        self::assertLessThan(
            strpos($corpo, (string) $segunda->getNup()),
            strpos($corpo, (string) $primeira->getNup()),
            'a ordem tem de seguir o identificador que aparece na coluna',
        );
    }

    private function pastaDeCobranca(object $tenant, string $fantasia, string $textoGravado, string $nup): Pasta
    {
        $credor = ClientePJFactory::createOne(['tenant' => $tenant, 'nomeFantasia' => $fantasia]);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'DEVEDOR ' . $nup])->_real();
        $pasta = PastaFactory::createOne(['tenant' => $tenant, 'nup' => $nup, 'nomeCliente' => $textoGravado])->_real();
        $this->semearGrafo(
            $tenant,
            ['pessoaCobradaAtual' => $pessoa, 'status' => StatusCaso::Judicializado, 'pastaJudicial' => $pasta],
            ['cliente' => $credor],
        );

        return $pasta;
    }

    #[TestDox('A tela de Demandas mostra o mesmo identificador derivado')]
    public function testDemandasMostraODerivado(): void
    {
        $client = static::createClient();
        [$usuario, $tenant] = $this->criarAdminLogado($client);

        $credor = ClientePJFactory::createOne(['tenant' => $tenant, 'nomeFantasia' => 'APLC TOP LIFE 1']);
        $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => 'CLAUDIO SILVA DA CRUZ'])->_real();
        // Demandas é a lista do RESPONSÁVEL: sem isso a pasta não aparece e o teste passaria vazio.
        $pasta = PastaFactory::createOne([
            'tenant' => $tenant,
            'nomeCliente' => 'TEXTO GRAVADO ANTIGO',
            'responsavel' => $usuario,
        ])->_real();
        $this->semearGrafo(
            $tenant,
            ['pessoaCobradaAtual' => $pessoa, 'status' => StatusCaso::Judicializado, 'pastaJudicial' => $pasta],
            ['cliente' => $credor],
        );

        $crawler = $client->request('GET', '/demandas');
        self::assertResponseIsSuccessful();

        self::assertSame(
            'APLC TOP LIFE 1 - CLAUDIO SILVA DA CRUZ',
            trim($crawler->filter('#tabelaDemandas tbody tr td')->eq(1)->text()),
        );
    }

    #[TestDox('Prime em lote resolve a página inteira sem consultar de novo')]
    public function testPrimeEmLoteResolveVariasPastas(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);

        $credor = ClientePJFactory::createOne(['tenant' => $tenant, 'nomeFantasia' => 'APLC TOP LIFE 1']);
        $pastas = [];
        foreach (['CLAUDIO SILVA DA CRUZ', 'MARIA DE SOUSA'] as $nome) {
            $pessoa = PessoaFactory::createOne(['tenant' => $tenant, 'nome' => $nome])->_real();
            $pasta = PastaFactory::createOne(['tenant' => $tenant])->_real();
            $this->semearGrafo(
                $tenant,
                ['pessoaCobradaAtual' => $pessoa, 'status' => StatusCaso::Judicializado, 'pastaJudicial' => $pasta],
                ['cliente' => $credor],
            );
            $pastas[] = $pasta;
        }

        $resolvedor = $this->resolvedor();
        $resolvedor->primeParaPastas($pastas);

        self::assertSame('APLC TOP LIFE 1 - CLAUDIO SILVA DA CRUZ', $resolvedor->para($pastas[0]));
        self::assertSame('APLC TOP LIFE 1 - MARIA DE SOUSA', $resolvedor->para($pastas[1]));
    }
}
