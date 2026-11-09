<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Unit;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\DTO\DadosDoComprovante;
use App\Ponto\Entity\RegistroPonto;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * Os dados que o HMAC sela (E15, D9 e P50): nome inteiro, escritório e CNPJ, data e hora do servidor, tipo, método e
 * NSR. Nunca CPF nem localização, mesmo que a batida tenha coordenadas. A forma canônica é estável: o mesmo dado gera
 * sempre o mesmo texto.
 */
#[Group('ponto')]
#[CoversClass(DadosDoComprovante::class)]
final class DadosDoComprovanteTest extends TestCase
{
    #[TestDox('Da batida: nome inteiro, escritório, CNPJ, hora, tipo, método e NSR; sem CPF e sem localização')]
    public function testDaBatidaSemCpfNemLocalizacao(): void
    {
        $dados = DadosDoComprovante::daBatida($this->batida(), 'BJP-ABCD-EFGH-JK23');
        $canonico = $dados->canonico();

        self::assertSame(
            '{"formato":1,"codigo":"BJP-ABCD-EFGH-JK23","nsr":42,"escritorio_id":7,"nome":"Maria da Silva Pereira",'
            . '"escritorio":"Escritório Modelo","cnpj":"12345678000190","data_hora":"2026-11-09T08:01:02-03:00",'
            . '"tipo":"entrada","metodo":"sistema"}',
            $canonico,
        );
        self::assertStringNotContainsStringIgnoringCase('cpf', $canonico, 'o CPF fica no perfil e nunca entra no selo');
        self::assertStringNotContainsString('-23.55', $canonico, 'sem latitude');
        self::assertStringNotContainsString('-46.63', $canonico, 'sem longitude');
        self::assertStringNotContainsString('Sede Centro', $canonico, 'sem o nome da sede');
    }

    #[TestDox('Forma canônica: ida e volta iguais, e o texto da validação no formato do desenho')]
    public function testIdaEVolta(): void
    {
        $dados = DadosDoComprovante::daBatida($this->batida(), 'BJP-ABCD-EFGH-JK23');
        $volta = DadosDoComprovante::deCanonico($dados->canonico());

        self::assertSame($dados->canonico(), $volta->canonico());
        self::assertSame('Entrada de Maria da Silva Pereira em 09/11/2026, 08:01:02, NSR 000000042.', $volta->textoDaValidacao());
        self::assertSame('Sistema', $volta->nomeDoMetodo());
        self::assertSame('12.345.678/0001-90', $volta->cnpjFormatado());
    }

    #[TestDox('Texto fora da forma canônica (chave a mais, faltando, fora de ordem ou outro formato) é recusado')]
    public function testRecusaForaDoFormato(): void
    {
        $canonico = DadosDoComprovante::daBatida($this->batida(), 'BJP-ABCD-EFGH-JK23')->canonico();
        $dados = json_decode($canonico, true);
        $variantes = [
            'não é JSON'  => 'xyz',
            'chave a mais' => json_encode($dados + ['cpf' => '123']),
            'faltando'     => json_encode(array_diff_key($dados, ['nsr' => 0])),
            'fora de ordem' => json_encode(['codigo' => $dados['codigo']] + $dados),
            'outro formato' => json_encode(['formato' => 2] + $dados),
        ];
        foreach ($variantes as $caso => $texto) {
            try {
                DadosDoComprovante::deCanonico((string) $texto);
                self::fail('deveria recusar: ' . $caso);
            } catch (\UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
    }

    #[TestDox('Sem NSR não há comprovante (D2: o NSR vem antes)')]
    public function testSemNsrNaoTemComprovante(): void
    {
        $batida = $this->batida();
        $semNsr = new RegistroPonto();
        $semNsr->setUser($batida->getUser());
        $semNsr->setTenant($batida->getTenant());
        $semNsr->setTipo(RegistroPonto::TIPO_ENTRADA);
        $semNsr->setDataHora(new \DateTime());

        $this->expectException(\LogicException::class);
        DadosDoComprovante::daBatida($semNsr, 'BJP-ABCD-EFGH-JK23');
    }

    private function batida(): RegistroPonto
    {
        $pessoa = new User();
        $pessoa->setEmail('maria@teste.com');
        $pessoa->setFullName('Maria da Silva Pereira');
        $escritorio = new Tenant();
        $escritorio->setName('Escritório Modelo');
        $escritorio->setCnpj('12345678000190');
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($escritorio, 7);

        $batida = new RegistroPonto();
        $batida->setUser($pessoa);
        $batida->setTenant($escritorio);
        $batida->setTipo(RegistroPonto::TIPO_ENTRADA);
        $batida->setDataHora(new \DateTime('2026-11-09 08:01:02', new \DateTimeZone('America/Sao_Paulo')));
        $batida->setLatitude('-23.55000000');
        $batida->setLongitude('-46.63000000');
        $batida->setSedeNomeSnapshot('Sede Centro');
        $batida->setNsr(42);

        return $batida;
    }
}
