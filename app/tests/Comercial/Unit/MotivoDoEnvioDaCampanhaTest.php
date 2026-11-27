<?php

declare(strict_types=1);

namespace App\Tests\Comercial\Unit;

use App\Comercial\Campanha\MotivoDoEnvioDaCampanha;
use App\Comercial\Repository\ContatoComercialRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * C1 (LGPD): a frase do rodapé da campanha conforme a origem do destinatário. O tradutor devolve a chave, para o teste
 * provar a escolha; os textos de verdade são provados nos testes funcionais da prévia, do teste e do expansor.
 */
#[CoversClass(MotivoDoEnvioDaCampanha::class)]
final class MotivoDoEnvioDaCampanhaTest extends TestCase
{
    /** @return iterable<string, array{string, string, ?string, string}> */
    public static function origens(): iterable
    {
        yield 'primários' => ['prim', 'comercial_campanhas', null, 'motivo_campanha.cadastrados'];
        yield 'secundários' => ['sec', 'comercial_campanhas', null, 'motivo_campanha.cadastrados'];
        yield 'todos' => ['todos', 'comercial_campanhas', null, 'motivo_campanha.cadastrados'];
        yield 'base de contatos' => ['leads', 'comercial_campanhas', null, 'motivo_campanha.base_de_contatos'];
        yield 'base de contatos pela lista de vagas' => ['leads', 'vagas_alerta', null, 'motivo_campanha.alertas_de_vagas'];
        yield 'CSV' => ['csv', 'comercial_campanhas', null, 'motivo_campanha.lista_informada'];
        yield 'Individual' => ['indiv', 'comercial_campanhas', null, 'motivo_campanha.lista_informada'];
        yield 'contato importado' => ['contatos', 'comercial_campanhas', 'importacao', 'motivo_campanha.contato_importado'];
        yield 'contato incluído pela equipe' => ['contatos', 'comercial_campanhas', 'manual', 'motivo_campanha.contato_incluido'];
        yield 'contato sem origem' => ['contatos', 'comercial_campanhas', null, 'motivo_campanha.contatos_salvos'];
        yield 'teste gravado' => ['teste', 'comercial_campanhas', null, 'motivo_lista'];
        yield 'público inventado' => ['inventado', 'comercial_campanhas', 'importacao', 'motivo_lista'];
    }

    #[DataProvider('origens')]
    #[TestDox('O público $publico pela lista $lista (origem $origem) leva a frase $esperada')]
    public function testFrasePorOrigem(string $publico, string $lista, ?string $origem, string $esperada): void
    {
        $motivo = new MotivoDoEnvioDaCampanha($this->tradutor(), $this->createStub(ContatoComercialRepository::class));

        self::assertSame('emails.comercial.layout.' . $esperada, $motivo->frase($publico, $lista, $origem));
    }

    #[TestDox('Na prévia, o contato salvo do exemplo é buscado pelo e-mail (minúsculo); os outros públicos não consultam a base')]
    public function testDoDestinatarioBuscaAOrigemSoNosContatos(): void
    {
        $contatos = $this->createMock(ContatoComercialRepository::class);
        $contatos->expects(self::once())->method('origensPorEmail')->with(['Ana@Exemplo.com'])->willReturn(['ana@exemplo.com' => 'manual']);
        $motivo = new MotivoDoEnvioDaCampanha($this->tradutor(), $contatos);

        self::assertSame('emails.comercial.layout.motivo_campanha.contato_incluido', $motivo->doDestinatario('contatos', 'comercial_campanhas', 'Ana@Exemplo.com'));
        self::assertSame('emails.comercial.layout.motivo_campanha.cadastrados', $motivo->doDestinatario('prim', 'comercial_campanhas', 'ana@exemplo.com'));
    }

    #[TestDox('Lote vazio não consulta a base de contatos')]
    public function testLoteVazioNaoConsulta(): void
    {
        $contatos = $this->createMock(ContatoComercialRepository::class);
        $contatos->expects(self::never())->method('origensPorEmail');

        self::assertSame([], (new MotivoDoEnvioDaCampanha($this->tradutor(), $contatos))->origensDosContatos([]));
    }

    private function tradutor(): TranslatorInterface
    {
        $tradutor = $this->createStub(TranslatorInterface::class);
        $tradutor->method('trans')->willReturnArgument(0);

        return $tradutor;
    }
}
