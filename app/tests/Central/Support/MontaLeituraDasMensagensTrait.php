<?php

declare(strict_types=1);

namespace App\Tests\Central\Support;

use App\Central\Service\LeituraDasMensagens;
use App\Entity\Auth\User;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Service\PermissionChecker;

/**
 * O ponto único de leitura (`LeituraDasMensagens`) montado sobre dublês, para os testes de unidade. Quem usa estende
 * `TestCase`. Sem argumentos, serve aos testes que não têm despacho (a leitura só consulta algo quando há um).
 */
trait MontaLeituraDasMensagensTrait
{
    /**
     * @param list<Pasta> $pastas               o que a busca da pasta (id + escritório) encontra
     * @param list<int>   $quemVe               ids de quem vê a pasta
     * @param list<int>   $quemVeOFinanceiro    ids de quem vê o financeiro (padrão: os mesmos de `$quemVe`)
     */
    private function leituraDasMensagens(array $pastas = [], array $quemVe = [], ?array $quemVeOFinanceiro = null): LeituraDasMensagens
    {
        $repositorio = $this->createStub(PastaRepository::class);
        $repositorio->method('findOneBy')->willReturnCallback(static function (array $criterio) use ($pastas): ?Pasta {
            foreach ($pastas as $pasta) {
                if ($pasta->getId() === $criterio['id'] && $pasta->getTenant() === $criterio['tenant']) {
                    return $pasta;
                }
            }

            return null;
        });
        $checker = $this->createStub(PermissionChecker::class);
        $checker->method('canAccessResource')->willReturnCallback(static fn (User $u): bool => in_array((int) $u->getId(), $quemVe, true));
        $financeiro = $this->createStub(VisibilidadeDoFinanceiroDaPasta::class);
        $financeiro->method('podeVer')->willReturnCallback(static fn (User $u): bool => in_array((int) $u->getId(), $quemVeOFinanceiro ?? $quemVe, true));

        return new LeituraDasMensagens($repositorio, new AcessoAPasta($checker), $financeiro);
    }
}
