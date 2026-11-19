<?php

declare(strict_types=1);

namespace App\Tests\Conector\Integration;

use App\ChatIa\Service\ProtecaoDoTextoDoChat;
use App\Conector\DTO\RespostaDaFerramenta;
use App\Conector\Ferramenta\CatalogoFechadoDoConector;
use App\Conector\Ferramenta\DespachoDasFerramentas;
use App\Conector\Ferramenta\QuemSouEuFerramenta;
use App\Conector\Ferramenta\TabelaDeAbaEChave;
use App\Conector\Service\ModoDoConector;
use App\Service\PermissionChecker;
use App\Tests\Conector\Support\CenarioDoConectorTrait;
use App\Tests\Conector\Support\DublesDoMcpTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `quem_sou_eu` com o banco (spec mcp-remoto-oauth 6.4, 6.3 e 12.1): só o escritório do token aparece, mesmo com a
 * pessoa em dois; as ferramentas listadas seguem o módulo pelo PermissionChecker com o modo do conector ligado (o
 * primário perde o passe); e o container registra só ferramentas do catálogo.
 */
#[CoversClass(QuemSouEuFerramenta::class)]
final class QuemSouEuFerramentaTest extends KernelTestCase
{
    use CenarioDoConectorTrait;
    use DublesDoMcpTrait;

    protected function setUp(): void
    {
        self::bootKernel();
    }

    #[TestDox('12.1: com a pessoa nos escritórios A e B, o contexto de A mostra só A: id, nome, escopos e validade da conexão')]
    public function testSoOEscritorioDoToken(): void
    {
        $pessoa = $this->pessoa();
        $a = $this->escritorio('Escritório Alfa');
        $b = $this->escritorio('Escritório Beta');
        $vinculoA = $this->vinculo($pessoa, $a);
        $this->vinculo($pessoa, $b, permissoes: ['modules.agenda.view']);

        $resposta = $this->quemSouEu()->executar([], self::chamada(['mcp:leitura'], $pessoa, $a, $vinculoA));

        $dados = $resposta->dados;
        self::assertSame(['id' => $pessoa->getId(), 'nome' => 'Pessoa do Conector'], $dados['pessoa']);
        self::assertSame(['id' => $a->getId(), 'nome' => $a->getName()], $dados['escritorio']);
        self::assertSame(['mcp:leitura'], $dados['escopos']);
        self::assertSame((new \DateTimeImmutable('2026-11-23 10:00:00'))->format(\DateTimeInterface::ATOM), $dados['conexao_vale_ate']);
        self::assertSame(['escritorio' => [$a->getId()]], $resposta->ids);

        $json = json_encode($dados, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString((string) $b->getName(), $json);
        self::assertStringNotContainsString('Beta', $json);
        self::assertStringNotContainsString('"id":' . $b->getId() . ',', $json);
    }

    #[TestDox('o container registra só ferramentas do catálogo, e a quem_sou_eu está entre elas')]
    public function testRegistradasDoContainer(): void
    {
        $nomes = array_map(static fn ($f): string => $f->nome(), static::getContainer()->get(DespachoDasFerramentas::class)->registradas());

        self::assertContains(QuemSouEuFerramenta::NOME, $nomes);
        self::assertSame([], array_diff($nomes, CatalogoFechadoDoConector::nomes()));
    }

    #[TestDox('a lista sai pelo módulo com o modo ligado: sem modules.agenda.view, ler_agenda some; o primário sem a permissão ligada também a vê (regra fixa do modo); escrita só liberada com mcp:escrita')]
    public function testFerramentasPeloModulo(): void
    {
        $escritorio = $this->escritorio();
        $semAgenda = $this->pessoa();
        $vinculoSem = $this->vinculo($semAgenda, $escritorio, permissoes: ['modules.pastas.view']);
        $comAgenda = $this->pessoa();
        $vinculoCom = $this->vinculo($comAgenda, $escritorio, permissoes: ['modules.agenda.view']);

        self::assertSame(['quem_sou_eu'], $this->nomesPara($semAgenda, $vinculoSem, ['mcp:leitura']));

        $lista = $this->listaPara($comAgenda, $vinculoCom, ['mcp:leitura']);
        self::assertSame(['quem_sou_eu', 'ler_agenda', 'gravar_na_agenda'], array_column($lista, 'nome'));
        self::assertSame([true, true, false], array_column($lista, 'liberada_nesta_conexao'));
        self::assertSame(CatalogoFechadoDoConector::TITULOS['gravar_na_agenda'], $lista[2]['titulo']);
        self::assertSame([true, true, true], array_column($this->listaPara($comAgenda, $vinculoCom, ['mcp:leitura', 'mcp:escrita']), 'liberada_nesta_conexao'));

        $primario = $this->pessoa();
        $vinculoDoPrimario = $this->vinculo($primario, $escritorio, sistema: true);
        self::assertSame(['quem_sou_eu', 'ler_agenda', 'gravar_na_agenda'], $this->nomesPara($primario, $vinculoDoPrimario, ['mcp:leitura']));
    }

    #[TestDox('todo nome do catálogo tem linha na tabela de aba e chave, de onde a quem_sou_eu lê o módulo')]
    public function testTabelaCobreOCatalogo(): void
    {
        self::assertSame(CatalogoFechadoDoConector::nomes(), array_keys(TabelaDeAbaEChave::LINHAS));
    }

    /**
     * @param list<string> $escopos
     *
     * @return list<string>
     */
    private function nomesPara(\App\Entity\Auth\User $pessoa, \App\Entity\Auth\UserTenant $vinculo, array $escopos): array
    {
        return array_column($this->listaPara($pessoa, $vinculo, $escopos), 'nome');
    }

    /**
     * Com o modo do conector ligado para a pessoa, como o portão faz.
     *
     * @param list<string> $escopos
     *
     * @return list<array{nome: string, titulo: string, liberada_nesta_conexao: bool}>
     */
    private function listaPara(\App\Entity\Auth\User $pessoa, \App\Entity\Auth\UserTenant $vinculo, array $escopos): array
    {
        $c = static::getContainer();
        $modo = $c->get(ModoDoConector::class);
        $modo->ligar($vinculo);
        try {
            $ferramenta = new QuemSouEuFerramenta(
                [$this->quemSouEu(), self::ferramenta('ler_agenda', new RespostaDaFerramenta([])), self::ferramenta('gravar_na_agenda', new RespostaDaFerramenta([]))],
                $c->get(PermissionChecker::class),
                $c->get(ProtecaoDoTextoDoChat::class),
            );

            return $ferramenta->executar([], self::chamada($escopos, $pessoa, $vinculo->getTenant(), $vinculo))->dados['ferramentas'];
        } finally {
            $modo->reset();
        }
    }

    private function quemSouEu(): QuemSouEuFerramenta
    {
        return static::getContainer()->get(QuemSouEuFerramenta::class);
    }
}
