<?php

declare(strict_types=1);

namespace App\Tests\Auth\Functional;

use App\Auth\DTO\IniciarCadastroPublicoInput;
use App\Auth\Enum\StatusOab;
use App\Auth\UseCase\ConfirmarCadastroUseCase;
use App\Auth\UseCase\IniciarCadastroPublicoUseCase;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Prova o wiring do status de OAB no fluxo público (backend dormente → nao_verificada):
 * Iniciar grava o status no pendente e Confirmar o copia para o User. Não-breaking: a conta
 * e o escritório continuam sendo criados como antes.
 */
final class CadastroStatusOabTest extends KernelTestCase
{
    #[TestDox('O status da OAB percorre Iniciar → Confirmar (dormente = nao_verificada)')]
    public function testStatusPercorreIniciarEConfirmar(): void
    {
        self::bootKernel();
        $c = static::getContainer();

        $input                 = new IniciarCadastroPublicoInput();
        $input->nomeCompleto   = 'Dra. Ana Souza';
        $input->email          = 'ana' . uniqid() . '@adv.com';
        $input->senha          = 'senha-forte-123';
        $input->nomeEscritorio = 'Banca Souza';
        $input->oabNumero      = '12345';
        $input->oabUf          = 'SP';
        $input->aceiteTermos   = true;

        $pendente = $c->get(IniciarCadastroPublicoUseCase::class)->executar($input, '127.0.0.1', 'ua-teste');
        self::assertSame(StatusOab::NaoVerificada, $pendente->getOabStatus(), 'Iniciar grava nao_verificada (backend dormente)');

        $user = $c->get(ConfirmarCadastroUseCase::class)->executar($pendente->getToken());
        self::assertSame(StatusOab::NaoVerificada, $user->getOabStatus(), 'Confirmar copia o status para o User');
        self::assertSame('12345', $user->getOabNumero());
    }
}
