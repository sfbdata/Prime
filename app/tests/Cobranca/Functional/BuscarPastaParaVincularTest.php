<?php

declare(strict_types=1);

namespace App\Tests\Cobranca\Functional;

use App\Tests\Factory\Pasta\PastaFactory;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Busca de pastas do modal "Vincular uma pasta existente".
 *
 * Substitui um `<select>` que carregava TODAS as pastas do escritório — 1.099 em produção — em toda
 * abertura da página da unidade, e rotulava cada uma só com o número. Sem o nome, ninguém reconhecia
 * a pasta; é parte do motivo de 26 das 30 judicializações terem sido feitas em pasta criada à mão.
 *
 * ⚠️ A lista branca do formulário deixou de existir com a busca. Quem recusa pasta de outro
 * escritório passa a ser só o UseCase (`id + tenant`) — por isso o teste de isolamento aqui não é
 * decorativo.
 */
final class BuscarPastaParaVincularTest extends CobrancaWebTestCase
{
    private const ROTA = '/cobrancas/casos/pastas/buscar?q=';

    #[TestDox('Busca pelo número, pelo nome e pela ação da pasta')]
    public function testBuscaPorNumeroNomeEAcao(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);

        $sufixo = strtoupper(uniqid());
        $alvo = PastaFactory::createOne([
            'tenant' => $tenant,
            'nup' => 'BP-' . $sufixo,
            'nomeCliente' => 'APLC TOP LIFE 1 - SALVADOR PAULO DE OLIVEIRA',
            'nomeAcao' => 'AÇÃO MONITÓRIA',
        ])->_real();
        PastaFactory::createOne(['tenant' => $tenant, 'nup' => 'OUTRA-' . $sufixo, 'nomeCliente' => 'NINGUEM']);

        foreach (['BP-' . $sufixo, 'salvador', 'monitória'] as $termo) {
            $client->xmlHttpRequest('GET', self::ROTA . rawurlencode($termo));
            self::assertResponseIsSuccessful();

            $ids = array_column(json_decode((string) $client->getResponse()->getContent(), true)['results'], 'id');
            self::assertContains((int) $alvo->getId(), $ids, sprintf('a busca por "%s" acha a pasta', $termo));
        }
    }

    #[TestDox('O rótulo traz número, nome e ação — é o que permite reconhecer a pasta')]
    public function testRotuloTrazONomeEAAcao(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);

        $sufixo = strtoupper(uniqid());
        PastaFactory::createOne([
            'tenant' => $tenant,
            'nup' => 'ROT-' . $sufixo,
            'nomeCliente' => 'APLC TOP LIFE 1 - SALVADOR PAULO DE OLIVEIRA',
            'nomeAcao' => 'AÇÃO MONITÓRIA',
        ]);

        $client->xmlHttpRequest('GET', self::ROTA . rawurlencode('ROT-' . $sufixo));

        $texto = json_decode((string) $client->getResponse()->getContent(), true)['results'][0]['text'];

        self::assertStringContainsString('ROT-' . $sufixo, $texto);
        self::assertStringContainsString('APLC TOP LIFE 1 - SALVADOR PAULO DE OLIVEIRA', $texto);
        self::assertStringContainsString('AÇÃO MONITÓRIA', $texto);
    }

    #[TestDox('Isolamento: a busca não devolve pasta de outro escritório')]
    public function testNaoDevolvePastaDeOutroEscritorio(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);

        $sufixo = strtoupper(uniqid());
        // O IRMÃO: mesmo termo de busca nos dois escritórios. Sem o filtro de tenant, os dois voltam.
        $minha = PastaFactory::createOne(['tenant' => $tenant, 'nup' => 'ISO-' . $sufixo, 'nomeCliente' => 'ALVO ' . $sufixo])->_real();
        $alheia = PastaFactory::createOne(['tenant' => $this->tenantAvulso(), 'nup' => 'ISOX-' . $sufixo, 'nomeCliente' => 'ALVO ' . $sufixo])->_real();

        $client->xmlHttpRequest('GET', self::ROTA . rawurlencode('ALVO ' . $sufixo));

        $ids = array_column(json_decode((string) $client->getResponse()->getContent(), true)['results'], 'id');
        self::assertContains((int) $minha->getId(), $ids);
        self::assertNotContains((int) $alheia->getId(), $ids, 'pasta de outro escritório não pode aparecer');
    }

    #[TestDox('Sem a capacidade de gerenciar cobrança: a busca é negada')]
    public function testSemCapacidadeEhNegada(): void
    {
        $client = static::createClient();
        $this->criarOperadorSemCapacidade($client);

        $client->xmlHttpRequest('GET', self::ROTA . 'qualquer');

        self::assertResponseStatusCodeSame(403, 'a busca tem o mesmo portão da judicialização');
    }

    #[TestDox('A página da unidade não carrega mais a lista inteira de pastas')]
    public function testAPaginaNaoCarregaMaisTodasAsPastas(): void
    {
        $client = static::createClient();
        [, $tenant] = $this->criarAdminLogado($client);
        [, $caso] = $this->semearGrafo($tenant);
        PastaFactory::createMany(3, ['tenant' => $tenant]);

        $crawler = $client->request('GET', '/cobrancas/objetos/' . $caso->getObjeto()->getId());

        self::assertCount(
            0,
            $crawler->filter('#modalJudicializar select[name="judicializar_caso[pastaId]"]'),
            'o seletor com todas as pastas saiu da página',
        );
        self::assertNotNull(
            $crawler->filter('#modalJudicializar [data-buscar-pasta]')->getNode(0),
            'no lugar dele entrou o campo de busca',
        );
    }
}
