<?php

declare(strict_types=1);

namespace App\Tests\Conector\Unit;

use App\Conector\Ferramenta\CatalogoFechadoDoConector;
use App\Conector\Mcp\FerramentaEscondidaDaPessoa;
use App\Conector\Mcp\ListaDasFerramentasDaPessoa;
use App\Conector\Service\GuardaDoMasterDoConector;
use App\Conector\Service\VisibilidadeDasFerramentasDoMaster;
use App\Entity\Auth\User;
use Mcp\Schema\JsonRpc\Error;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Request\CallToolRequest;
use Mcp\Schema\Request\ListToolsRequest;
use Mcp\Schema\Result\ListToolsResult;
use Mcp\Schema\Tool;
use Mcp\Server\Session\InMemorySessionStore;
use Mcp\Server\Session\Session;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * As ferramentas do Painel do Master no MCP (`docs/specs/mcp-remoto-oauth.md`, 6.9, decisões 34, 38 e 39): só o Master
 * as vê no `tools/list`, e quem não é Master que chama uma delas pelo nome recebe a resposta de nome desconhecido.
 */
#[CoversClass(VisibilidadeDasFerramentasDoMaster::class)]
#[CoversClass(ListaDasFerramentasDaPessoa::class)]
#[CoversClass(FerramentaEscondidaDaPessoa::class)]
#[CoversClass(GuardaDoMasterDoConector::class)]
final class FerramentasDoMasterNoMcpTest extends TestCase
{
    #[TestDox('visibilidade: ferramenta comum aparece para todos; a do Master só para quem tem ROLE_SUPER_ADMIN; sem pessoa, não aparece')]
    public function testVisibilidade(): void
    {
        $comum = self::pessoa(['ROLE_USER']);
        $master = self::pessoa(['ROLE_USER', 'ROLE_SUPER_ADMIN']);

        self::assertTrue(VisibilidadeDasFerramentasDoMaster::visivelPara('buscar_pastas', $comum));
        self::assertTrue(VisibilidadeDasFerramentasDoMaster::visivelPara('buscar_pastas', null));
        foreach (CatalogoFechadoDoConector::SO_DO_MASTER as $nome) {
            self::assertFalse(VisibilidadeDasFerramentasDoMaster::visivelPara($nome, $comum), $nome);
            self::assertFalse(VisibilidadeDasFerramentasDoMaster::visivelPara($nome, null), $nome);
            self::assertTrue(VisibilidadeDasFerramentasDoMaster::visivelPara($nome, $master), $nome);
        }
    }

    #[TestDox('catálogo: as quatro do Master são uma lista à parte; as que já entraram no catálogo (hoje a ler_comercial) são as únicas de FERRAMENTAS marcadas como do Master')]
    public function testCatalogoDoMaster(): void
    {
        self::assertCount(4, CatalogoFechadoDoConector::SO_DO_MASTER);
        foreach (CatalogoFechadoDoConector::SO_DO_MASTER as $nome) {
            self::assertTrue(CatalogoFechadoDoConector::soDoMaster($nome));
        }
        foreach (array_keys(CatalogoFechadoDoConector::FERRAMENTAS) as $nome) {
            self::assertSame($nome === 'ler_comercial', CatalogoFechadoDoConector::soDoMaster($nome), $nome);
        }
    }

    #[TestDox('tools/list: a lista toda, numa página só, menos as ferramentas que a pessoa não vê, na ordem do registro')]
    public function testListaFiltrada(): void
    {
        $definicoes = [self::tool('buscar_pastas'), self::tool('ler_comercial'), self::tool('ler_pasta')];
        $comum = new ListaDasFerramentasDaPessoa($definicoes, static fn (string $nome): bool => $nome !== 'ler_comercial');
        $tudo = new ListaDasFerramentasDaPessoa($definicoes, static fn (string $nome): bool => true);
        $pedido = (new ListToolsRequest())->withId(7);

        self::assertTrue($comum->supports($pedido));
        self::assertFalse($comum->supports((new CallToolRequest('buscar_pastas', []))->withId(1)));
        self::assertSame(['buscar_pastas', 'ler_pasta'], self::nomes($comum->handle($pedido, self::sessao())));
        self::assertSame(['buscar_pastas', 'ler_comercial', 'ler_pasta'], self::nomes($tudo->handle($pedido, self::sessao())));
    }

    #[TestDox('tools/call de ferramenta escondida: mesmo erro do SDK para nome desconhecido; a visível segue para o SDK')]
    public function testChamadaEscondida(): void
    {
        $handler = new FerramentaEscondidaDaPessoa(static fn (string $nome): bool => $nome !== 'ler_comercial');
        $escondida = (new CallToolRequest('ler_comercial', []))->withId(9);

        self::assertFalse($handler->supports((new CallToolRequest('buscar_pastas', []))->withId(1)));
        self::assertFalse($handler->supports((new ListToolsRequest())->withId(1)));
        self::assertTrue($handler->supports($escondida));
        $erro = $handler->handle($escondida, self::sessao());
        self::assertInstanceOf(Error::class, $erro);
        self::assertSame(Error::INVALID_PARAMS, $erro->code);
        self::assertSame('Tool not found: "ler_comercial".', $erro->message);
        self::assertSame(9, $erro->getId());
    }

    /** @param list<string> $papeis */
    private static function pessoa(array $papeis): User
    {
        return (new User())->setRoles($papeis);
    }

    private static function tool(string $nome): Tool
    {
        return new Tool($nome, null, ['type' => 'object'], null, null);
    }

    private static function sessao(): Session
    {
        return new Session(new InMemorySessionStore());
    }

    /** @return list<string> */
    private static function nomes(Response|Error $resposta): array
    {
        self::assertInstanceOf(Response::class, $resposta);
        self::assertInstanceOf(ListToolsResult::class, $resposta->result);
        self::assertNull($resposta->result->nextCursor);

        return array_map(static fn (Tool $t): string => $t->name, $resposta->result->tools);
    }
}
