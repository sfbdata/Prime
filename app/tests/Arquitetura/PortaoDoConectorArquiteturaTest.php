<?php

declare(strict_types=1);

namespace App\Tests\Arquitetura;

use App\Conector\Service\PortaoDoConector;
use App\EventListener\TenantContextValidatorListener;
use App\Tenant\Service\BarreirasDoEscritorio;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * As travas do conector de I.A. que se conferem pelo código (spec mcp-remoto-oauth 4.1 e 6.3; 12.2 e 12.19):
 *  (a) nenhuma classe de `App\Conector\` alcança os UseCases de administrador da 6.1 (teto do conector);
 *  (b) as barreiras do escritório moram num lugar só, que o listener do navegador e o portão usam;
 *  (c) só o PortaoDoConector liga o filtro de escritório, fixa o escritório no TenantContext sem sessão e liga o modo
 *      do conector.
 */
#[CoversNothing]
final class PortaoDoConectorArquiteturaTest extends TestCase
{
    private const SRC = __DIR__ . '/../../src';
    private const CONECTOR = self::SRC . '/Conector';

    /** UseCases de administrador que ficam fora do conector (6.1, linha "Administrador"; decisão 14.b). */
    private const USECASES_DE_ADMINISTRADOR = [
        'Pasta/UseCase/ConfigurarCadeadoDaPastaUseCase.php',
        'Pasta/UseCase/RemoverCadeadoDaPastaUseCase.php',
        'Pasta/UseCase/AlternarTrancaDaPastaUseCase.php',
        'Pasta/UseCase/DecidirPedidoDeAcessoUseCase.php',
        'Pasta/UseCase/OcultarAbaParaTodosUseCase.php',
        'Pasta/UseCase/DefinirQuemNaoVeAbaUseCase.php',
        'Pasta/UseCase/MostrarAbaParaTodosUseCase.php',
        'Pasta/UseCase/ListarEquipeParaOcultarAbaUseCase.php',
        'Auditoria/UseCase/DesfazerAlteracaoAuditLogUseCase.php',
        'Sync/UseCase/ConectarDriveUseCase.php',
        'Sync/UseCase/DefinirRootFolderDriveUseCase.php',
        'Sync/UseCase/DesconectarDriveUseCase.php',
        'Agenda/UseCase/AcrescentarDataUseCase.php',
        'Agenda/UseCase/RenomearDataUseCase.php',
        'Agenda/UseCase/OcultarDataUseCase.php',
        'Agenda/UseCase/RestaurarDatasDoSistemaUseCase.php',
    ];

    #[TestDox('(a) nenhuma classe do conector depende de um UseCase de administrador da 6.1')]
    public function testConectorNaoAlcancaFuncoesDeAdministrador(): void
    {
        $nomes = [];
        foreach (self::USECASES_DE_ADMINISTRADOR as $arquivo) {
            self::assertFileExists(self::SRC . '/' . $arquivo, 'UseCase da lista sumiu ou mudou de lugar: atualize a lista (e a 6.1 da spec)');
            $nomes[] = basename($arquivo, '.php');
        }

        $violacoes = [];
        foreach (self::arquivosDoConector() as $relativo => $codigo) {
            foreach ($nomes as $nome) {
                if (preg_match('/\b' . $nome . '\b/', $codigo) === 1) {
                    $violacoes[] = $relativo . ' usa ' . $nome;
                }
            }
        }

        self::assertSame([], $violacoes, 'O conector não faz função de administrador, nem para Master e primário (6.3, item 1).');
    }

    #[TestDox('(b) o listener do navegador e o portão dependem do mesmo serviço de barreiras')]
    public function testListenerEPortaoUsamAsMesmasBarreiras(): void
    {
        foreach ([TenantContextValidatorListener::class, PortaoDoConector::class] as $classe) {
            $tipos = array_map(
                static fn (\ReflectionParameter $p): string => (string) $p->getType(),
                (new \ReflectionClass($classe))->getConstructor()?->getParameters() ?? [],
            );
            self::assertContains(BarreirasDoEscritorio::class, $tipos, $classe . ' tem de aplicar as barreiras pelo BarreirasDoEscritorio');
        }
    }

    #[TestDox('(b) a regra das barreiras não é copiada no listener nem no conector')]
    public function testBarreirasNaoSaoDuplicadas(): void
    {
        // As peças da regra: quem as chama fora do BarreirasDoEscritorio está refazendo a barreira.
        $pecas = ['AvisoDeContaSuspensa', 'mensagemDeAcesso', 'suspensaAPedido', 'getExcluidoEm', '->bloqueia('];
        $alvos = ['EventListener/TenantContextValidatorListener.php' => (string) file_get_contents(self::SRC . '/EventListener/TenantContextValidatorListener.php')];
        foreach (self::arquivosDoConector() as $relativo => $codigo) {
            $alvos['Conector/' . $relativo] = $codigo;
        }

        $violacoes = [];
        foreach ($alvos as $relativo => $codigo) {
            foreach ($pecas as $peca) {
                if (str_contains($codigo, $peca)) {
                    $violacoes[] = $relativo . ' usa ' . $peca;
                }
            }
        }

        self::assertSame([], $violacoes, 'A regra das barreiras mora no BarreirasDoEscritorio (spec mcp-remoto-oauth 4.1).');
    }

    #[TestDox('(c) dentro do conector, só o portão liga o filtro de escritório (pelo FiltroDoEscritorio)')]
    public function testSoOPortaoLigaOFiltro(): void
    {
        $violacoes = [];
        foreach (self::arquivosDoConector() as $relativo => $codigo) {
            if (str_contains($codigo, 'getFilters(') && $relativo !== 'Service/FiltroDoEscritorioDoctrine.php') {
                $violacoes[] = $relativo . ' mexe nos filtros do Doctrine';
            }
            $usaOFiltro = preg_match('/\bFiltroDoEscritorio(Doctrine)?\b/', $codigo) === 1;
            if ($usaOFiltro && !in_array($relativo, ['Service/PortaoDoConector.php', 'Service/FiltroDoEscritorio.php', 'Service/FiltroDoEscritorioDoctrine.php'], true)) {
                $violacoes[] = $relativo . ' usa o FiltroDoEscritorio';
            }
        }

        self::assertSame([], $violacoes, 'Só o PortaoDoConector liga e confere o filtro (spec mcp-remoto-oauth 4.1).');
    }

    #[TestDox('(c) só o portão fixa o escritório sem sessão no TenantContext e liga o modo do conector')]
    public function testSoOPortaoFixaOEscritorioELigaOModo(): void
    {
        $fixa = [];
        $modo = [];
        foreach (self::arquivos(self::SRC) as $relativo => $codigo) {
            if (str_contains($codigo, 'fixarSemSessao(') && !in_array($relativo, ['Conector/Service/PortaoDoConector.php', 'Service/Tenant/TenantContext.php'], true)) {
                $fixa[] = $relativo;
            }
            $citaOModo = preg_match('/\bModoDoConector\b/', $codigo) === 1;
            $ligaOModo = preg_match('/modo\w*->ligar\(/i', $codigo) === 1;
            if (($citaOModo || $ligaOModo) && !in_array($relativo, ['Conector/Service/PortaoDoConector.php', 'Conector/Service/ModoDoConector.php', 'Service/PermissionChecker.php', 'Tenant/Service/RegraDosPrimarios.php'], true)) {
                $modo[] = $relativo . ' cita o ModoDoConector';
            }
            if ($ligaOModo && $relativo !== 'Conector/Service/PortaoDoConector.php') {
                $modo[] = $relativo . ' liga o modo';
            }
        }

        self::assertSame([], $fixa, 'Só o PortaoDoConector fixa o escritório sem sessão (spec mcp-remoto-oauth 4.1).');
        self::assertSame([], $modo, 'Só o PortaoDoConector liga o modo; só o PermissionChecker e a RegraDosPrimarios o consultam (6.3).');
    }

    /** @return array<string, string> conteúdo por caminho relativo a src/Conector */
    private static function arquivosDoConector(): array
    {
        return is_dir(self::CONECTOR) ? self::arquivos(self::CONECTOR) : [];
    }

    /** @return array<string, string> conteúdo por caminho relativo à raiz */
    private static function arquivos(string $raiz): array
    {
        $arquivos = [];
        foreach ((new Finder())->files()->in($raiz)->name('*.php')->sortByName() as $arquivo) {
            $arquivos[str_replace('\\', '/', $arquivo->getRelativePathname())] = $arquivo->getContents();
        }

        return $arquivos;
    }
}
