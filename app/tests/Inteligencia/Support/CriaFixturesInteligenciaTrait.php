<?php

declare(strict_types=1);

namespace App\Tests\Inteligencia\Support;

use App\Djen\Entity\PublicacaoDjen;
use App\Entity\Auth\User;
use App\Entity\Auth\UserTenant;
use App\Entity\Permission\Permission;
use App\Entity\Tenant\Tenant;
use App\Entity\Tenant\TenantRole;
use App\Entity\Tenant\TenantRolePermission;
use App\Inteligencia\Entity\AnaliseDeInteligencia;
use App\Inteligencia\Entity\ConfiguracaoDeInteligencia;
use App\Inteligencia\Enum\TipoDeAnalise;
use App\Inteligencia\Prompt\PromptResumoDoPush;
use App\Pasta\Entity\Pasta;
use App\Processo\Entity\Processo;
use App\Tests\Pasta\Functional\CriaFixturesPushDaPastaTrait;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Fixtures da BlueJus IA por cima das do Push da pasta (escritório, usuário, pasta, processo,
 * publicação): liga a IA no escritório, cria usuário com permissões específicas, cria análises.
 */
trait CriaFixturesInteligenciaTrait
{
    use CriaFixturesPushDaPastaTrait;

    private function ligarIaNoTenant(
        Tenant $tenant,
        User $por,
        int $limiteDiario = 50,
        int $limiteMensal = 500,
        bool $mascarar = true,
    ): ConfiguracaoDeInteligencia {
        $configuracao = new ConfiguracaoDeInteligencia($tenant);
        $configuracao->atualizar(true, $limiteDiario, $limiteMensal, $mascarar, $por);
        $this->em()->persist($configuracao);
        $this->em()->flush();

        return $configuracao;
    }

    /**
     * Papel comum com EXATAMENTE as permissões informadas (cria o código no catálogo se faltar).
     *
     * @param list<string> $codigos
     */
    private function criarUsuarioComPermissoes(Tenant $tenant, array $codigos, string $rotulo = 'ia'): User
    {
        $em = $this->em();
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $role = new TenantRole();
        $role->setTenant($tenant);
        $role->setName('Papel ' . $rotulo . ' ' . uniqid());
        $role->setIsSystem(false);
        $em->persist($role);

        foreach ($codigos as $codigo) {
            $perm = $em->getRepository(Permission::class)->findOneBy(['code' => $codigo]);
            if ($perm === null) {
                $perm = new Permission();
                $perm->setCode($codigo);
                $perm->setDescription($codigo);
                $perm->setGroup(explode('.', $codigo)[0]);
                $em->persist($perm);
            }

            $vinculo = new TenantRolePermission();
            $vinculo->setTenantRole($role);
            $vinculo->setPermission($perm);
            $em->persist($vinculo);
            $role->getTenantRolePermissions()->add($vinculo);
        }

        $user = new User();
        $user->setEmail($rotulo . '_' . uniqid() . '@test.com');
        $user->setFullName('Usuário ' . $rotulo);
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setPassword($hasher->hashPassword($user, 'senha123'));
        $em->persist($user);

        $ut = new UserTenant($user, $tenant);
        $ut->setTenantRole($role);
        $em->persist($ut);
        $em->flush();

        return $user;
    }

    /**
     * Pasta com um processo vinculado e uma publicação do DJEN com o texto informado.
     *
     * @return array{Pasta, Processo, PublicacaoDjen}
     */
    private function criarPastaComPublicacao(
        Tenant $tenant,
        string $numero = '07011345720258070007',
        string $texto = 'Intimação da sentença. Prazo de 15 dias para apelação.',
    ): array {
        $pasta = $this->criarPasta($tenant);
        $processo = $this->criarProcesso($tenant, $numero);
        $this->vincular($pasta, $processo);
        $publicacao = $this->criarPublicacao($tenant, $this->proximoDjenId(), $numero, '2026-10-01', $processo);
        $publicacao->setTexto($texto);
        $this->em()->flush();

        return [$pasta, $processo, $publicacao];
    }

    /** `djen_id` é BIGINT: precisa ser numérico e único por escritório. */
    private function proximoDjenId(): string
    {
        return (string) random_int(100_000_000, 999_999_999_999);
    }

    /** @param list<string> $chaves */
    private function criarAnalisePendente(Tenant $tenant, ?User $solicitante, Pasta $pasta, array $chaves = []): AnaliseDeInteligencia
    {
        $analise = new AnaliseDeInteligencia(
            tenant: $tenant,
            solicitante: $solicitante,
            tipo: TipoDeAnalise::ResumoPush,
            alvoTipo: AnaliseDeInteligencia::ALVO_PASTA,
            alvoId: (int) $pasta->getId(),
            versaoDoPrompt: PromptResumoDoPush::VERSAO,
            contextoHash: hash('sha256', implode('|', $chaves) . uniqid()),
            contextoResumo: ['chaves' => $chaves, 'publicacoes' => [], 'movimentacoes' => [], 'total' => count($chaves), 'novas' => count($chaves), 'processos' => []],
        );
        $this->em()->persist($analise);
        $this->em()->flush();

        return $analise;
    }

    /** @param list<array{tipo: string, texto: string}> $pontos */
    private function concluirAnalise(
        AnaliseDeInteligencia $analise,
        string $resumo = 'Resumo de teste.',
        array $pontos = [],
        ?string $quem = null,
    ): void {
        $analise->iniciarProcessamento();
        $analise->concluir(
            resumo: $resumo,
            pontos: $pontos,
            quemAge: $quem,
            textoBruto: (string) json_encode(['resumo' => $resumo, 'pontos' => $pontos, 'quem' => $quem]),
            provedor: ProvedorFalso::NOME,
            modelo: ProvedorFalso::MODELO,
            tokensEntrada: 100,
            tokensSaida: 30,
            duracaoMs: 12,
        );
        $this->em()->flush();
    }

    private function provedorFalso(): ProvedorFalso
    {
        return static::getContainer()->get(ProvedorFalso::class);
    }

    private function transporteAsync(): InMemoryTransport
    {
        return static::getContainer()->get('messenger.transport.async');
    }

    /**
     * Conta por SQL cru, fora do alcance do TenantFilter e do ORM — para as perguntas "isto ainda
     * existe no banco?" que a exclusão suave precisa responder.
     *
     * @param array<string, mixed> $parametros
     */
    private function contarNoBanco(string $sql, array $parametros = []): int
    {
        return (int) $this->em()->getConnection()->fetchOne($sql, $parametros);
    }
}
