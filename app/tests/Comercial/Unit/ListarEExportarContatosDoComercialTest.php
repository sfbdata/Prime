<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\DTO\ContatoComercialOutput;
use App\Comercial\Repository\ContatoComercialRepository;
use App\Comercial\Service\AuditoriaDaBaseDeContatos;
use App\Comercial\UseCase\ExportarContatosDoComercialUseCase;
use App\Comercial\UseCase\ListarContatosDoComercialUseCase;
use App\Entity\Auth\User;
use App\Shared\Email\Descadastro\DescadastroDeEmailInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/** A lista e o CSV da Base de contatos: busca mínima de 2 letras, situação pelo descadastro e as páginas do CSV. */
#[CoversClass(ListarContatosDoComercialUseCase::class)]
#[CoversClass(ExportarContatosDoComercialUseCase::class)]
#[CoversClass(ContatoComercialOutput::class)]
final class ListarEExportarContatosDoComercialTest extends TestCase
{
    private ContatoComercialRepository&MockObject $contatos;
    private DescadastroDeEmailInterface&MockObject $descadastro;

    protected function setUp(): void
    {
        $this->contatos = $this->createMock(ContatoComercialRepository::class);
        $this->descadastro = $this->createMock(DescadastroDeEmailInterface::class);
    }

    /** @return array<string, mixed> */
    private static function linha(int $id, string $email, ?string $nome, string $origem = 'manual'): array
    {
        return ['id' => $id, 'email' => $email, 'nome' => $nome, 'origem' => $origem, 'origem_detalhe' => null, 'criado_em' => '2026-11-25 10:00:00', 'ultimo_convite_em' => null];
    }

    #[TestDox('A situação vem do descadastro da plataforma para a lista das campanhas, só dos endereços da página')]
    public function testSituacaoPeloDescadastro(): void
    {
        $this->contatos->method('pagina')->willReturn(['total' => 2, 'itens' => [self::linha(1, 'a@ex.com', 'Ana'), self::linha(2, 'b@ex.com', null, 'importacao')]]);
        $this->descadastro->expects(self::once())->method('filtrarDescadastrados')
            ->with(null, ['a@ex.com', 'b@ex.com'], 'comercial_campanhas')->willReturn(['b@ex.com']);

        $lista = (new ListarContatosDoComercialUseCase($this->contatos, $this->descadastro))->executar(null, 1);

        self::assertSame(2, $lista['total']);
        self::assertSame(20, $lista['porPagina']);
        self::assertSame('ativo', $lista['itens'][0]->situacao);
        self::assertSame('descadastrado', $lista['itens'][1]->situacao);
        self::assertNull($lista['itens'][1]->nome);
        self::assertSame('importacao', $lista['itens'][1]->origem);
    }

    #[TestDox('Busca com menos de 2 letras não filtra; com 2 ou mais vai ao repositório aparada; página mínima é 1')]
    public function testBuscaMinima(): void
    {
        $recebidas = [];
        $this->contatos->method('pagina')->willReturnCallback(function (?string $busca, int $pagina) use (&$recebidas): array {
            $recebidas[] = [$busca, $pagina];

            return ['total' => 0, 'itens' => []];
        });
        $this->descadastro->expects(self::never())->method('filtrarDescadastrados');
        $caso = new ListarContatosDoComercialUseCase($this->contatos, $this->descadastro);

        $caso->executar('a', 1);
        $caso->executar('  jo ', 0);

        self::assertSame([[null, 1], ['jo', 1]], $recebidas);
    }

    #[TestDox('O CSV traz as colunas, rotula origem e situação, protege fórmula e percorre todas as páginas com a mesma busca')]
    public function testCsv(): void
    {
        $this->contatos->expects(self::exactly(2))->method('pagina')
            ->willReturnCallback(static fn (?string $busca, int $pagina): array => $pagina === 1
                ? ['total' => 1001, 'itens' => array_map(static fn (int $i): array => self::linha($i, "u$i@ex.com", $i === 1 ? '=SOMA(A1)' : 'Nome "Z"'), range(1, 1000))]
                : ['total' => 1001, 'itens' => [self::linha(1001, 'ultimo@ex.com', null, 'importacao')]]);
        $this->descadastro->method('filtrarDescadastrados')->willReturnCallback(static fn (mixed $t, array $emails): array => array_values(array_intersect($emails, ['ultimo@ex.com'])));

        $master = new User();
        $auditoria = $this->createMock(AuditoriaDaBaseDeContatos::class);
        $auditoria->expects(self::once())->method('registrar')->with('ctt_exp', null, ['linhas' => 1001, 'busca' => 'ex'], $master);

        $csv = (new ExportarContatosDoComercialUseCase(new ListarContatosDoComercialUseCase($this->contatos, $this->descadastro), $auditoria))->executar('ex', $master);
        $linhas = explode("\n", $csv);

        self::assertStringStartsWith("\u{FEFF}", $csv);
        self::assertSame('"E-mail";"Nome";"Origem";"Situação";"Último convite";"Criado em"', substr($linhas[0], 3));
        self::assertCount(1002, $linhas);
        self::assertSame('"u1@ex.com";"\'=SOMA(A1)";"Manual";"Ativo";"";"25/11/2026 10:00"', $linhas[1]);
        self::assertSame('"u2@ex.com";"Nome ""Z""";"Manual";"Ativo";"";"25/11/2026 10:00"', $linhas[2]);
        self::assertSame('"ultimo@ex.com";"";"Importação";"Descadastrado";"";"25/11/2026 10:00"', $linhas[1001]);
    }

    #[TestDox('Células que começam com tab ou CR também ganham apóstrofo, como no CSV de destinatários')]
    public function testFormulaComTabECr(): void
    {
        $this->contatos->method('pagina')->willReturn(['total' => 2, 'itens' => [self::linha(1, 'a@ex.com', "\tcmd"), self::linha(2, 'b@ex.com', "\rcmd")]]);
        $this->descadastro->method('filtrarDescadastrados')->willReturn([]);

        $csv = (new ExportarContatosDoComercialUseCase(new ListarContatosDoComercialUseCase($this->contatos, $this->descadastro), $this->createStub(AuditoriaDaBaseDeContatos::class)))->executar(null, new User());

        self::assertStringContainsString("\"'\tcmd\"", $csv);
        self::assertStringContainsString("\"'\rcmd\"", $csv);
    }
}
