<?php

declare(strict_types=1);

namespace App\Tests\Central\Support;

use App\Central\Entity\Conversa;
use App\Central\Entity\Participante;
use App\Central\Repository\ParticipanteRepository;
use App\Central\Service\AcessoACentral;
use App\Central\Service\GuardaDoEncaminhamento;
use App\Central\Service\ItensEncaminhaveisDaPasta;
use App\Djen\Repository\PublicacaoDjenRepository;
use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Inteligencia\Service\VisibilidadeDoFinanceiroDaPasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Repository\PastaMensagemRepository;
use App\Pasta\Repository\PastaObservacaoDetalhesRepository;
use App\Pasta\Repository\PastaObservacaoFinanceiraRepository;
use App\Pasta\Repository\PastaPrazoRepository;
use App\Pasta\Repository\PastaRepository;
use App\Pasta\Repository\PastaSecaoRepository;
use App\Pasta\Service\AcessoAPasta;
use App\Processo\Repository\NotaTecnicaRepository;
use App\Processo\Repository\ProcessoRepository;
use App\Repository\UserRepository;
use App\Repository\UserTenantRepository;
use App\Service\PermissionChecker;
use App\Tarefa\Repository\TarefaRepository;
use App\Tests\Inteligencia\Support\DefineId;
use App\Twig\NumeroProcessoExtension;

/**
 * Peças dos testes de unidade do "Encaminhar via Chat I.A": as guardas e o leitor de itens montados de verdade sobre
 * repositórios de mentira. Quem usa estende `TestCase`.
 *
 * Os dois repositórios de observação são `final` (legado, não se padroniza): entram sem construtor e nunca são
 * chamados nestes testes, que leem o registro de Dados.
 */
trait MontaEncaminhamentoTrait
{
    private function pessoa(int $id, string $nome): User
    {
        $u = new User();
        $u->setFullName($nome);
        DefineId::em($u, $id);

        return $u;
    }

    private function pastaDe(Tenant $tenant, int $id = 50, string $nup = 'PASTA-50'): Pasta
    {
        $pasta = (new Pasta())->setTenant($tenant)->setNup($nup);
        DefineId::em($pasta, $id);

        return $pasta;
    }

    /** @param list<int> $quemVe ids de quem vê a pasta */
    private function acessoAPasta(array $quemVe): AcessoAPasta
    {
        $checker = $this->createStub(PermissionChecker::class);
        $checker->method('canAccessResource')->willReturnCallback(
            static fn (User $u): bool => in_array((int) $u->getId(), $quemVe, true),
        );

        return new AcessoAPasta($checker);
    }

    /**
     * @param list<User> $ativos colaboradores com vínculo ativo
     */
    private function acessoACentral(array $ativos, ParticipanteRepository $participantes): AcessoACentral
    {
        $ids = array_map(static fn (User $u): int => (int) $u->getId(), $ativos);
        $vinculos = $this->createStub(UserTenantRepository::class);
        $vinculos->method('existeVinculoAtivo')->willReturnCallback(static fn (User $u): bool => in_array((int) $u->getId(), $ids, true));
        $usuarios = $this->createStub(UserRepository::class);
        $usuarios->method('findColaboradoresAtivosPorTenant')->willReturn($ativos);

        return new AcessoACentral($vinculos, $participantes, $usuarios);
    }

    /** @param list<int>|null $quemVeOFinanceiro ids de quem vê o financeiro da pasta (nulo: todos) */
    private function guarda(?Pasta $pasta, AcessoAPasta $acessoAPasta, ParticipanteRepository $participantes, ?array $quemVeOFinanceiro = null): GuardaDoEncaminhamento
    {
        $pastas = $this->createStub(PastaRepository::class);
        $pastas->method('findOneBy')->willReturn($pasta);
        $financeiro = $this->createStub(VisibilidadeDoFinanceiroDaPasta::class);
        $financeiro->method('podeVer')->willReturnCallback(
            static fn (User $u): bool => $quemVeOFinanceiro === null || in_array((int) $u->getId(), $quemVeOFinanceiro, true),
        );

        return new GuardaDoEncaminhamento($pastas, $acessoAPasta, $participantes, $financeiro);
    }

    private function itens(?PastaMensagemRepository $mensagens = null): ItensEncaminhaveisDaPasta
    {
        return new ItensEncaminhaveisDaPasta(
            $mensagens ?? $this->createStub(PastaMensagemRepository::class),
            (new \ReflectionClass(PastaObservacaoDetalhesRepository::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(PastaObservacaoFinanceiraRepository::class))->newInstanceWithoutConstructor(),
            $this->createStub(PastaDocumentoRepository::class),
            $this->createStub(PastaSecaoRepository::class),
            $this->createStub(ProcessoRepository::class),
            $this->createStub(TarefaRepository::class),
            $this->createStub(PastaPrazoRepository::class),
            $this->createStub(PublicacaoDjenRepository::class),
            $this->createStub(PastaRepository::class),
            $this->createStub(NotaTecnicaRepository::class),
            new NumeroProcessoExtension(),
        );
    }

    /** @param list<User> $membros */
    private function participacoes(Tenant $tenant, Conversa $conversa, array $membros): array
    {
        return array_map(
            static fn (User $u): Participante => new Participante($tenant, $conversa, $u, new \DateTimeImmutable('2026-10-19 09:00')),
            $membros,
        );
    }
}
