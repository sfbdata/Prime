<?php

declare(strict_types=1);

namespace App\Tests\Master\Integration;

use App\Master\Enum\TipoDeDivulgacao;
use App\Master\Exception\OperacaoDeMasterRecusada;
use App\Master\Exception\RecusaDaSeccional;
use App\Master\Exception\RespostaRecusada;
use App\Master\Exception\SeccionalNaoEncontrada;
use App\Master\Exception\SeccionalRecusada;
use App\Master\Exception\SubsecaoNaoEncontrada;
use App\Master\Service\FaixaEtaria;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Idioma do sistema (spec docs/specs/idioma-do-sistema.md, P2b da b5), no molde do CatalogoDasRecusasTest dos Perfis:
 * as recusas das seccionais continuam como texto em português nas constantes RECUSA_* (é o que os testes comparam e o
 * que a exceção leva como mensagem) e o SeccionaisOabController traduz pela chave. O português do catálogo tem de ser
 * o mesmo da constante, com o limite no lugar do %d, senão a tela muda. Vale também para o rótulo do tipo de
 * divulgação e o da faixa etária, que o painel recebe do PHP. P3: as recusas da Central de Notificações
 * (RespostaRecusada) e as de promover e remover Master (OperacaoDeMasterRecusada), no mesmo padrão.
 */
#[CoversNothing]
final class CatalogoDasRecusasDoMasterTest extends KernelTestCase
{
    private static function tradutor(): TranslatorInterface
    {
        self::bootKernel();
        $tradutor = static::getContainer()->get(TranslatorInterface::class);
        self::assertInstanceOf(TranslatorInterface::class, $tradutor);

        return $tradutor;
    }

    #[TestDox('Toda recusa das seccionais tem chave no catálogo, com o mesmo texto em português (o limite de 1000 sem separador)')]
    public function testRecusasDasSeccionais(): void
    {
        $tradutor = self::tradutor();

        foreach ([SeccionalRecusada::class, SeccionalNaoEncontrada::class, SubsecaoNaoEncontrada::class] as $classe) {
            $recusas = array_filter(
                (new \ReflectionClass($classe))->getConstants(),
                static fn (string $nome): bool => str_starts_with($nome, 'RECUSA'),
                ARRAY_FILTER_USE_KEY,
            );
            self::assertNotEmpty($recusas, $classe);
            self::assertCount(count($recusas), $classe::CHAVE_DA_RECUSA, $classe . ': uma chave por recusa');

            foreach ($recusas as $nome => $texto) {
                self::assertIsString($texto);
                $parametros = str_contains($texto, '%d') ? ['limite' => 1000] : [];
                /** @var RecusaDaSeccional $recusa */
                $recusa = new $classe($texto, $parametros);

                self::assertSame(sprintf($texto, 1000), $recusa->getMessage(), $nome . ': a mensagem continua o português');
                self::assertSame($recusa->getMessage(), $tradutor->trans($recusa->chave(), $recusa->parametros(), null, 'pt_BR'), $classe . '::' . $nome);
            }
        }
    }

    #[TestDox('Recusa fora das constantes não vira texto solto: falha na hora')]
    public function testRecusaSemChave(): void
    {
        $this->expectException(\LogicException::class);

        new SeccionalRecusada('Texto que não é constante.');
    }

    #[TestDox('Toda recusa da Central de Notificações e dos Masters tem chave no catálogo, com o mesmo texto em português')]
    public function testRecusasDaCentralEDosMasters(): void
    {
        $tradutor = self::tradutor();

        foreach ([RespostaRecusada::class, OperacaoDeMasterRecusada::class] as $classe) {
            $recusas = array_filter(
                (new \ReflectionClass($classe))->getConstants(),
                static fn (string $nome): bool => str_starts_with($nome, 'RECUSA'),
                ARRAY_FILTER_USE_KEY,
            );
            self::assertNotEmpty($recusas, $classe);
            self::assertCount(count($recusas), $classe::CHAVE_DA_RECUSA, $classe . ': uma chave por recusa');

            foreach ($recusas as $nome => $texto) {
                self::assertIsString($texto);
                $recusa = new $classe($texto);

                self::assertSame($texto, $recusa->getMessage(), $nome . ': a mensagem continua o português');
                self::assertSame($texto, $tradutor->trans($recusa->chave(), ['papel' => 'Master'], null, 'pt_BR'), $classe . '::' . $nome);
            }
        }
    }

    #[TestDox('Recusa da Central fora das constantes falha na hora')]
    public function testRecusaDaCentralSemChave(): void
    {
        $this->expectException(\LogicException::class);

        new RespostaRecusada('Texto que não é constante.');
    }

    #[TestDox('Recusa dos Masters fora das constantes falha na hora')]
    public function testRecusaDosMastersSemChave(): void
    {
        $this->expectException(\LogicException::class);

        new OperacaoDeMasterRecusada('Texto que não é constante.');
    }

    #[TestDox('O rótulo do tipo de divulgação e o da faixa etária têm o mesmo português no catálogo')]
    public function testRotulosQueVemDoPhp(): void
    {
        $tradutor = self::tradutor();

        foreach (TipoDeDivulgacao::cases() as $tipo) {
            self::assertSame($tipo->rotulo(), $tradutor->trans($tipo->chave(), [], null, 'pt_BR'), $tipo->value);
        }
        foreach (FaixaEtaria::FAIXAS as [$rotulo]) {
            $chave = FaixaEtaria::chaveDoRotulo($rotulo);
            self::assertNotNull($chave, $rotulo);
            self::assertSame($rotulo, $tradutor->trans($chave[0], $chave[1], null, 'pt_BR'), $rotulo);
        }
        self::assertNull(FaixaEtaria::chaveDoRotulo('outro'));
    }
}
