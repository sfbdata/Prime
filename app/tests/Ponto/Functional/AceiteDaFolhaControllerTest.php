<?php

declare(strict_types=1);

namespace App\Tests\Ponto\Functional;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Ponto\Controller\AceiteDaFolhaController;
use App\Tests\Functional\JusPrimeWebTestCase;
use App\Tests\Ponto\Support\CenarioDaFolhaDoMesTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * O envio da janela "Assinar folha" (spec ponto-desenho-0811, E16, P22 e P33; decisões 1, 15 e 19): POST em
 * `/ponto/folha/assinar` com o token `ponto_folha_assinar`, só a própria pessoa, com o módulo Ponto, no escritório da
 * sessão. Volta para a /ponto na competência com o aviso do topo, como as janelas da E4: "Folha assinada." ou a frase
 * da recusa, sem gravar nada.
 */
#[Group('ponto')]
#[CoversClass(AceiteDaFolhaController::class)]
final class AceiteDaFolhaControllerTest extends JusPrimeWebTestCase
{
    use ClockSensitiveTrait;
    use CenarioDaFolhaDoMesTrait;

    private const SEGREDO = 'segredo-de-teste-do-envio-do-aceite';
    private const IP = '189.6.10.2';
    private const NAVEGADOR = 'Mozilla/5.0 (teste do aceite)';

    private KernelBrowser $client;
    private Tenant $escritorio;
    private User $maria;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        self::mockTime(new \DateTimeImmutable('2026-11-09 10:15:30', new \DateTimeZone(date_default_timezone_get())));
        // O segredo entra no container antes do primeiro uso e não pode ser trocado depois: o teste sem ele é a exceção.
        $this->segredo($this->name() === 'testSemOSegredo' ? null : self::SEGREDO);
        $this->instalarCsrfFalso();
        $this->escritorio = $this->escritorio('Escritório do envio do aceite');
        $this->maria = $this->pessoaComOutubro($this->escritorio);
    }

    #[TestDox('Concordo: grava o aceite com o IP e o navegador e volta para a /ponto na competência com "Folha assinada."')]
    public function testConcordo(): void
    {
        $this->enviar();

        self::assertResponseRedirects('/ponto/?competencia=2026-10');
        self::assertSame([AceiteDaFolhaController::FOLHA_ASSINADA], $this->avisos('success'));
        $aceites = $this->aceitesNoBanco($this->escritorio, $this->maria);
        self::assertCount(1, $aceites);
        self::assertSame(['concordo', null, self::IP, self::NAVEGADOR], [$aceites[0]['situacao'], $aceites[0]['ressalva'], $aceites[0]['ip'], $aceites[0]['navegador']]);
        self::assertSame(['folha_emitida', 'folha_assinada'], $this->acoesNaTrilha($this->escritorio));
    }

    #[TestDox('Discordo com texto: grava a ressalva aparada')]
    public function testRessalva(): void
    {
        $this->enviar(['situacao' => 'ressalva', 'ressalva' => "  O dia 6 foi de folga.\n"]);

        self::assertSame([AceiteDaFolhaController::FOLHA_ASSINADA], $this->avisos('success'));
        $aceites = $this->aceitesNoBanco($this->escritorio, $this->maria);
        self::assertSame(['ressalva', 'O dia 6 foi de folga.'], [$aceites[0]['situacao'], $aceites[0]['ressalva']]);
    }

    #[TestDox('Navegador e ressalva com UTF-8 inválido: o byte inválido vira "?" antes de selar; grava, volta com "Folha assinada." e o aceite vale (nunca 500)')]
    public function testUtf8InvalidoNoNavegadorENaRessalva(): void
    {
        $this->enviar(['situacao' => 'ressalva', 'ressalva' => "O dia 6 foi \xFF de folga."], navegador: "Mozilla/5.0 \xC3\x28teste)");

        self::assertResponseRedirects('/ponto/?competencia=2026-10');
        self::assertSame([AceiteDaFolhaController::FOLHA_ASSINADA], $this->avisos('success'));
        $aceites = $this->aceitesNoBanco($this->escritorio, $this->maria);
        self::assertSame(['O dia 6 foi ? de folga.', 'Mozilla/5.0 ?(teste)'], [$aceites[0]['ressalva'], $aceites[0]['navegador']]);
        self::assertFalse($this->estadoDoAceite()->daCompetencia($this->escritorio, $this->maria, 2026, 10)->alterada, 'o selo confere com o que foi gravado');
    }

    #[TestDox('Ressalva de 1.000 caracteres com quebras CRLF (o navegador conta a quebra como 1): passa e grava só LF')]
    public function testRessalvaComQuebrasCrlf(): void
    {
        $ressalva = implode("\r\n", array_fill(0, 10, str_repeat('a', 99))) . 'a';   // 1.009 caracteres com CRLF, 1.000 com LF

        $this->enviar(['situacao' => 'ressalva', 'ressalva' => $ressalva]);

        self::assertSame([AceiteDaFolhaController::FOLHA_ASSINADA], $this->avisos('success'));
        $gravada = (string) $this->aceitesNoBanco($this->escritorio, $this->maria)[0]['ressalva'];
        self::assertSame(str_replace("\r\n", "\n", $ressalva), $gravada);
        self::assertSame(1000, mb_strlen($gravada));
        self::assertFalse($this->estadoDoAceite()->daCompetencia($this->escritorio, $this->maria, 2026, 10)->alterada, 'o selo confere com o que foi gravado');
    }

    #[TestDox('Sem o módulo Ponto no escritório da sessão: 403 e nada gravado')]
    public function testSemOModulo(): void
    {
        $semModulo = $this->pessoa('Sem o Ponto');
        $this->vincular($semModulo, $this->escritorio, []);

        $this->enviar([], $semModulo);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->aceitesNoBanco($this->escritorio, $semModulo));
        self::assertSame([], $this->acoesNaTrilha($this->escritorio));
    }

    #[TestDox('Token inválido: "Token de segurança inválido." e nada gravado')]
    public function testTokenInvalido(): void
    {
        $this->enviar(['_token' => 'token-errado']);

        self::assertResponseRedirects('/ponto/?competencia=2026-10');
        self::assertSame([AceiteDaFolhaController::TOKEN_INVALIDO], $this->avisos('danger'));
        $this->assertNadaGravado();
    }

    #[TestDox('Outro escritório: na sessão de Y, a folha de outubro de X não é assinada (nada no pedido escolhe o escritório)')]
    public function testOutroEscritorio(): void
    {
        $outro = $this->escritorio('Escritório Y do aceite');
        $this->vincular($this->maria, $outro);

        $this->enviar(['tenant_id' => (string) $this->escritorio->getId(), 'user_id' => (string) $this->maria->getId()], $this->maria, $outro);

        self::assertSame(['Este mês não está na sua folha de ponto.'], $this->avisos('warning'), 'em Y a pessoa não tem outubro');
        $this->assertNadaGravado();
        self::assertSame([], $this->aceitesNoBanco($outro, $this->maria));
        self::assertSame([], $this->acoesNaTrilha($outro));
    }

    #[TestDox('Mês aberto pelo relógio do servidor: "Só dá para assinar a folha de um mês já fechado."')]
    public function testMesAberto(): void
    {
        $this->diaCompleto($this->maria, $this->escritorio, '2026-11-03');

        $this->enviar(['competencia' => '2026-11']);

        self::assertResponseRedirects('/ponto/?competencia=2026-11');
        self::assertSame(['Só dá para assinar a folha de um mês já fechado.'], $this->avisos('warning'));
        $this->assertNadaGravado();
    }

    #[TestDox('Discordo sem texto: "Descreva a ressalva." e nada gravado')]
    public function testRessalvaVazia(): void
    {
        $this->enviar(['situacao' => 'ressalva', 'ressalva' => "  \n "]);

        self::assertSame(['Descreva a ressalva.'], $this->avisos('warning'));
        $this->assertNadaGravado();
    }

    #[TestDox('Ressalva com mais de 1.000 caracteres: recusada e nada gravado')]
    public function testRessalvaLonga(): void
    {
        $this->enviar(['situacao' => 'ressalva', 'ressalva' => str_repeat('a', 1001)]);

        self::assertSame(['A ressalva pode ter até 1.000 caracteres.'], $this->avisos('warning'));
        $this->assertNadaGravado();
    }

    #[TestDox('Folha já assinada: "Esta folha já foi assinada." e continua um aceite só')]
    public function testJaAssinada(): void
    {
        $this->enviar();
        $this->enviar(['situacao' => 'ressalva', 'ressalva' => 'Mudei de ideia.']);

        self::assertSame(['Esta folha já foi assinada.'], $this->avisos('warning'));
        $aceites = $this->aceitesNoBanco($this->escritorio, $this->maria);
        self::assertCount(1, $aceites);
        self::assertSame('concordo', $aceites[0]['situacao']);
    }

    #[TestDox('Função desligada no escritório: "O aceite da folha não está disponível neste escritório."')]
    public function testFuncaoDesligadaNoEscritorio(): void
    {
        $this->desligarNoEscritorio($this->escritorio, $this->maria);

        $this->enviar();

        self::assertSame(['O aceite da folha não está disponível neste escritório.'], $this->avisos('warning'));
        $this->assertNadaGravado();
    }

    #[TestDox('Função desligada no Global (Master): a mesma recusa')]
    public function testFuncaoDesligadaNoGlobal(): void
    {
        $this->desligarNoGlobal();

        $this->enviar();

        self::assertSame(['O aceite da folha não está disponível neste escritório.'], $this->avisos('warning'));
        $this->assertNadaGravado();
    }

    #[TestDox('Sem o segredo do HMAC no servidor: a mesma recusa (decisão 16)')]
    public function testSemOSegredo(): void
    {
        $this->enviar();

        self::assertSame(['O aceite da folha não está disponível neste escritório.'], $this->avisos('warning'));
        $this->assertNadaGravado();
    }

    #[TestDox('Competência fora do formato: "Competência inválida." e volta para a /ponto sem mês')]
    public function testCompetenciaInvalida(): void
    {
        $this->enviar(['competencia' => '10/2026']);

        self::assertResponseRedirects('/ponto/');
        self::assertSame(['Competência inválida.'], $this->avisos('warning'));
        $this->assertNadaGravado();
    }

    #[TestDox('Posição fora das duas: "Escolha a sua posição." e nada gravado')]
    public function testSituacaoInvalida(): void
    {
        $this->enviar(['situacao' => 'talvez']);

        self::assertSame(['Escolha a sua posição.'], $this->avisos('warning'));
        $this->assertNadaGravado();
    }

    #[TestDox('Só POST: GET na rota responde 405')]
    public function testSoPost(): void
    {
        $this->logarComTenant($this->client, $this->maria, $this->escritorio);

        $this->client->request('GET', '/ponto/folha/assinar');

        self::assertResponseStatusCodeSame(405);
    }

    /** @param array<string, string> $campos os campos que mudam em relação ao concordo de outubro */
    private function enviar(array $campos = [], ?User $quem = null, ?Tenant $onde = null, string $navegador = self::NAVEGADOR): void
    {
        $this->logarComTenant($this->client, $quem ?? $this->maria, $onde ?? $this->escritorio);
        $this->client->request('POST', '/ponto/folha/assinar', $campos + [
            '_token'      => 'TOKEN_ponto_folha_assinar',
            'competencia' => '2026-10',
            'situacao'    => 'concordo',
            'ressalva'    => '',
        ], [], ['REMOTE_ADDR' => self::IP, 'HTTP_USER_AGENT' => $navegador]);
    }

    /** @return list<string> os avisos do tipo que o envio deixou para a próxima tela */
    private function avisos(string $tipo): array
    {
        $sessao = $this->client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $sessao);

        return $sessao->getFlashBag()->peek($tipo);
    }

    private function assertNadaGravado(): void
    {
        self::assertSame([], $this->aceitesNoBanco($this->escritorio, $this->maria));
        self::assertSame([], $this->folhasNoBanco($this->escritorio, $this->maria));
        self::assertSame([], $this->acoesNaTrilha($this->escritorio));
    }
}
