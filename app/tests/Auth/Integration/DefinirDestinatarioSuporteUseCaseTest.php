<?php

declare(strict_types=1);

namespace App\Tests\Auth\Integration;

use App\Auth\DTO\DefinirDestinatarioSuporteInput;
use App\Auth\Entity\AjusteEntrada;
use App\Auth\Repository\AjusteEntradaRepository;
use App\Auth\Service\PaginaDeEntrada;
use App\Auth\UseCase\DefinirDestinatarioSuporteUseCase;
use App\Tests\Auth\Cenario\CenarioDaEntrada;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integração, e não unitário: as opções válidas vêm do PaginaDeEntrada, que é `final`. As opções não dependem da requisição
 * (nem do escritório atual): a equipe da conta do Master é coberta no teste funcional.
 */
#[CoversClass(DefinirDestinatarioSuporteUseCase::class)]
final class DefinirDestinatarioSuporteUseCaseTest extends KernelTestCase
{
    use CenarioDaEntrada;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->limparAjustesDaEntrada();
    }

    private function useCase(): DefinirDestinatarioSuporteUseCase
    {
        return static::getContainer()->get(DefinirDestinatarioSuporteUseCase::class);
    }

    #[TestDox('Escolher um Master grava o ajuste com quem alterou e devolve o texto do desenho')]
    public function testEscolheMaster(): void
    {
        $quemEscolhe = $this->master('Marta Master');
        $escolhido = $this->master('Rafael Lima Souza');

        $texto = $this->useCase()->executar(new DefinirDestinatarioSuporteInput((int) $escolhido->getId(), $quemEscolhe));

        self::assertSame('A partir de agora, Rafael recebe as mensagens no Chat I.A.', $texto);
        $this->em()->clear();
        $ajuste = static::getContainer()->get(AjusteEntradaRepository::class)->daChave(AjusteEntrada::SUPORTE_DESTINATARIO);
        self::assertNotNull($ajuste);
        self::assertSame((string) $escolhido->getId(), $ajuste->getValor());
        self::assertSame($quemEscolhe->getId(), $ajuste->getAlteradoPor()?->getId());
        self::assertSame($escolhido->getId(), static::getContainer()->get(PaginaDeEntrada::class)->destinatarioDoSuporte()?->getId());
    }

    #[TestDox('Trocar a escolha reaproveita o mesmo ajuste')]
    public function testTrocaAEscolha(): void
    {
        $quemEscolhe = $this->master('Marta Master');
        $primeiro = $this->master('Paulo Primeiro');
        $segundo = $this->master('Sara Segunda');

        $this->useCase()->executar(new DefinirDestinatarioSuporteInput((int) $primeiro->getId(), $quemEscolhe));
        $texto = $this->useCase()->executar(new DefinirDestinatarioSuporteInput((int) $segundo->getId(), $quemEscolhe));

        self::assertSame('A partir de agora, Sara recebe as mensagens no Chat I.A.', $texto);
        $this->em()->clear();
        self::assertSame(1, static::getContainer()->get(AjusteEntradaRepository::class)->count(['chave' => AjusteEntrada::SUPORTE_DESTINATARIO]));
        self::assertSame((string) $segundo->getId(), static::getContainer()->get(AjusteEntradaRepository::class)->daChave(AjusteEntrada::SUPORTE_DESTINATARIO)?->getValor());
    }

    #[TestDox('Pessoa fora das opções (comum sem escritório atual, Master inativo, id inexistente) é recusada e nada é gravado')]
    public function testPessoaForaDasOpcoes(): void
    {
        $quemEscolhe = $this->master('Marta Master');
        $comum = $this->pessoa('Carla Comum');
        $inativo = $this->master('Ivo Inativo', ativa: false);

        foreach ([(int) $comum->getId(), (int) $inativo->getId(), 0, -1] as $id) {
            try {
                $this->useCase()->executar(new DefinirDestinatarioSuporteInput($id, $quemEscolhe));
                self::fail('Deveria recusar o id ' . $id);
            } catch (\DomainException $e) {
                self::assertSame('Pessoa inválida.', $e->getMessage());
            }
        }
        self::assertNull(static::getContainer()->get(AjusteEntradaRepository::class)->daChave(AjusteEntrada::SUPORTE_DESTINATARIO));
    }
}
